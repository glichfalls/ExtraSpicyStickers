<?php

namespace App\Service;

use App\Entity\Sticker;
use App\Entity\StickerPack;
use App\Entity\User;
use App\Repository\StickerPackRepository;
use App\Repository\StickerRepository;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use TelegramBot\Api\BotApi;

class StickerService
{
    private ImageManager $imageManager;

    public function __construct(
        private readonly BotApi $botApi,
        private readonly StickerPackRepository $stickerPackRepository,
        private readonly StickerRepository $stickerRepository,
        private readonly string $botUsername,
    ) {
        $this->imageManager = new ImageManager(new Driver());
    }

    public function convertToPng(string $imageData): string
    {
        $image = $this->imageManager->read($imageData);

        if ($image->width() > $image->height()) {
            $image->scale(width: 512);
        } else {
            $image->scale(height: 512);
        }

        return $image->toPng()->toString();
    }

    public function ensurePack(User $user): StickerPack
    {
        $existing = $this->stickerPackRepository->findByUser($user);
        if ($existing !== null) {
            return $existing;
        }

        $packName = sprintf('stickers_%d_by_%s', $user->getTelegramId(), $this->botUsername);
        $packTitle = sprintf("%s's AI Stickers", $user->getFirstName());

        // Check if the sticker set already exists on Telegram
        $existsOnTelegram = false;
        try {
            $this->botApi->call('getStickerSet', ['name' => $packName]);
            $existsOnTelegram = true;
        } catch (\Exception) {
            // Set doesn't exist, we'll create it
        }

        if (!$existsOnTelegram) {
            $this->callWithTempFile($this->createPlaceholderPng(), function (string $tempFile) use ($user, $packName, $packTitle) {
                $this->botApi->call('createNewStickerSet', [
                    'user_id' => $user->getTelegramId(),
                    'name' => $packName,
                    'title' => $packTitle,
                    'stickers' => json_encode([[
                        'sticker' => 'attach://sticker_file',
                        'format' => 'static',
                        'emoji_list' => ["\u{1F3A8}"],
                    ]]),
                    'sticker_file' => new \CURLFile($tempFile, 'image/png', 'sticker.png'),
                ]);
            });
        }

        $pack = new StickerPack();
        $pack->setUser($user);
        $pack->setName($packName);
        $this->stickerPackRepository->save($pack);

        return $pack;
    }

    public function addSticker(StickerPack $pack, string $pngData, string $emoji, string $prompt): Sticker
    {
        $this->callWithTempFile($pngData, function (string $tempFile) use ($pack, $emoji) {
            $this->botApi->call('addStickerToSet', [
                'user_id' => $pack->getUser()->getTelegramId(),
                'name' => $pack->getName(),
                'sticker' => json_encode([
                    'sticker' => 'attach://sticker_file',
                    'format' => 'static',
                    'emoji_list' => [$emoji],
                ]),
                'sticker_file' => new \CURLFile($tempFile, 'image/png', 'sticker.png'),
            ]);
        });

        $fileId = $this->getLastStickerFileId($pack);

        $sticker = new Sticker();
        $sticker->setPack($pack);
        $sticker->setFileId($fileId);
        $sticker->setEmoji($emoji);
        $sticker->setPrompt($prompt);
        $this->stickerRepository->save($sticker);

        return $sticker;
    }

    public function getLastStickerFileId(StickerPack $pack): string
    {
        $stickerSet = $this->botApi->call('getStickerSet', ['name' => $pack->getName()]);
        $lastSticker = end($stickerSet['stickers']);

        return $lastSticker['file_id'];
    }

    private function createPlaceholderPng(): string
    {
        $image = $this->imageManager->create(512, 512)->fill('rgba(255, 255, 255, 0)');
        return $image->toPng()->toString();
    }

    private function callWithTempFile(string $data, callable $callback): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'sticker_');
        file_put_contents($tempFile, $data);

        try {
            $callback($tempFile);
        } finally {
            @unlink($tempFile);
        }
    }
}