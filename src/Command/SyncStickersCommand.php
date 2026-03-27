<?php

namespace App\Command;

use App\Entity\Sticker;
use App\Repository\StickerPackRepository;
use App\Repository\StickerRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TelegramBot\Api\BotApi;

#[AsCommand(
    name: 'app:sync-stickers',
    description: 'Sync sticker data from Telegram and download missing images',
)]
class SyncStickersCommand extends Command
{
    public function __construct(
        private readonly BotApi $botApi,
        private readonly StickerPackRepository $stickerPackRepository,
        private readonly StickerRepository $stickerRepository,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $packs = $this->stickerPackRepository->findAll();

        if (empty($packs)) {
            $io->warning('No sticker packs found in the database.');

            return Command::SUCCESS;
        }

        $stickersDir = $this->projectDir.'/public/stickers';
        if (!is_dir($stickersDir)) {
            mkdir($stickersDir, 0775, true);
        }

        $totalAdded = 0;
        $totalDownloaded = 0;

        foreach ($packs as $pack) {
            $io->section(sprintf('Pack: %s (user: %s)', $pack->getName(), $pack->getUser()->getFirstName()));

            try {
                $stickerSet = $this->botApi->call('getStickerSet', ['name' => $pack->getName()]);
            } catch (\Exception $e) {
                $io->error(sprintf('Failed to fetch set from Telegram: %s', $e->getMessage()));
                continue;
            }

            $telegramStickers = $stickerSet['stickers'] ?? [];
            $io->text(sprintf('Found %d stickers on Telegram', count($telegramStickers)));

            // Index existing DB stickers by file_id
            $dbStickers = $this->stickerRepository->findByPack($pack->getId());
            $existingFileIds = [];
            foreach ($dbStickers as $dbSticker) {
                $existingFileIds[$dbSticker->getFileId()] = $dbSticker;
            }

            // Add missing stickers to DB
            foreach ($telegramStickers as $telegramSticker) {
                $fileId = $telegramSticker['file_id'];
                $emoji = $telegramSticker['emoji'] ?? '';

                if (!isset($existingFileIds[$fileId])) {
                    $sticker = new Sticker();
                    $sticker->setPack($pack);
                    $sticker->setFileId($fileId);
                    $sticker->setEmoji($emoji);
                    $sticker->setPrompt('(synced from Telegram)');
                    $this->stickerRepository->save($sticker);
                    $existingFileIds[$fileId] = $sticker;
                    ++$totalAdded;
                    $io->text(sprintf('  + Added sticker %s %s', $emoji, $fileId));
                }
            }

            // Download images for stickers without imagePath
            foreach ($existingFileIds as $sticker) {
                if (null !== $sticker->getImagePath()) {
                    continue;
                }

                try {
                    $fileData = $this->botApi->downloadFile($sticker->getFileId());
                    $filename = uniqid('sticker_').'.webp';
                    file_put_contents($stickersDir.'/'.$filename, $fileData);

                    // Convert to PNG
                    $pngFilename = uniqid('sticker_').'.png';
                    $converted = @imagecreatefromwebp($stickersDir.'/'.$filename);
                    if (false !== $converted) {
                        imagesavealpha($converted, true);
                        imagepng($converted, $stickersDir.'/'.$pngFilename);
                        imagedestroy($converted);
                        unlink($stickersDir.'/'.$filename);
                        $sticker->setImagePath('stickers/'.$pngFilename);
                    } else {
                        // Keep as webp if conversion fails
                        $sticker->setImagePath('stickers/'.$filename);
                    }

                    $this->stickerRepository->save($sticker);
                    ++$totalDownloaded;
                    $io->text(sprintf('  ↓ Downloaded image for %s %s', $sticker->getEmoji(), $sticker->getFileId()));
                } catch (\Exception $e) {
                    $io->warning(sprintf('  ! Failed to download %s: %s', $sticker->getFileId(), $e->getMessage()));
                }
            }
        }

        $io->success(sprintf('Done. Added %d stickers to DB, downloaded %d images.', $totalAdded, $totalDownloaded));

        return Command::SUCCESS;
    }
}
