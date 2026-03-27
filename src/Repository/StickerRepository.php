<?php

namespace App\Repository;

use App\Entity\Sticker;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Sticker>
 */
class StickerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Sticker::class);
    }

    public function save(Sticker $sticker): void
    {
        $this->getEntityManager()->persist($sticker);
        $this->getEntityManager()->flush();
    }

    /**
     * @return Sticker[]
     */
    public function findByPack(int $packId): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.pack = :packId')
            ->setParameter('packId', $packId)
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countSince(\DateTimeInterface $since): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.createdAt >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countRecentByUser(int $userId, \DateTimeInterface $since): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->join('s.pack', 'p')
            ->join('p.user', 'u')
            ->where('u.id = :userId')
            ->andWhere('s.createdAt >= :since')
            ->setParameter('userId', $userId)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
