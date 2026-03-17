<?php

namespace App\Telegram;

use App\Repository\StickerRepository;
use App\Repository\UserRepository;
use App\Service\OpenAiImageService;
use App\Service\StickerService;
use App\Telegram\Dto\ParsedInput;
use BoShurik\TelegramBotBundle\Telegram\Command\AbstractCommand;
use BoShurik\TelegramBotBundle\Telegram\Command\PublicCommandInterface;
use Psr\Log\LoggerInterface;
use TelegramBot\Api\BotApi;
use TelegramBot\Api\Types\Update;

class StickerCommand extends AbstractCommand implements PublicCommandInterface
{
    private const DEFAULT_EMOJI = "\u{1F3A8}";

    public function __construct(
        private readonly OpenAiImageService $openAiImageService,
        private readonly StickerService $stickerService,
        private readonly UserRepository $userRepository,
        private readonly StickerRepository $stickerRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getName(): string
    {
        return '/sticker';
    }

    public function getDescription(): string
    {
        return 'Generate a sticker';
    }

    public function isApplicable(Update $update): bool
    {
        $text = $update->getMessage()?->getText();

        if ($text === null) {
            return false;
        }

        return (bool) preg_match('/^\/sticker(@\w+)?(\s|$)/', $text);
    }

    public function execute(BotApi $api, Update $update): void
    {
        $message = $update->getMessage();
        $chatId = $message->getChat()->getId();
        $messageId = $message->getMessageId();
        $from = $message->getFrom();
        $text = trim(preg_replace('/^\/sticker(@\w+)?\s*/', '', $message->getText()));

        if ($text === 'styles' || $text === '--help') {
            $this->reply($api, $chatId, $messageId, "Available styles:\n\n" . ParsedInput::styleList() . "\n\nUsage: /sticker 🐱 happy cat --pixel");
            return;
        }

        $input = ParsedInput::fromText($text, self::DEFAULT_EMOJI);

        if (empty($input->description)) {
            $this->reply($api, $chatId, $messageId, "Please provide a description.\n\nExample: /sticker 🐱 happy orange cat\nWith style: /sticker 🐱 happy cat --pixel\n\nType /sticker styles to see all styles.");
            return;
        }

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

            $recentCount = $this->stickerRepository->countRecentByUser(
                $user->getId(),
                new \DateTime('-24 hours'),
            );

            if ($recentCount >= $user->getDailyLimit()) {
                $this->reply($api, $chatId, $messageId, "You've reached your daily limit of {$user->getDailyLimit()} stickers. Try again tomorrow!");
                return;
            }

            try {
                $pack = $this->stickerService->ensurePack($user);
            } catch (\Exception $e) {
                if (str_contains($e->getMessage(), 'PEER_ID_INVALID')) {
                    $botUsername = $_ENV['TELEGRAM_BOT_USERNAME'] ?? 'the bot';
                    $this->reply($api, $chatId, $messageId, "Please start a private chat with @$botUsername first, then try again.");
                    return;
                }
                throw $e;
            }

            $styleName = $input->style !== 'default' ? ' (' . OpenAiImageService::STYLES[$input->style]['name'] . ')' : '';
            $this->reply($api, $chatId, $messageId, "Generating your sticker$styleName...");

            $imageData = $this->openAiImageService->generateImage($input->description, $input->style);
            $pngData = $this->stickerService->convertToPng($imageData);
            $sticker = $this->stickerService->addSticker($pack, $pngData, $input->emoji, $input->description);

            $api->call('sendSticker', [
                'chat_id' => $chatId,
                'sticker' => $sticker->getFileId(),
                'reply_parameters' => json_encode(['message_id' => $messageId, 'allow_sending_without_reply' => true]),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to generate sticker', [
                'error' => $e->getMessage(),
                'exception' => $e,
                'chat_id' => $chatId,
                'text' => $text,
            ]);

            $this->reply($api, $chatId, $messageId, "Something went wrong: {$e->getMessage()}");
        }
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