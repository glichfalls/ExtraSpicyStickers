<?php

namespace App\Telegram;

use App\Repository\StickerPackRepository;
use App\Repository\UserRepository;
use App\Service\StickerService;
use BoShurik\TelegramBotBundle\Telegram\Command\AbstractCommand;
use BoShurik\TelegramBotBundle\Telegram\Command\PublicCommandInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use TelegramBot\Api\BotApi;
use TelegramBot\Api\Types\Update;

class PackCommand extends AbstractCommand implements PublicCommandInterface
{
    public function __construct(
        private readonly StickerService $stickerService,
        private readonly StickerPackRepository $stickerPackRepository,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
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

        if ($text === null) {
            return false;
        }

        return (bool) preg_match('/^\/pack(@\w+)?(\s|$)/', $text);
    }

    public function execute(BotApi $api, Update $update): void
    {
        $message = $update->getMessage();
        $chatId = $message->getChat()->getId();
        $messageId = $message->getMessageId();
        $from = $message->getFrom();
        $text = trim(preg_replace('/^\/pack(@\w+)?\s*/', '', $message->getText()));

        try {
            $user = $this->userRepository->findOrCreateByTelegramData(
                $from->getId(),
                $from->getFirstName() ?? 'User',
                $from->getUsername()
            );

            if ($user->isBanned()) {
                $this->reply($api, $chatId, $messageId, 'Your account has been suspended.');
                return;
            }

            // Parse subcommand
            if ($text === '' || $text === 'info') {
                $this->handleInfo($api, $chatId, $messageId, $user);
            } elseif ($text === 'list') {
                $this->handleList($api, $chatId, $messageId, $user);
            } elseif (preg_match('/^new\s+(.+)$/i', $text, $matches)) {
                $this->handleNew($api, $chatId, $messageId, $user, trim($matches[1]));
            } elseif (preg_match('/^rename\s+(.+)$/i', $text, $matches)) {
                $this->handleRename($api, $chatId, $messageId, $user, trim($matches[1]));
            } elseif (preg_match('/^switch\s+(\d+)$/i', $text, $matches)) {
                $this->handleSwitch($api, $chatId, $messageId, $user, (int) $matches[1]);
            } else {
                $this->reply($api, $chatId, $messageId,
                    "Usage:\n" .
                    "/pack — show current pack info\n" .
                    "/pack list — list all your packs\n" .
                    "/pack new <title> — create a new pack\n" .
                    "/pack rename <title> — rename current pack\n" .
                    "/pack switch <number> — switch to a pack by number"
                );
            }
        } catch (\Throwable $e) {
            $this->logger->error('Pack command failed', [
                'error' => $e->getMessage(),
                'exception' => $e,
                'chat_id' => $chatId,
            ]);

            $this->reply($api, $chatId, $messageId, "Something went wrong: {$e->getMessage()}");
        }
    }

    private function handleInfo(BotApi $api, int $chatId, int $messageId, \App\Entity\User $user): void
    {
        $packs = $this->stickerPackRepository->findAllByUser($user);

        if (empty($packs)) {
            $this->reply($api, $chatId, $messageId, "You don't have any sticker packs yet. Use /sticker to create your first one, or /pack new <title> to create one with a custom name.");
            return;
        }

        $activePack = $user->getActiveStickerPack();
        if ($activePack === null) {
            $activePack = $packs[0];
        }

        $stickerCount = count($this->stickerPackRepository->findAllByUser($user));
        $text = "📦 Active pack: {$activePack->getTitle()}\n";
        $text .= "🔗 t.me/addstickers/{$activePack->getName()}\n";
        $text .= "📁 You have {$stickerCount} pack(s) total\n\n";
        $text .= "Use /pack list to see all packs.";

        $this->reply($api, $chatId, $messageId, $text);
    }

    private function handleList(BotApi $api, int $chatId, int $messageId, \App\Entity\User $user): void
    {
        $packs = $this->stickerPackRepository->findAllByUser($user);

        if (empty($packs)) {
            $this->reply($api, $chatId, $messageId, "You don't have any sticker packs yet. Use /pack new <title> to create one.");
            return;
        }

        $activePack = $user->getActiveStickerPack();
        $lines = ["Your sticker packs:\n"];

        foreach ($packs as $i => $pack) {
            $num = $i + 1;
            $active = ($activePack !== null && $pack->getId() === $activePack->getId()) ? ' ✅' : '';
            $lines[] = "{$num}. {$pack->getTitle()}{$active}";
        }

        $lines[] = "\nUse /pack switch <number> to change active pack.";

        $this->reply($api, $chatId, $messageId, implode("\n", $lines));
    }

    private function handleNew(BotApi $api, int $chatId, int $messageId, \App\Entity\User $user, string $title): void
    {
        if (mb_strlen($title) > 64) {
            $this->reply($api, $chatId, $messageId, "Pack title must be 64 characters or less.");
            return;
        }

        if (mb_strlen($title) < 1) {
            $this->reply($api, $chatId, $messageId, "Please provide a title for the new pack.\n\nExample: /pack new My Cool Stickers");
            return;
        }

        try {
            $pack = $this->stickerService->createPack($user, $title);
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'PEER_ID_INVALID')) {
                $botUsername = $_ENV['TELEGRAM_BOT_USERNAME'] ?? 'the bot';
                $this->reply($api, $chatId, $messageId, "Please start a private chat with @$botUsername first, then try again.");
                return;
            }
            throw $e;
        }

        $this->reply($api, $chatId, $messageId,
            "✅ Created new pack: {$title}\n" .
            "🔗 t.me/addstickers/{$pack->getName()}\n\n" .
            "This is now your active pack. New stickers will be added here."
        );
    }

    private function handleRename(BotApi $api, int $chatId, int $messageId, \App\Entity\User $user, string $newTitle): void
    {
        if (mb_strlen($newTitle) > 64) {
            $this->reply($api, $chatId, $messageId, "Pack title must be 64 characters or less.");
            return;
        }

        $activePack = $user->getActiveStickerPack();
        if ($activePack === null) {
            $packs = $this->stickerPackRepository->findAllByUser($user);
            if (empty($packs)) {
                $this->reply($api, $chatId, $messageId, "You don't have any sticker packs yet.");
                return;
            }
            $activePack = $packs[0];
        }

        $this->stickerService->renamePack($activePack, $newTitle);

        $this->reply($api, $chatId, $messageId, "✅ Renamed pack to: {$newTitle}");
    }

    private function handleSwitch(BotApi $api, int $chatId, int $messageId, \App\Entity\User $user, int $number): void
    {
        $packs = $this->stickerPackRepository->findAllByUser($user);

        if ($number < 1 || $number > count($packs)) {
            $this->reply($api, $chatId, $messageId, "Invalid pack number. Use /pack list to see your packs.");
            return;
        }

        $pack = $packs[$number - 1];
        $user->setActiveStickerPack($pack);
        $this->entityManager->flush();

        $this->reply($api, $chatId, $messageId,
            "✅ Switched to: {$pack->getTitle()}\n" .
            "🔗 t.me/addstickers/{$pack->getName()}\n\n" .
            "New stickers will be added to this pack."
        );
    }

    private function reply(BotApi $api, int $chatId, int $messageId, string $text): void
    {
        $api->call('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'reply_parameters' => json_encode(['message_id' => $messageId, 'allow_sending_without_reply' => true]),
        ]);
    }
}