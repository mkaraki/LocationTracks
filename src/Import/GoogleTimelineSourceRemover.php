<?php

namespace App\Import;

use App\Entity\LocationPoint;
use App\Entity\LocationSource;
use App\Entity\RawRecord;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;

final class GoogleTimelineSourceRemover
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LockFactory $lockFactory,
    ) {
    }

    public function remove(LocationSource $source): bool
    {
        if (LocationSource::TYPE_GOOGLE_TIMELINE !== $source->getSourceType()) {
            throw new \InvalidArgumentException('Expected a Google Timeline source.');
        }

        $lock = $this->lockFactory->createLock(GoogleTimelineImporter::OWNER_LOCK_PREFIX.$source->getUser()->getId());
        if (!$lock->acquire()) {
            return false;
        }

        $sourceId = $source->getId();
        try {
            $this->em->getConnection()->transactional(function () use ($sourceId): void {
                // Keep points from other sources even if they superseded a removed point.
                $this->em->createQuery(<<<'DQL'
                    UPDATE App\Entity\LocationPoint point SET point.supersedes = NULL
                    WHERE IDENTITY(point.supersedes) IN (
                        SELECT removed.id FROM App\Entity\LocationPoint removed WHERE removed.source = :source
                    )
                    DQL)
                    ->setParameter('source', $sourceId)
                    ->execute();

                // Bulk deletes keep large imports out of the ORM's unit of work.
                foreach ([LocationPoint::class, RawRecord::class] as $entity) {
                    $this->em->createQueryBuilder()
                        ->delete($entity, 'item')
                        ->where('item.source = :source')
                        ->setParameter('source', $sourceId)
                        ->getQuery()
                        ->execute();
                }

                $this->em->createQueryBuilder()
                    ->delete(LocationSource::class, 'source')
                    ->where('source.id = :id')
                    ->setParameter('id', $sourceId)
                    ->getQuery()
                    ->execute();
            });
            $this->em->detach($source);

            return true;
        } finally {
            $lock->release();
        }
    }
}
