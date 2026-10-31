<?php

namespace App\Tests\Integration;

use App\Controller\LocationPointController;
use App\Entity\LocationPoint;
use App\Entity\LocationSource;
use App\Entity\RawRecord;
use App\Tests\Support\DatabaseTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class LocationPersistenceTest extends KernelTestCase
{
    use DatabaseTrait;

    public function testParsedLocationRoundTripsThroughDoctrineRepositories(): void
    {
        self::bootKernel();
        $em = $this->prepareDatabase();
        $source = $this->createSource($em);
        $payload = '{"_type":"location","tst":1700000000,"lat":35.5,"lon":139.5}';
        $raw = (new RawRecord())->setSource($source)->setRecordType('owntracks_location')
            ->setPayload($payload)->setPayloadHash(hash('sha256', $payload))->setCreatedAt(new \DateTimeImmutable());
        $point = static::getContainer()->get(LocationPointController::class)->process_owntracks_location_data(
            $em, $source, $raw, json_decode($payload, true, flags: JSON_THROW_ON_ERROR) + ['tid' => 'AB', 'acc' => 3.5]
        );
        $id = $point->getId();
        $sourceId = $source->getId();
        $em->clear();

        $stored = $em->getRepository(LocationPoint::class)->find($id);
        self::assertSame(35.5, $stored->getLatitude());
        self::assertSame(139.5, $stored->getLongitude());
        self::assertSame(3.5, $stored->getAccuracyM());
        self::assertSame(['tid' => 'AB'], $stored->getMetadata());
        self::assertSame(1700000000, $stored->getRecordedAt()->getTimestamp());
        self::assertSame($sourceId, $stored->getSource()->getId());
        self::assertSame('done', $stored->getRawRecord()->getParseStatus());
        self::assertSame($payload, $stored->getRawRecord()->getPayload());
        self::assertSame(hash('sha256', $payload), $stored->getRawRecord()->getPayloadHash());
        self::assertNotNull($stored->getRawRecord()->getParsedAt());
        self::assertCount(1, $stored->getRawRecord()->getLocationPoints());
        $reloadedSource = $em->getRepository(LocationSource::class)->find($sourceId);
        self::assertCount(1, $reloadedSource->getRawRecords());
        self::assertCount(1, $reloadedSource->getLocationPoints());
        self::assertTrue(static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($reloadedSource, 'submit-password'));
    }
}
