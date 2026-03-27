<?php

namespace App\Service;

use App\Entity\Sticker;
use App\Entity\StickerPack;
use App\Entity\User;
use App\Repository\StickerPackRepository;
use App\Repository\StickerRepository;
use Doctrine\ORM\EntityManagerInterface;
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
        private readonly EntityManagerInterface $entityManager,
        private readonly string $botUsername,
        private readonly string $projectDir,
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
        $activePack = $user->getActiveStickerPack();
        if (null !== $activePack) {
            return $activePack;
        }

        // Check if user has any existing packs (migration from old schema)
        $packs = $this->stickerPackRepository->findAllByUser($user);
        if (!empty($packs)) {
            $pack = $packs[0];
            $user->setActiveStickerPack($pack);
            $this->entityManager->flush();

            return $pack;
        }

        // Create first pack
        return $this->createPack($user, sprintf("%s's AI Stickers", $user->getFirstName()));
    }

    public function createPack(User $user, string $title): StickerPack
    {
        $packCount = $this->stickerPackRepository->countByUser($user);
        $packName = 0 === $packCount
            ? sprintf('stickers_%d_by_%s', $user->getTelegramId(), $this->botUsername)
            : sprintf('stickers_%d_%d_by_%s', $user->getTelegramId(), $packCount + 1, $this->botUsername);

        // Check if the sticker set already exists on Telegram
        $existsOnTelegram = false;
        try {
            $this->botApi->call('getStickerSet', ['name' => $packName]);
            $existsOnTelegram = true;
        } catch (\Exception) {
            // Set doesn't exist, we'll create it
        }

        if (!$existsOnTelegram) {
            $this->callWithTempFile($this->createPlaceholderPng(), function (string $tempFile) use ($user, $packName, $title) {
                $this->botApi->call('createNewStickerSet', [
                    'user_id' => $user->getTelegramId(),
                    'name' => $packName,
                    'title' => $title,
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
        $pack->setTitle($title);
        $this->stickerPackRepository->save($pack);

        $user->setActiveStickerPack($pack);
        $this->entityManager->flush();

        return $pack;
    }

    public function renamePack(StickerPack $pack, string $newTitle): void
    {
        $this->botApi->call('setStickerSetTitle', [
            'name' => $pack->getName(),
            'title' => $newTitle,
        ]);

        $pack->setTitle($newTitle);
        $this->entityManager->flush();
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

        $stickersDir = $this->projectDir.'/public/stickers';
        if (!is_dir($stickersDir)) {
            mkdir($stickersDir, 0775, true);
        }
        $filename = uniqid('sticker_').'.png';
        file_put_contents($stickersDir.'/'.$filename, $pngData);
        $sticker->setImagePath('stickers/'.$filename);

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
