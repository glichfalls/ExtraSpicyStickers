<?php

namespace App\Telegram;

use App\Service\BotGuard;
use App\Service\OpenAiImageService;
use App\Service\StickerService;
use App\Service\TelegramMessenger;
use App\Telegram\Dto\ParsedInput;
use BoShurik\TelegramBotBundle\Telegram\Command\AbstractCommand;
use BoShurik\TelegramBotBundle\Telegram\Command\PublicCommandInterface;
use Psr\Log\LoggerInterface;
use TelegramBot\Api\BotApi;
use TelegramBot\Api\Types\Update;

class RemixCommand extends AbstractCommand implements PublicCommandInterface
{
    private const string DEFAULT_EMOJI = "\u{1F3A8}";

    public function __construct(
        private readonly OpenAiImageService $openAiImageService,
        private readonly StickerService $stickerService,
        private readonly BotGuard $guard,
        private readonly TelegramMessenger $messenger,
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
        if (null === $message) {
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
            $this->messenger->reply($message, "Send a photo with /remix as caption, or reply to a photo with /remix.\n\nExamples:\n• Send photo with caption: /remix make it cartoon\n• Reply to a photo: /remix pixel art style --pixel");

            return;
        }

        $input = ParsedInput::fromText($promptText ?: 'sticker version', self::DEFAULT_EMOJI);

        try {
            $user = $this->guard->resolveUser($message);
            if (null === $user) {
                return;
            }

            if (!$this->guard->checkDailyLimit($message, $user)) {
                return;
            }

            $pack = $this->guard->resolvePack($message, $user);
            if (null === $pack) {
                return;
            }

            // Get the largest photo
            $photo = end($photos);
            $fileId = $photo->getFileId();

            $styleName = 'default' !== $input->style ? ' ('.OpenAiImageService::STYLES[$input->style]['name'].')' : '';
            $this->messenger->reply($message, "Remixing your photo into a sticker$styleName...");

            $photoData = $this->botApi->downloadFile($fileId);

            $imageData = $this->openAiImageService->remixImage($photoData, $input->description, $input->style);
            $pngData = $this->stickerService->convertToPng($imageData);
            $sticker = $this->stickerService->addSticker($pack, $pngData, $input->emoji, 'remix: '.$input->description);

            $this->messenger->replySticker($message, $sticker->getFileId());
        } catch (\Throwable $e) {
            $this->logger->error('Failed to remix sticker', [
                'error' => $e->getMessage(),
                'exception' => $e,
                'chat_id' => $message->getChat()->getId(),
            ]);

            $this->messenger->reply($message, "Something went wrong: {$e->getMessage()}");
        }
    }
}
