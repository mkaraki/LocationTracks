<?php

namespace App\Tests\Application;

use App\Entity\LocationSource;
use App\Tests\Support\DatabaseTrait;
use App\Tests\Support\LocationPointFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

class PointMapControllerTest extends WebTestCase
{
    use DatabaseTrait;
    use LocationPointFixturesTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private LocationSource $source;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = $this->prepareDatabase();
        $this->source = $this->createSource($this->em);
    }

    private function showMap(array $query = []): Crawler
    {
        $this->em->flush();
        $this->client->loginUser($this->source->getUser());

        return $this->client->request('GET', '/points/map', array_replace([
            'start' => '2026-11-01T12:00', 'end' => '2026-11-01T12:02',
            'tz_sign' => '+', 'tz_hours' => 0, 'tz_minutes' => 0,
        ], $query));
    }

    private function mapValue(Crawler $crawler, string $name): mixed
    {
        // Verify the serialized values consumed by the real Leaflet Stimulus controller.
        return json_decode($crawler->filter('.map-canvas')->attr('data-symfony--ux-leaflet-map--map-'.$name.'-value'), true, flags: JSON_THROW_ON_ERROR);
    }

    public function testEmptyMapDoesNotExposeAnotherOwnerOrOutOfRangePoints(): void
    {
        $other = $this->createSource($this->em, 'other');
        $this->persistPoint($this->em, $other, '2026-11-01 12:01:00', 51.5, -0.25);
        $this->persistPoint($this->em, $this->source, '2026-11-01 11:59:59');
        $crawler = $this->showMap();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('#point-count', 'Showing 0 items');
        self::assertEquals(['lat' => 0, 'lng' => 0], $this->mapValue($crawler, 'center'));
        self::assertSame(6, $this->mapValue($crawler, 'zoom'));
        self::assertFalse($this->mapValue($crawler, 'fit-bounds-to-markers'));
        $markers = $this->mapValue($crawler, 'markers');
        self::assertCount(1, $markers);
        self::assertSame('No data found', $markers[0]['title']);
        self::assertEquals(['lat' => 0, 'lng' => 0], $markers[0]['position']);
        self::assertSame([], $this->mapValue($crawler, 'polylines'));
    }

    public function testSinglePointHasCorrectCoordinatesTitleCenterAndZoom(): void
    {
        $point = $this->persistPoint($this->em, $this->source, '2026-11-01 12:01:00');
        $other = $this->createSource($this->em, 'other');
        $this->persistPoint($this->em, $other, '2026-11-01 12:01:00', -33.5, 151.25);
        $crawler = $this->showMap();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('#point-count', 'Showing 1 items');
        $position = ['lat' => 35.25, 'lng' => 139.75];
        self::assertSame($position, $this->mapValue($crawler, 'center'));
        self::assertSame(17, $this->mapValue($crawler, 'zoom'));
        self::assertFalse($this->mapValue($crawler, 'fit-bounds-to-markers'));
        $markers = $this->mapValue($crawler, 'markers');
        self::assertCount(1, $markers);
        self::assertSame($position, $markers[0]['position']);
        self::assertSame('Id: #'.$point->getId(), $markers[0]['title']);
        self::assertSame([], $this->mapValue($crawler, 'polylines'));
    }

    public function testMultiplePointsProduceChronologicalPolylineAndMarkersAcrossOwnedSources(): void
    {
        $second = $this->createSource($this->em, 'second', $this->source->getUser());
        $other = $this->createSource($this->em, 'other');
        // Persist out of time order, so an id-ordered path would fail this assertion.
        $this->persistPoint($this->em, $this->source, '2026-11-01 12:02:00', 35.75, 139.75);
        $this->persistPoint($this->em, $second, '2026-11-01 12:00:00', 35.25, 139.25);
        $this->persistPoint($this->em, $other, '2026-11-01 12:00:30', -33.5, 151.25);
        $this->persistPoint($this->em, $this->source, '2026-11-01 12:01:00', 35.5, 139.5);
        $crawler = $this->showMap();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('#point-count', 'Showing 3 items');
        $expected = [
            ['lat' => 35.25, 'lng' => 139.25],
            ['lat' => 35.5, 'lng' => 139.5],
            ['lat' => 35.75, 'lng' => 139.75],
        ];
        $polylines = $this->mapValue($crawler, 'polylines');
        self::assertCount(1, $polylines);
        self::assertSame($expected, $polylines[0]['points']);
        $markers = $this->mapValue($crawler, 'markers');
        self::assertSame($expected, array_column($markers, 'position'));
        foreach ($markers as $marker) {
            self::assertNotNull($marker['icon']);
        }
        self::assertTrue($this->mapValue($crawler, 'fit-bounds-to-markers'));
    }

    public function testSelectedMinuteIncludesBothBoundariesAndExcludesAdjacentMinutes(): void
    {
        foreach (['11:59:59', '12:00:00', '12:00:59', '12:01:00'] as $index => $time) {
            $this->persistPoint($this->em, $this->source, '2026-11-01 '.$time, 35.25 + $index * 0.125);
        }
        $crawler = $this->showMap(['end' => '2026-11-01T12:00']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('#point-count', 'Showing 2 items');
        self::assertSame([
            ['lat' => 35.375, 'lng' => 139.75], ['lat' => 35.5, 'lng' => 139.75],
        ], array_column($this->mapValue($crawler, 'markers'), 'position'));
    }

    public function testDisabledSourceHistoryRemainsPlotted(): void
    {
        $this->persistPoint($this->em, $this->source, '2026-11-01 12:01:00');
        $this->source->setDeletedAt(new \DateTime())->setAuthUsername(null)->setAuthPasswordHash(null);
        $crawler = $this->showMap();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('#point-count', 'Showing 1 items');
        self::assertSame(['lat' => 35.25, 'lng' => 139.75], $this->mapValue($crawler, 'markers')[0]['position']);
    }

    public function testSubmittingFilterFormReplacesThePlottedCoordinates(): void
    {
        $this->persistPoint($this->em, $this->source, '2026-11-01 12:00:00', 35.25, 139.25);
        $this->persistPoint($this->em, $this->source, '2026-11-01 12:01:00', 35.75, 139.75);
        $crawler = $this->showMap();
        self::assertResponseIsSuccessful();
        $crawler = $this->client->submit($crawler->selectButton('Plot')->form([
            'start' => '2026-11-01T12:01', 'end' => '2026-11-01T12:01',
        ]));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('#point-count', 'Showing 1 items');
        self::assertSame(['lat' => 35.75, 'lng' => 139.75], $this->mapValue($crawler, 'markers')[0]['position']);
        self::assertSame([], $this->mapValue($crawler, 'polylines'));
        self::assertInputValueSame('start', '2026-11-01T12:01');
    }

    public function testInvalidFiltersDoNotSerializeAnyStoredLocation(): void
    {
        $this->persistPoint($this->em, $this->source, '2026-11-01 12:01:00');
        $crawler = $this->showMap(['end' => '2026-11-01T11:00']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextSame('#point-count', 'Showing 0 items');
        self::assertSame('No data found', $this->mapValue($crawler, 'markers')[0]['title']);
        self::assertEquals(['lat' => 0, 'lng' => 0], $this->mapValue($crawler, 'markers')[0]['position']);
        self::assertSame([], $this->mapValue($crawler, 'polylines'));
    }

    public function testIngestionCredentialsCannotAccessMap(): void
    {
        $this->client->request('GET', '/points/map', server: [
            'PHP_AUTH_USER' => 'phone', 'PHP_AUTH_PW' => 'submit-password',
        ]);
        self::assertResponseRedirects('/login');
        self::assertSelectorNotExists('.map-canvas');
    }
}
