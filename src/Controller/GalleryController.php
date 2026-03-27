<?php

namespace App\Controller;

use App\Repository\StickerPackRepository;
use App\Repository\StickerRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class GalleryController extends AbstractController
{
    #[Route('/gallery', name: 'gallery')]
    public function index(StickerPackRepository $packRepository): Response
    {
        $packs = $packRepository->findAll();

        return $this->render('gallery/index.html.twig', [
            'packs' => $packs,
        ]);
    }

    #[Route('/gallery/{id}', name: 'gallery_pack')]
    public function pack(int $id, StickerPackRepository $packRepository, StickerRepository $stickerRepository): Response
    {
        $pack = $packRepository->find($id);

        if (!$pack) {
            throw $this->createNotFoundException();
        }

        $stickers = $stickerRepository->findByPack($id);

        return $this->render('gallery/pack.html.twig', [
            'pack' => $pack,
            'stickers' => $stickers,
        ]);
    }
}
