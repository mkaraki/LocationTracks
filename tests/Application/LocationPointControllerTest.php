<?php

namespace App\Tests\Application;

use App\Entity\LocationPoint;
use App\Entity\LocationSource;
use App\Entity\RawRecord;
use App\Tests\Support\DatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

class LocationPointControllerTest extends WebTestCase
{
    use DatabaseTrait;

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

    private function createPoint(LocationSource $source, int $timestamp = 1700000000): LocationPoint
    {
        $raw = (new RawRecord())->setSource($source)->setRecordType('owntracks_location')
            ->setPayload('{}')->setPayloadHash(hash('sha256', $source->getId().':'.$timestamp))
            ->setCreatedAt(new \DateTimeImmutable())->setParseStatus('done');
        $point = (new LocationPoint())->setSource($source)->setRawRecord($raw)
            ->setRecordedAt(new \DateTime('@'.$timestamp))
            ->setLatitude(35.6812)->setLongitude(139.7671)
            ->setCreatedAt(new \DateTimeImmutable('2026-10-31 00:00:00 UTC'));
        $this->em->persist($raw);
        $this->em->persist($point);

        return $point;
    }

    private function rowIds(Crawler $crawler): array
    {
        return $crawler->filter('tbody tr th[scope="row"]')->each(
            static fn (Crawler $cell): int => (int) $cell->text()
        );
    }

    public function testAnonymousVisitorIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/points/');
        self::assertResponseRedirects('/login');
    }

    public function testIngestionCredentialsDoNotGrantAccessToPoints(): void
    {
        $this->client->request('GET', '/points/', server: [
            'PHP_AUTH_USER' => 'phone', 'PHP_AUTH_PW' => 'submit-password',
        ]);
        self::assertResponseRedirects('/login');
    }

    public function testEmptyListingDoesNotExposeAnotherOwnersPoints(): void
    {
        $other = $this->createSource($this->em, 'other');
        $this->createPoint($other);
        $this->em->flush();
        $this->client->loginUser($this->source->getUser());
        $this->client->request('GET', '/points/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1', 'Location Points');
        self::assertSelectorCount(0, 'tbody tr');
        self::assertSelectorNotExists('a[rel="next"], a[rel="prev"]');
    }

    public function testRendersRecordedTimeCoordinatesAndMeasurements(): void
    {
        $point = $this->createPoint($this->source);
        $point->setAccuracyM(5.5)->setAltitudeM(-12.25);
        $this->em->flush();
        $this->em->clear();
        $this->client->loginUser($this->source->getUser());
        $crawler = $this->client->request('GET', '/points/');

        self::assertResponseIsSuccessful();
        self::assertSame([$point->getId()], $this->rowIds($crawler));
        self::assertSelectorTextSame('tbody time', '2023-11-14 22:13:20');
        self::assertSame('2023-11-14T22:13:20+00:00', $crawler->filter('tbody time')->attr('datetime'));
        self::assertSelectorTextContains('thead', 'UTC');
        self::assertSelectorTextContains('tbody', '139.7671, 35.6812');
        self::assertSelectorTextContains('tbody', 'Acc: 5.5 m');
        self::assertSelectorTextContains('tbody', 'Alt: -12.25 m');
        self::assertSelectorTextNotContains('tbody', '2026-10-31');
    }

    #[DataProvider('optionalMeasurements')]
    public function testOptionalMeasurementsDistinguishMissingFromZero(?float $accuracy, ?float $altitude, string $expected): void
    {
        $this->createPoint($this->source)->setAccuracyM($accuracy)->setAltitudeM($altitude);
        $this->em->flush();
        $this->client->loginUser($this->source->getUser());
        $this->client->request('GET', '/points/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('tbody', $expected);
    }

    public static function optionalMeasurements(): iterable
    {
        yield 'unknown' => [null, null, 'Acc: ? m, Alt: ? m'];
        yield 'zero' => [0.0, 0.0, 'Acc: 0 m, Alt: 0 m'];
        yield 'mixed' => [null, 0.0, 'Acc: ? m, Alt: 0 m'];
    }

    public function testListsAllOwnedSourcesInDescendingIdOrder(): void
    {
        $second = $this->createSource($this->em, 'second', $this->source->getUser());
        $other = $this->createSource($this->em, 'other');
        $firstPoint = $this->createPoint($this->source, 1700000020);
        $this->em->flush();
        $otherPoint = $this->createPoint($other);
        $this->em->flush();
        // A late submission has an older recorded timestamp but a newer id.
        $lastPoint = $this->createPoint($second, 1700000010);
        $this->em->flush();
        $this->client->loginUser($this->source->getUser());
        $crawler = $this->client->request('GET', '/points/');

        self::assertResponseIsSuccessful();
        self::assertSame([$lastPoint->getId(), $firstPoint->getId()], $this->rowIds($crawler));
        self::assertNotContains($otherPoint->getId(), $this->rowIds($crawler));
    }

    public function testDisablingSourceDoesNotHideItsHistory(): void
    {
        $point = $this->createPoint($this->source);
        $this->source->setDeletedAt(new \DateTime())->setAuthUsername(null)->setAuthPasswordHash(null);
        $this->em->flush();
        $this->client->loginUser($this->source->getUser());
        $crawler = $this->client->request('GET', '/points/');

        self::assertResponseIsSuccessful();
        self::assertSame([$point->getId()], $this->rowIds($crawler));
    }

    public function testCursorPaginationHasNoMissingOrRepeatedPointsAndPreservesOwnerScope(): void
    {
        $other = $this->createSource($this->em, 'other');
        $owned = [];
        for ($i = 0; $i < 101; ++$i) {
            $owned[] = $this->createPoint($this->source, 1700000000 + $i);
            $this->createPoint($other, 1700000000 + $i);
        }
        $this->em->flush();
        $expected = array_reverse(array_map(static fn (LocationPoint $point): int => $point->getId(), $owned));
        $this->client->loginUser($this->source->getUser());
        $first = $this->client->request('GET', '/points/');
        self::assertResponseIsSuccessful();
        self::assertSame(array_slice($expected, 0, 100), $this->rowIds($first));
        self::assertSelectorNotExists('a[rel="prev"]');
        $nextUrl = $first->filter('a[rel="next"]')->attr('href');

        $last = $this->client->request('GET', $nextUrl);
        self::assertResponseIsSuccessful();
        self::assertSame(array_slice($expected, 100), $this->rowIds($last));
        self::assertSelectorNotExists('a[rel="next"]');
        $previousUrl = $last->filter('a[rel="prev"]')->attr('href');
        $back = $this->client->request('GET', $previousUrl);
        self::assertResponseIsSuccessful();
        self::assertSame(array_slice($expected, 0, 100), $this->rowIds($back));

        // The paginator binds cursors to the query's owner parameter.
        $this->client->loginUser($other->getUser());
        $otherPage = $this->client->request('GET', $nextUrl);
        self::assertResponseStatusCodeSame(400);
        self::assertSame([], array_intersect($expected, $this->rowIds($otherPage)));
    }

    #[DataProvider('invalidCursors')]
    public function testMalformedCursorIsBadRequest(array $query): void
    {
        $this->client->loginUser($this->source->getUser());
        $this->client->request('GET', '/points/', $query);
        self::assertResponseStatusCodeSame(400);
    }

    public static function invalidCursors(): iterable
    {
        yield 'invalid signature' => [['cursor' => 'invalid']];
        yield 'array value' => [['cursor' => ['invalid']]];
    }
}
