<?php

namespace App\Telegram;

use App\Entity\User;
use App\Repository\StickerPackRepository;
use App\Service\BotGuard;
use App\Service\StickerService;
use App\Service\TelegramMessenger;
use BoShurik\TelegramBotBundle\Telegram\Command\AbstractCommand;
use BoShurik\TelegramBotBundle\Telegram\Command\PublicCommandInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use TelegramBot\Api\BotApi;
use TelegramBot\Api\Types\Message;
use TelegramBot\Api\Types\Update;

class PackCommand extends AbstractCommand implements PublicCommandInterface
{
    public function __construct(
        private readonly StickerPackRepository $stickerPackRepository,
        private readonly StickerService $stickerService,
        private readonly EntityManagerInterface $entityManager,
        private readonly BotGuard $guard,
        private readonly TelegramMessenger $messenger,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getName(): string
    {
        return '/pack';
    }

    public function getDescription(): string
    {
        return 'Manage your sticker packs';
    }

    public function isApplicable(Update $update): bool
    {
        $text = $update->getMessage()?->getText();

        if (null === $text) {
            return false;
        }

        return (bool) preg_match('/^\/pack(@\w+)?(\s|$)/', $text);
    }

    public function execute(BotApi $api, Update $update): void
    {
        $message = $update->getMessage();
        $text = trim(preg_replace('/^\/pack(@\w+)?\s*/', '', $message->getText()));

        try {
            $user = $this->guard->resolveUser($message);
            if (null === $user) {
                return;
            }

            if ('' === $text || 'info' === $text) {
                $this->handleInfo($message, $user);
            } elseif ('list' === $text) {
                $this->handleList($message, $user);
            } elseif (preg_match('/^new\s+(.+)$/i', $text, $matches)) {
                $this->handleNew($message, $user, trim($matches[1]));
            } elseif (preg_match('/^rename\s+(.+)$/i', $text, $matches)) {
                $this->handleRename($message, $user, trim($matches[1]));
            } elseif (preg_match('/^switch\s+(\d+)$/i', $text, $matches)) {
                $this->handleSwitch($message, $user, (int) $matches[1]);
            } else {
                $this->messenger->reply($message,
                    "Usage:\n".
                    "/pack — show current pack info\n".
                    "/pack list — list all your packs\n".
                    "/pack new <title> — create a new pack\n".
                    "/pack rename <title> — rename current pack\n".
                    '/pack switch <number> — switch to a pack by number'
                );
            }
        } catch (\Throwable $e) {
            $this->logger->error('Pack command failed', [
                'error' => $e->getMessage(),
                'exception' => $e,
                'chat_id' => $message->getChat()->getId(),
            ]);

            $this->messenger->reply($message, "Something went wrong: {$e->getMessage()}");
        }
    }

    private function handleInfo(Message $message, User $user): void
    {
        $packs = $this->stickerPackRepository->findAllByUser($user);

        if (empty($packs)) {
            $this->messenger->reply($message, "You don't have any sticker packs yet. Use /sticker to create your first one, or /pack new <title> to create one with a custom name.");

            return;
        }

        $activePack = $user->getActiveStickerPack() ?? $packs[0];
        $packCount = count($packs);

        $text = "📦 Active pack: {$activePack->getTitle()}\n";
        $text .= "🔗 t.me/addstickers/{$activePack->getName()}\n";
        $text .= "📁 You have {$packCount} pack(s) total\n\n";
        $text .= 'Use /pack list to see all packs.';

        $this->messenger->reply($message, $text);
    }

    private function handleList(Message $message, User $user): void
    {
        $packs = $this->stickerPackRepository->findAllByUser($user);

        if (empty($packs)) {
            $this->messenger->reply($message, "You don't have any sticker packs yet. Use /pack new <title> to create one.");

            return;
        }

        $activePack = $user->getActiveStickerPack();
        $lines = ["Your sticker packs:\n"];

        foreach ($packs as $i => $pack) {
            $num = $i + 1;
            $active = (null !== $activePack && $pack->getId() === $activePack->getId()) ? ' ✅' : '';
            $lines[] = "{$num}. {$pack->getTitle()}{$active}";
        }

        $lines[] = "\nUse /pack switch <number> to change active pack.";

        $this->messenger->reply($message, implode("\n", $lines));
    }

    private function handleNew(Message $message, User $user, string $title): void
    {
        if (mb_strlen($title) > 64) {
            $this->messenger->reply($message, 'Pack title must be 64 characters or less.');

            return;
        }

        try {
            $pack = $this->stickerService->createPack($user, $title);
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'PEER_ID_INVALID')) {
                $botUsername = $_ENV['TELEGRAM_BOT_USERNAME'] ?? 'the bot';
                $this->messenger->reply($message, "Please start a private chat with @$botUsername first, then try again.");

                return;
            }
            throw $e;
        }

        $this->messenger->reply($message,
            "✅ Created new pack: {$title}\n".
            "🔗 t.me/addstickers/{$pack->getName()}\n\n".
            'This is now your active pack. New stickers will be added here.'
        );
    }

    private function handleRename(Message $message, User $user, string $newTitle): void
    {
        if (mb_strlen($newTitle) > 64) {
            $this->messenger->reply($message, 'Pack title must be 64 characters or less.');

            return;
        }

        $activePack = $user->getActiveStickerPack();
        if (null === $activePack) {
            $packs = $this->stickerPackRepository->findAllByUser($user);
            if (empty($packs)) {
                $this->messenger->reply($message, "You don't have any sticker packs yet.");

                return;
            }
            $activePack = $packs[0];
        }

        $this->stickerService->renamePack($activePack, $newTitle);

        $this->messenger->reply($message, "✅ Renamed pack to: {$newTitle}");
    }

    private function handleSwitch(Message $message, User $user, int $number): void
    {
        $packs = $this->stickerPackRepository->findAllByUser($user);

        if ($number < 1 || $number > count($packs)) {
            $this->messenger->reply($message, 'Invalid pack number. Use /pack list to see your packs.');

            return;
        }

        $pack = $packs[$number - 1];
        $user->setActiveStickerPack($pack);
        $this->entityManager->flush();

        $this->messenger->reply($message,
            "✅ Switched to: {$pack->getTitle()}\n".
            "🔗 t.me/addstickers/{$pack->getName()}\n\n".
            'New stickers will be added to this pack.'
        );
    }
}
