<?php

namespace App\Tests\Unit\Controller;

use App\Controller\LocationPointController;
use App\Entity\LocationPoint;
use App\Entity\LocationSource;
use App\Entity\RawRecord;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LocationPointControllerTest extends TestCase
{
    public function testMapsLocationAndPreservesUnknownMetadata(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $persisted = [];
        $em->expects(self::exactly(3))->method('persist')->willReturnCallback(
            static function (object $entity) use (&$persisted): void { $persisted[] = $entity; }
        );
        $em->expects(self::once())->method('flush');
        $source = new LocationSource();
        $raw = new RawRecord();
        $before = new \DateTimeImmutable();
        $point = (new LocationPointController())->process_owntracks_location_data($em, $source, $raw, [
            '_type' => 'location', 'tst' => '1700000000', 'lat' => '35.6812', 'lon' => '139.7671',
            'acc' => '5.5', 'alt' => -12, 'vel' => '36', 'cog' => 180, 'batt' => '87',
            'tid' => 'AB', 'extra' => ['regions' => ['home', 'work']],
        ]);

        self::assertInstanceOf(LocationPoint::class, $point);
        self::assertSame([$raw, $point, $raw], $persisted);
        self::assertSame($source, $point->getSource());
        self::assertSame($raw, $point->getRawRecord());
        self::assertSame(1700000000, $point->getRecordedAt()->getTimestamp());
        self::assertSame('UTC', $point->getRecordedAt()->getTimezone()->getName());
        self::assertSame(35.6812, $point->getLatitude());
        self::assertSame(139.7671, $point->getLongitude());
        self::assertSame(5.5, $point->getAccuracyM());
        self::assertSame(-12.0, $point->getAltitudeM());
        self::assertSame(36.0, $point->getSpeedKmh());
        self::assertSame(180.0, $point->getHeadingDeg());
        self::assertSame(87, $point->getBatteryPercent());
        self::assertSame(['tid' => 'AB', 'extra' => ['regions' => ['home', 'work']]], $point->getMetadata());
        self::assertGreaterThanOrEqual($before, $point->getCreatedAt());
        self::assertSame('done', $raw->getParseStatus());
        self::assertNull($raw->getParseError());
        self::assertNotNull($raw->getParsedAt());
    }

    #[DataProvider('invalidLocations')]
    public function testRejectsInvalidLocationWithoutPersistingPoint(array $data, string $error, bool $flush): void
    {
        $raw = new RawRecord();
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::exactly(2))->method('persist')->with(self::identicalTo($raw));
        $em->expects($flush ? self::once() : self::never())->method('flush');
        $point = (new LocationPointController())->process_owntracks_location_data($em, new LocationSource(), $raw, $data, $flush);

        self::assertNull($point);
        self::assertSame('error', $raw->getParseStatus());
        self::assertSame($error, $raw->getParseError());
        self::assertNotNull($raw->getParsedAt());
    }

    public static function invalidLocations(): iterable
    {
        $valid = ['tst' => 1700000000, 'lat' => 35, 'lon' => 139];
        $required = 'Numeric records: `tst`, `lat`, and `lon` are required.';
        $invalid = 'Records: `tst`, `lat`, and `lon` are invalid.';
        foreach (['tst', 'lat', 'lon'] as $field) {
            $missing = $valid;
            unset($missing[$field]);
            yield 'missing '.$field => [$missing, $required, true];
            yield 'null '.$field => [array_replace($valid, [$field => null]), $required, false];
            yield 'non numeric '.$field => [array_replace($valid, [$field => 'invalid']), $required, true];
        }
        foreach (['fractional timestamp' => ['tst' => 1.5], 'overflow timestamp' => ['tst' => '999999999999999999999'],
            'latitude north' => ['lat' => 90.01], 'latitude south' => ['lat' => -90.01],
            'longitude east' => ['lon' => 180.01], 'longitude west' => ['lon' => -180.01],
            'infinite latitude' => ['lat' => INF], 'nan longitude' => ['lon' => NAN]] as $name => $value) {
            yield $name => [array_replace($valid, $value), $invalid, false];
        }
    }

    #[DataProvider('validBoundaries')]
    public function testAcceptsBoundariesAndIgnoresInvalidOptionalValues(int $timestamp, float $lat, float $lon): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::exactly(3))->method('persist');
        $em->expects(self::never())->method('flush');
        $point = (new LocationPointController())->process_owntracks_location_data($em, new LocationSource(), new RawRecord(), [
            'tst' => $timestamp, 'lat' => $lat, 'lon' => $lon,
            'acc' => 'bad', 'alt' => null, 'vel' => [], 'cog' => 'bad', 'batt' => 'bad',
        ], false);

        self::assertNotNull($point);
        self::assertSame($timestamp, $point->getRecordedAt()->getTimestamp());
        self::assertSame($lat, $point->getLatitude());
        self::assertSame($lon, $point->getLongitude());
        self::assertNull($point->getAccuracyM());
        self::assertNull($point->getAltitudeM());
        self::assertNull($point->getSpeedKmh());
        self::assertNull($point->getHeadingDeg());
        self::assertNull($point->getBatteryPercent());
        self::assertSame([], $point->getMetadata());
    }

    public static function validBoundaries(): iterable
    {
        yield 'origin at epoch' => [0, 0.0, 0.0];
        yield 'north east' => [1700000000, 90.0, 180.0];
        yield 'south west before epoch' => [-1, -90.0, -180.0];
    }
}
