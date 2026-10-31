<?php

namespace App\Repository;

use App\Entity\LocationPoint;
use App\Entity\User;
use DateTime;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LocationPoint>
 */
class LocationPointRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LocationPoint::class);
    }

    /**
     * @return LocationPoint[] Returns an array of LocationPoint objects
     */
    public function findByUserAndTimeRange(
        User $user,
        DateTime $startTime,
        DateTime $endTime,
    ) {
        return $this->createQueryBuilder('points')
            ->innerJoin('points.source', 'sources')
            ->where('sources.user = :user')
            ->setParameter('user', $user->getId())
            ->andWhere('points.recorded_at >= :startTime')
            ->andWhere('points.recorded_at <= :endTime')
            ->setParameter('startTime', $startTime)
            ->setParameter('endTime', $endTime)
            ->orderBy('points.recorded_at', 'ASC')
            ->getQuery()
            ->getResult();
    }

//    /**
//     * @return LocationPoint[] Returns an array of LocationPoint objects
//     */
//    public function findByExampleField($value): array
//    {
//        return $this->createQueryBuilder('l')
//            ->andWhere('l.exampleField = :val')
//            ->setParameter('val', $value)
//            ->orderBy('l.id', 'ASC')
//            ->setMaxResults(10)
//            ->getQuery()
//            ->getResult()
//        ;
//    }

//    public function findOneBySomeField($value): ?LocationPoint
//    {
//        return $this->createQueryBuilder('l')
//            ->andWhere('l.exampleField = :val')
//            ->setParameter('val', $value)
//            ->getQuery()
//            ->getOneOrNullResult()
//        ;
//    }
}
