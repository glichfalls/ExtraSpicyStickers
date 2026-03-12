<?php

namespace App\Telegram;

use App\Repository\UserRepository;
use App\Service\OpenAiImageService;
use App\Service\StickerService;
use BoShurik\TelegramBotBundle\Telegram\Command\AbstractCommand;
use Psr\Log\LoggerInterface;
use TelegramBot\Api\BotApi;
use TelegramBot\Api\Types\Update;

use function Emoji\detect_emoji;
use function Emoji\remove_emoji;

class StickerCommand extends AbstractCommand
{
    private const DEFAULT_EMOJI = "\u{1F3A8}";

    public function __construct(
        private readonly OpenAiImageService $openAiImageService,
        private readonly StickerService $stickerService,
        private readonly UserRepository $userRepository,
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

        return str_starts_with($text, '/sticker ') || $text === '/sticker';
    }

    public function execute(BotApi $api, Update $update): void
    {
        $message = $update->getMessage();
        $chatId = $message->getChat()->getId();
        $messageId = $message->getMessageId();
        $from = $message->getFrom();
        $text = trim(preg_replace('/^\/sticker\s*/', '', $message->getText()));

        $emoji = $this->extractEmoji($text);
        $description = trim(remove_emoji($text));

        if (empty($description)) {
            $this->reply($api, $chatId, $messageId, 'Please provide a description. Example: 🐱 happy orange cat');
            return;
        }

        try {
            $user = $this->userRepository->findOrCreateByTelegramData(
                $from->getId(),
                $from->getFirstName() ?? 'User',
                $from->getUsername()
            );

            $pack = $this->stickerService->ensurePack($user);

            $this->reply($api, $chatId, $messageId, 'Generating your sticker...');

            $imageData = $this->openAiImageService->generateImage($description);
            $pngData = $this->stickerService->convertToPng($imageData);
            $this->stickerService->addSticker($pack, $pngData, $emoji);

            $fileId = $this->stickerService->getLastStickerFileId($pack);
            $api->call('sendSticker', [
                'chat_id' => $chatId,
                'sticker' => $fileId,
                'reply_parameters' => json_encode(['message_id' => $messageId]),
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
            'reply_parameters' => json_encode(['message_id' => $messageId]),
        ]);
    }

    private function extractEmoji(string $text): string
    {
        $emojis = detect_emoji($text);

        if (empty($emojis)) {
            return self::DEFAULT_EMOJI;
        }

        // Strip variation selectors (U+FE0F) — Telegram rejects them in sticker emoji_list
        return preg_replace('/\x{FE0F}/u', '', $emojis[0]['emoji']);
    }
}