<?php

namespace App\Tests\Integration;

use App\Entity\LocationPoint;
use App\Repository\LocationPointRepository;
use App\Tests\Support\DatabaseTrait;
use App\Tests\Support\LocationPointFixturesTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class LocationPointRepositoryTest extends KernelTestCase
{
    use DatabaseTrait;
    use LocationPointFixturesTrait;

    public function testTimeRangeIsInclusiveChronologicalAndScopedToOwnerAcrossSources(): void
    {
        self::bootKernel();
        $em = $this->prepareDatabase();
        $source = $this->createSource($em);
        $second = $this->createSource($em, 'second', $source->getUser());
        $other = $this->createSource($em, 'other');
        $end = $this->persistPoint($em, $source, '2026-11-01 12:01:59');
        $this->persistPoint($em, $source, '2026-11-01 11:59:59');
        $this->persistPoint($em, $other, '2026-11-01 12:01:00');
        $start = $this->persistPoint($em, $second, '2026-11-01 12:00:00');
        $this->persistPoint($em, $source, '2026-11-01 12:02:00');
        $middle = $this->persistPoint($em, $source, '2026-11-01 12:01:00');
        $em->flush();
        $owner = $source->getUser();
        $expected = [$start->getId(), $middle->getId(), $end->getId()];
        $em->clear();

        $found = static::getContainer()->get(LocationPointRepository::class)->findByUserAndTimeRange(
            $owner, new \DateTime('2026-11-01 12:00:00 UTC'), new \DateTime('2026-11-01 12:01:59 UTC')
        );

        self::assertSame($expected, array_map(static fn (LocationPoint $point): int => $point->getId(), $found));
    }

    public function testRangeWithNoOwnedPointsReturnsEmptyArray(): void
    {
        self::bootKernel();
        $em = $this->prepareDatabase();
        $source = $this->createSource($em);
        $other = $this->createSource($em, 'other');
        $this->persistPoint($em, $other, '2026-11-01 12:00:00');
        $em->flush();

        self::assertSame([], static::getContainer()->get(LocationPointRepository::class)->findByUserAndTimeRange(
            $source->getUser(), new \DateTime('2026-11-01 12:00:00 UTC'), new \DateTime('2026-11-01 12:01:59 UTC')
        ));
    }
}
