<?php

namespace App\Tests\Support;

use App\Entity\LocationPoint;
use App\Entity\LocationSource;
use App\Entity\RawRecord;
use Doctrine\ORM\EntityManagerInterface;

trait LocationPointFixturesTrait
{
    protected function persistPoint(EntityManagerInterface $em, LocationSource $source, string $recordedAt, float $lat = 35.25, float $lon = 139.75): LocationPoint
    {
        $raw = (new RawRecord())->setSource($source)->setRecordType('owntracks_location')
            ->setPayload('{}')->setPayloadHash(hash('sha256', $source->getId().':'.$recordedAt))
            ->setParseStatus('done')->setCreatedAt(new \DateTimeImmutable());
        $point = (new LocationPoint())->setSource($source)->setRawRecord($raw)
            ->setRecordedAt(new \DateTime($recordedAt, new \DateTimeZone('UTC')))
            ->setLatitude($lat)->setLongitude($lon)->setCreatedAt(new \DateTimeImmutable());
        $em->persist($raw);
        $em->persist($point);

        return $point;
    }
}
