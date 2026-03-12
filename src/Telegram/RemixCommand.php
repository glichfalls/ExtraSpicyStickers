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

class RemixCommand extends AbstractCommand implements PublicCommandInterface
{
    private const DEFAULT_EMOJI = "\u{1F3A8}";

    public function __construct(
        private readonly OpenAiImageService $openAiImageService,
        private readonly StickerService $stickerService,
        private readonly UserRepository $userRepository,
        private readonly StickerRepository $stickerRepository,
        private readonly BotApi $botApi,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getName(): string
    {
        return '/remix';
    }

    public function getDescription(): string
    {
        return 'Turn a photo into a sticker';
    }

    public function isApplicable(Update $update): bool
    {
        $message = $update->getMessage();
        if ($message === null) {
            return false;
        }

        // Photo with /remix caption
        $caption = $message->getCaption() ?? '';
        if ($message->getPhoto() && preg_match('/^\/remix(@\w+)?(\s|$)/', $caption)) {
            return true;
        }

        // /remix as reply to a photo
        $text = $message->getText() ?? '';
        if (preg_match('/^\/remix(@\w+)?(\s|$)/', $text)) {
            return true;
        }

        return false;
    }

    public function execute(BotApi $api, Update $update): void
    {
        $message = $update->getMessage();
        $chatId = $message->getChat()->getId();
        $messageId = $message->getMessageId();
        $from = $message->getFrom();

        // Determine photo and prompt source
        $photos = $message->getPhoto();
        $promptText = '';

        if ($photos) {
            // Photo sent with /remix caption
            $promptText = trim(preg_replace('/^\/remix(@\w+)?\s*/', '', $message->getCaption() ?? ''));
        } else {
            // /remix as text — check if replying to a photo
            $promptText = trim(preg_replace('/^\/remix(@\w+)?\s*/', '', $message->getText() ?? ''));
            $replyTo = $message->getReplyToMessage();

            if ($replyTo && $replyTo->getPhoto()) {
                $photos = $replyTo->getPhoto();
            }
        }

        if (!$photos) {
            $this->reply($api, $chatId, $messageId, "Send a photo with /remix as caption, or reply to a photo with /remix.\n\nExamples:\n• Send photo with caption: /remix make it cartoon\n• Reply to a photo: /remix pixel art style --pixel");
            return;
        }

        $input = ParsedInput::fromText($promptText ?: 'sticker version', self::DEFAULT_EMOJI);

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

            // Get the largest photo
            $photo = end($photos);
            $fileId = $photo->getFileId();

            $styleName = $input->style !== 'default' ? ' (' . OpenAiImageService::STYLES[$input->style]['name'] . ')' : '';
            $this->reply($api, $chatId, $messageId, "Remixing your photo into a sticker$styleName...");

            // Download photo from Telegram
            $photoData = $this->botApi->downloadFile($fileId);

            // Remix via OpenAI
            $imageData = $this->openAiImageService->remixImage($photoData, $input->description, $input->style);
            $pngData = $this->stickerService->convertToPng($imageData);
            $sticker = $this->stickerService->addSticker($pack, $pngData, $input->emoji, 'remix: ' . $input->description);

            $api->call('sendSticker', [
                'chat_id' => $chatId,
                'sticker' => $sticker->getFileId(),
                'reply_parameters' => json_encode(['message_id' => $messageId]),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to remix sticker', [
                'error' => $e->getMessage(),
                'exception' => $e,
                'chat_id' => $chatId,
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
}
