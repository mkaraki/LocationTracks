<?php

namespace App\Import;

use App\Entity\LocationPoint;
use App\Entity\LocationSource;
use App\Entity\RawRecord;
use App\Entity\User;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

final class GoogleTimelineImporter
{
    public const OWNER_LOCK_PREFIX = 'location-tracks.timeline-import.';

    private const BATCH_SIZE = 500;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GoogleTimelineReader $reader,
        private readonly LockFactory $lockFactory,
    ) {
    }

    /** @return array{added: int, skipped: int} */
    public function import(User $owner, string $name, string $filename): array
    {
        // Use a separate unit of work and keep imported locations out of Doctrine's query profiler.
        $defaultConnection = $this->entityManager->getConnection();
        $configuration = clone $defaultConnection->getConfiguration();
        $configuration->setMiddlewares([]);
        $connection = DriverManager::getConnection($defaultConnection->getParams(), $configuration);
        $em = new EntityManager($connection, $this->entityManager->getConfiguration(), $this->entityManager->getEventManager());

        $lock = $this->lockFactory->createLock(self::OWNER_LOCK_PREFIX.$owner->getId());
        if (!$lock->acquire()) {
            throw new InvalidTimeline('A Google Timeline import is already running for this account. Try again when it finishes.');
        }

        try {
            return $em->wrapInTransaction(fn (): array => $this->persistImport($em, $owner->getId(), $name, $filename, $lock));
        } finally {
            $connection->close();
            $lock->release();
        }
    }

    /** @return array{added: int, skipped: int} */
    private function persistImport(EntityManagerInterface $em, int $ownerId, string $name, string $filename, LockInterface $lock): array
    {
        $utc = new \DateTimeZone('UTC');
        $importedAt = new \DateTimeImmutable('now', $utc);
        $sourceId = null;
        $added = 0;
        $skipped = 0;

        foreach ($this->batches($filename, $importedAt) as $batch) {
            $lock->refresh();
            $seen = $this->existingPointKeys($em->getConnection(), $ownerId, $batch, $utc);
            $source = null;

            foreach ($batch as [$type, $data, $points]) {
                $newPoints = [];
                foreach ($points as $point) {
                    $key = $this->pointKey($point->getRecordedAt(), $point->getLatitude(), $point->getLongitude());
                    if (isset($seen[$key])) {
                        ++$skipped;
                        continue;
                    }
                    $seen[$key] = true;
                    $newPoints[] = $point;
                }
                if (!$newPoints) {
                    continue;
                }

                if (null === $source) {
                    if (null === $sourceId) {
                        $source = (new LocationSource())
                            ->setUser($em->getReference(User::class, $ownerId))
                            ->setSourceType(LocationSource::TYPE_GOOGLE_TIMELINE)
                            ->setName($name)
                            ->setCreatedAt($importedAt);
                        $em->persist($source);
                    } else {
                        $source = $em->getReference(LocationSource::class, $sourceId);
                    }
                }

                $this->persistRecord($em, $source, $type, $data, $newPoints, $importedAt);
                $added += \count($newPoints);
            }

            if (null !== $source) {
                $em->flush();
                $sourceId = $source->getId();
                $em->clear();
            }
        }

        if (0 === $added && 0 === $skipped) {
            throw new InvalidTimeline('The Timeline JSON contains no locations with coordinates and timestamps.');
        }

        $lock->refresh();

        return ['added' => $added, 'skipped' => $skipped];
    }

    /** @return iterable<list<array{string, array, list<LocationPoint>}>> */
    private function batches(string $filename, \DateTimeImmutable $importedAt): iterable
    {
        $batch = [];
        foreach ($this->reader->records($filename) as [$type, $data, $observations]) {
            $points = [];
            foreach ($observations as [$coordinates, $timestamp]) {
                $point = $this->point($coordinates, $timestamp, $importedAt);
                if (null !== $point) {
                    $points[] = $point;
                }
            }
            if (!$points) {
                continue;
            }

            $batch[] = [$type, $data, $points];
            if (self::BATCH_SIZE === \count($batch)) {
                yield $batch;
                $batch = [];
            }
        }
        if ($batch) {
            yield $batch;
        }
    }

    /**
     * @param list<array{string, array, list<LocationPoint>}> $batch
     * @return array<string, true>
     */
    private function existingPointKeys(Connection $connection, int $ownerId, array $batch, \DateTimeZone $utc): array
    {
        $format = $connection->getDatabasePlatform()->getDateTimeTzFormatString();
        $timestamps = [];
        foreach ($batch as [, , $points]) {
            foreach ($points as $point) {
                $timestamp = $point->getRecordedAt()->format($format);
                $timestamps[$timestamp] = $timestamp;
            }
        }

        $rows = $connection->createQueryBuilder()
            ->select('p.recorded_at', 'p.latitude', 'p.longitude')
            ->from('location_point', 'p')
            ->innerJoin('p', 'location_source', 's', 's.id = p.source_id')
            ->where('s.user_id = :owner', 's.source_type = :type', 'p.recorded_at IN (:timestamps)', 'p.deleted_at IS NULL')
            ->setParameter('owner', $ownerId)
            ->setParameter('type', LocationSource::TYPE_GOOGLE_TIMELINE)
            ->setParameter('timestamps', array_values($timestamps), ArrayParameterType::STRING)
            ->executeQuery()
            ->iterateAssociative();

        $keys = [];
        foreach ($rows as $row) {
            $recordedAt = new \DateTimeImmutable($row['recorded_at'], $utc);
            $keys[$this->pointKey($recordedAt, (float) $row['latitude'], (float) $row['longitude'])] = true;
        }

        return $keys;
    }

    private function pointKey(\DateTimeInterface $recordedAt, float $latitude, float $longitude): string
    {
        // Match the precision used by the persisted timestamp and treat signed zero as zero.
        return $recordedAt->getTimestamp().':'.($latitude + 0.0).':'.($longitude + 0.0);
    }

    /** @param non-empty-list<LocationPoint> $points */
    private function persistRecord(EntityManagerInterface $em, LocationSource $source, string $type, array $data, array $points, \DateTimeImmutable $importedAt): void
    {
        if (GoogleTimelineReader::RECORD_POSITION === $type) {
            $speed = $this->measurement($data['speedMetersPerSecond'] ?? null);
            $points[0]
                ->setAccuracyM($this->measurement($data['accuracyMeters'] ?? null))
                ->setAltitudeM($this->measurement($data['altitudeMeters'] ?? null))
                ->setSpeedKmh(null === $speed ? null : $speed * 3.6);
        }

        try {
            $payload = json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        } catch (\JsonException) {
            throw new InvalidTimeline('The Timeline JSON contains a record that could not be stored.');
        }
        $record = (new RawRecord())
            ->setSource($source)
            ->setRecordType($type)
            ->setPayload($payload)
            ->setPayloadHash(hash('sha256', $payload))
            ->setCreatedAt($importedAt)
            ->setRecordedAt(\DateTimeImmutable::createFromMutable($points[0]->getRecordedAt()))
            ->setParseStatus('done')
            ->setParsedAt(\DateTime::createFromImmutable($importedAt));
        $em->persist($record);

        foreach ($points as $point) {
            $point->setSource($source)->setRawRecord($record);
            $em->persist($point);
        }
    }

    private function point(mixed $coordinates, mixed $timestamp, \DateTimeImmutable $importedAt): ?LocationPoint
    {
        if (!\is_string($coordinates) || !preg_match('/^\s*(?:geo:)?([+-]?\d+(?:\.\d+)?)\s*°?\s*,\s*([+-]?\d+(?:\.\d+)?)\s*°?\s*$/uD', $coordinates, $matches)) {
            return null;
        }
        $latitude = (float) $matches[1];
        $longitude = (float) $matches[2];
        if (abs($latitude) > 90 || abs($longitude) > 180) {
            return null;
        }

        if (!\is_string($timestamp) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})$/D', $timestamp)) {
            return null;
        }
        try {
            $recordedAt = new \DateTime($timestamp);
        } catch (\DateMalformedStringException) {
            return null;
        }
        if (false !== \DateTime::getLastErrors()) {
            return null;
        }

        return (new LocationPoint())
            ->setLatitude($latitude)
            ->setLongitude($longitude)
            ->setRecordedAt($recordedAt->setTimezone($importedAt->getTimezone()))
            ->setCreatedAt($importedAt);
    }

    private function measurement(mixed $value): ?float
    {
        return (\is_int($value) || \is_float($value)) && is_finite($value) ? $value : null;
    }
}
