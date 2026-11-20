<?php

namespace App\Tests\Application;

use App\Entity\LocationPoint;
use App\Entity\LocationSource;
use App\Entity\RawRecord;
use App\Import\GoogleTimelineImporter;
use App\Tests\Support\DatabaseTrait;
use App\Tests\Support\LocationPointFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class LocationSourceControllerTest extends WebTestCase
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

    private function loginOwner(): void
    {
        $this->client->loginUser($this->source->getUser(), 'main');
    }

    private function reloadSource(?int $id = null): LocationSource
    {
        $id ??= $this->source->getId();
        $this->em->clear();

        return $this->em->find(LocationSource::class, $id);
    }

    private function disableToken(): string
    {
        $crawler = $this->client->request('GET', '/settings/sources/');
        self::assertResponseIsSuccessful();

        return $crawler->filter(sprintf('form[action="/settings/sources/%d/delete"] input[name="token"]', $this->source->getId()))->attr('value');
    }

    #[DataProvider('protectedPages')]
    public function testAnonymousUserMustLogIn(string $method, string $path): void
    {
        $this->client->request($method, $path);
        self::assertResponseRedirects('/login');
        self::assertNull($this->reloadSource()->getDeletedAt());
    }

    public static function protectedPages(): iterable
    {
        yield 'list' => ['GET', '/settings/sources/'];
        yield 'create' => ['GET', '/settings/sources/new'];
        yield 'submit new' => ['POST', '/settings/sources/new'];
        // An existing id is not needed: the firewall must reject before resolution.
        yield 'disable' => ['POST', '/settings/sources/1/delete'];
        yield 'delete confirmation' => ['GET', '/settings/sources/1/remove'];
        yield 'delete timeline' => ['POST', '/settings/sources/1/remove'];
    }

    public function testListShowsOnlyOwnedSourcesAndEnabledState(): void
    {
        $other = $this->createSource($this->em, 'other');
        $other->setName('Other owner private phone');
        $retired = $this->createSource($this->em, 'retired', $this->source->getUser());
        $retired->setName('Retired phone')->setDeletedAt(new \DateTime());
        $this->em->flush();
        $this->loginOwner();
        $crawler = $this->client->request('GET', '/settings/sources/');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(2, 'tbody tr');
        self::assertSelectorTextNotContains('tbody', 'Other owner private phone');
        $active = $crawler->filter('tbody tr')->reduce(fn (Crawler $row): bool => str_contains($row->text(), 'OwnTracks phone'));
        self::assertSame('Yes', $active->filter('td')->eq(2)->text());
        self::assertCount(1, $active->selectButton('Disable'));
        $disabled = $crawler->filter('tbody tr')->reduce(fn (Crawler $row): bool => str_contains($row->text(), 'Retired phone'));
        self::assertSame('No', $disabled->filter('td')->eq(2)->text());
        self::assertCount(0, $disabled->selectButton('Disable'));
        self::assertSelectorExists('a[href="/settings/sources/new"]');
        self::assertSelectorNotExists('input[name="auth_password_hash"]');
    }

    public function testOwnerCanCreateSourceAndUseItsHashedCredentialsToSubmit(): void
    {
        $this->loginOwner();
        $this->client->request('GET', '/settings/sources/new');
        self::assertResponseIsSuccessful();
        $this->client->submitForm('Save', [
            'form[name]' => 'New phone', 'form[source_type]' => '1',
            'form[auth_username]' => 'new-phone', 'form[auth_password_hash]' => 'new-secret',
        ]);
        self::assertResponseRedirects('/settings/sources/');
        $this->em->clear();
        $created = $this->em->getRepository(LocationSource::class)->findOneBy(['auth_username' => 'new-phone']);
        self::assertNotNull($created);
        self::assertSame($this->source->getUser()->getId(), $created->getUser()->getId());
        self::assertSame('New phone', $created->getName());
        self::assertSame(1, $created->getSourceType());
        self::assertNull($created->getDeletedAt());
        self::assertNotNull($created->getCreatedAt());
        self::assertNotSame('new-secret', $created->getPassword());
        self::assertTrue(static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($created, 'new-secret'));

        $this->client->request('POST', '/api/v1/submit/owntracks', server: [
            'CONTENT_TYPE' => 'application/json', 'PHP_AUTH_USER' => 'new-phone', 'PHP_AUTH_PW' => 'new-secret',
        ], content: '{"_type":"location","tst":1700000000,"lat":35,"lon":139}');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->em->getRepository(LocationPoint::class)->count(['source' => $created->getId()]));
    }

    #[DataProvider('invalidSourceFields')]
    public function testInvalidCreationDoesNotPersistSource(array $fields): void
    {
        $this->loginOwner();
        $this->client->request('GET', '/settings/sources/new');
        $this->client->submitForm('Save', array_replace([
            'form[name]' => 'New phone', 'form[source_type]' => '1',
            'form[auth_username]' => 'new-phone', 'form[auth_password_hash]' => 'new-secret',
        ], $fields));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.invalid-feedback, ul li');
        self::assertSame(1, $this->em->getRepository(LocationSource::class)->count([]));
    }

    public static function invalidSourceFields(): iterable
    {
        yield 'blank name' => [['form[name]' => '']];
        yield 'blank username' => [['form[auth_username]' => '']];
        yield 'blank password' => [['form[auth_password_hash]' => '']];
        yield 'name too long' => [['form[name]' => str_repeat('n', 256)]];
        yield 'username too long' => [['form[auth_username]' => str_repeat('u', 256)]];
    }

    public function testCreationRejectsInvalidCsrfToken(): void
    {
        $this->loginOwner();
        $this->client->request('GET', '/settings/sources/new');
        $this->client->submitForm('Save', [
            'form[name]' => 'New phone', 'form[source_type]' => '1',
            'form[auth_username]' => 'new-phone', 'form[auth_password_hash]' => 'new-secret',
            'form[_token]' => 'invalid',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'The CSRF token is invalid.');
        self::assertSame(1, $this->em->getRepository(LocationSource::class)->count([]));
    }

    public function testOwnerCanImportAndroidTimelineAsOneSource(): void
    {
        $this->loginOwner();
        $this->uploadTimeline(<<<'JSON'
            {
              "semanticSegments": [
                {"timelinePath": [{"point": "-12.5°, 45.25°", "time": "2025-01-01T02:00:00.123+02:00"}]},
                {"startTime": "2025-01-01T00:10:00Z", "visit": {"topCandidate": {"placeLocation": {"latLng": "-12.6°, 45.3°"}}}},
                {
                  "startTime": "2025-01-01T00:20:00Z",
                  "endTime": "2025-01-01T00:40:00Z",
                  "activity": {
                    "start": {"latLng": "-12.7°, 45.4°"},
                    "end": {"latLng": "-12.8°, 45.5°"},
                    "parking": {"location": {"latLng": "-12.81°, 45.51°"}, "startTime": "2025-01-01T00:35:00Z"}
                  }
                }
              ],
              "rawSignals": [
                {"wifiScan": {"devicesRecords": []}},
                {"activityRecord": {"probableActivities": []}},
                {"position": {"LatLng": "-12.9°, 45.75°", "timestamp": "2025-01-01T03:00:00.123+02:00", "accuracyMeters": 0, "altitudeMeters": 23.5, "speedMetersPerSecond": 2.5}}
              ],
              "userLocationProfile": {"frequentPlaces": []}
            }
            JSON);

        self::assertResponseRedirects('/settings/sources/', 303);
        $this->em->clear();
        $imports = $this->em->getRepository(LocationSource::class)->findBy(['source_type' => LocationSource::TYPE_GOOGLE_TIMELINE]);
        self::assertCount(1, $imports);
        $import = $imports[0];
        self::assertSame($this->source->getUser()->getId(), $import->getUser()->getId());
        self::assertSame('Android history', $import->getName());
        self::assertNull($import->getAuthUsername());
        self::assertNull($import->getPassword());
        self::assertSame([], $import->getRoles());
        self::assertCount(4, $import->getRawRecords());
        self::assertCount(6, $import->getLocationPoints());

        $path = $import->getRawRecords()->findFirst(static fn (int $key, RawRecord $record): bool => 'google_timeline_path' === $record->getRecordType());
        self::assertNotNull($path);
        self::assertSame('2025-01-01 00:00:00', $path->getRecordedAt()->format('Y-m-d H:i:s'));
        self::assertSame(-12.5, $path->getLocationPoints()->first()->getLatitude());

        $activity = $import->getRawRecords()->findFirst(static fn (int $key, RawRecord $record): bool => 'google_timeline_activity' === $record->getRecordType());
        self::assertNotNull($activity);
        $activityTimes = $activity->getLocationPoints()->map(static fn (LocationPoint $point): string => $point->getRecordedAt()->format('H:i:s'));
        self::assertEqualsCanonicalizing(['00:20:00', '00:35:00', '00:40:00'], $activityTimes->getValues());

        $position = $import->getRawRecords()->findFirst(static fn (int $key, RawRecord $record): bool => 'google_timeline_position' === $record->getRecordType());
        self::assertNotNull($position);
        self::assertSame('done', $position->getParseStatus());
        $point = $position->getLocationPoints()->first();
        self::assertSame('2025-01-01 01:00:00', $point->getRecordedAt()->format('Y-m-d H:i:s'));
        self::assertSame(45.75, $point->getLongitude());
        self::assertSame(0.0, $point->getAccuracyM());
        self::assertSame(23.5, $point->getAltitudeM());
        self::assertSame(9.0, $point->getSpeedKmh());

        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('[role="status"]', 'Imported 6 location points');
        $row = $crawler->filter('tbody tr')->reduce(static fn (Crawler $row): bool => str_contains($row->text(), 'Android history'));
        self::assertStringContainsString('Google Timeline', $row->text());
        self::assertSame('Yes', $row->filter('td')->eq(2)->text());
        self::assertCount(0, $row->selectButton('Disable'));
        self::assertCount(1, $crawler->selectButton('Disable'));

        $this->client->request('POST', '/settings/sources/'.$import->getId().'/delete', ['token' => $this->disableToken()]);
        self::assertResponseStatusCodeSame(404);
        self::assertNull($this->reloadSource($import->getId())->getDeletedAt());
        self::assertSame(6, $this->em->getRepository(LocationPoint::class)->count(['source' => $import->getId()]));

        $this->client->request('GET', '/points/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('tbody', '45.75');
    }

    public function testTimelineImportRollsBackPreviouslyFlushedPointsOnTruncatedJson(): void
    {
        $this->loginOwner();
        $points = array_map(static fn (int $index): string => sprintf('{"point":"geo:-12.%03d,45.25","time":"2025-01-01T00:00:00Z"}', $index), range(0, 500));
        $this->uploadTimeline('{"semanticSegments":[{"timelinePath":['.implode(',', $points).']}],"rawSignals":[');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'The uploaded file is not valid Timeline JSON.');
        self::assertSame(1, $this->em->getRepository(LocationSource::class)->count([]));
        self::assertSame(0, $this->em->getRepository(RawRecord::class)->count([]));
        self::assertSame(0, $this->em->getRepository(LocationPoint::class)->count([]));
    }

    public function testTimelineSkipsDuplicatesAcrossBatchesAndDoesNotCreateAnEmptySourceOnReplay(): void
    {
        $this->loginOwner();
        $points = array_map(static fn (int $index): array => [
            'point' => sprintf('geo:-12.%03d,45.25', $index),
            'time' => '2025-01-01T00:00:00Z',
        ], range(0, 499));
        $points[] = $points[0];
        $file = json_encode(['semanticSegments' => [['timelinePath' => $points]]], JSON_THROW_ON_ERROR);

        $this->uploadTimeline($file);
        self::assertResponseRedirects('/settings/sources/', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="status"]', 'Imported 500 location points');
        self::assertSelectorTextContains('[role="status"]', 'Skipped 1 duplicate');

        $this->uploadTimeline($file, ['form[name]' => 'Replay']);
        self::assertResponseRedirects('/settings/sources/', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="status"]', 'Imported 0 location points');
        self::assertSelectorTextContains('[role="status"]', 'Skipped 501 duplicate points');
        self::assertSelectorTextNotContains('tbody', 'Replay');
        self::assertSame(2, $this->em->getRepository(LocationSource::class)->count([]));
        self::assertSame(500, $this->em->getRepository(RawRecord::class)->count([]));
        self::assertSame(500, $this->em->getRepository(LocationPoint::class)->count([]));
    }

    public function testTimelineKeepsNewLocationsInAnOverlappingExport(): void
    {
        $this->loginOwner();
        $this->uploadTimeline(<<<'JSON'
            {"semanticSegments":[{"timelinePath":[
                {"point":"geo:-12.50,45.250","time":"2025-01-01T02:00:00.123+02:00"},
                {"point":"geo:-12.6,45.3","time":"2025-01-01T02:15:00+02:00"}
            ]}]}
            JSON);
        self::assertResponseRedirects('/settings/sources/', 303);
        $this->client->followRedirect();

        $this->uploadTimeline(<<<'JSON'
            {"semanticSegments":[{
                "startTime":"2025-01-01T00:00:00.123Z",
                "endTime":"2025-01-01T00:10:00Z",
                "activity":{"start":{"latLng":"-12.5°, 45.25°"},"end":{"latLng":"-12.5°, 45.25°"}}
            },{"timelinePath":[
                {"point":"geo:-12.55,45.25","time":"2025-01-01T00:00:00Z"},
                {"point":"geo:-12.55,45.25","time":"2025-01-01T00:00:00Z"}
            ]}]}
            JSON, ['form[name]' => 'Overlapping export']);
        self::assertResponseRedirects('/settings/sources/', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="status"]', 'Imported 2 location points');
        self::assertSelectorTextContains('[role="status"]', 'Skipped 2 duplicate points');

        $source = $this->em->getRepository(LocationSource::class)->findOneBy(['name' => 'Overlapping export']);
        self::assertCount(2, $source->getLocationPoints());
        $activity = $source->getRawRecords()->findFirst(static fn (int $key, RawRecord $record): bool => 'google_timeline_activity' === $record->getRecordType());
        self::assertCount(1, $activity->getLocationPoints());
        self::assertSame('00:10:00', $activity->getLocationPoints()->first()->getRecordedAt()->format('H:i:s'));
        self::assertSame(4, $this->em->getRepository(LocationPoint::class)->count([]));
    }

    public function testTimelineOnlyChecksDuplicatesAgainstTheOwnersTimelineSources(): void
    {
        $json = '{"rawSignals":[{"position":{"LatLng":"10°, 20°","timestamp":"2025-01-01T00:00:00Z"}}]}';
        $other = $this->createSource($this->em, 'other');
        $this->client->loginUser($other->getUser(), 'main');
        $this->uploadTimeline($json, ['form[name]' => 'Other timeline']);
        self::assertResponseRedirects('/settings/sources/', 303);

        $this->client->request('POST', '/api/v1/submit/owntracks', server: [
            'CONTENT_TYPE' => 'application/json', 'PHP_AUTH_USER' => 'phone', 'PHP_AUTH_PW' => 'submit-password',
        ], content: '{"_type":"location","tst":1735689600,"lat":10,"lon":20}');
        self::assertResponseIsSuccessful();

        $this->loginOwner();
        $this->uploadTimeline($json);
        self::assertResponseRedirects('/settings/sources/', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="status"]', 'Imported 1 location point from Google Timeline.');
        self::assertSame(3, $this->em->getRepository(LocationPoint::class)->count([]));
    }

    public function testTimelineRejectsAConcurrentImportForTheSameOwner(): void
    {
        $this->loginOwner();
        $lock = static::getContainer()->get(LockFactory::class)->createLock(GoogleTimelineImporter::OWNER_LOCK_PREFIX.$this->source->getUser()->getId());
        self::assertTrue($lock->acquire());

        try {
            $this->uploadTimeline('{"rawSignals":[{"position":{"LatLng":"10°, 20°","timestamp":"2025-01-01T00:00:00Z"}}]}');

            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('body', 'A Google Timeline import is already running for this account.');
            self::assertSame(1, $this->em->getRepository(LocationSource::class)->count([]));
        } finally {
            $lock->release();
        }
    }

    public function testOwnerCanDeleteATimelineSourceAndReimportItsLocations(): void
    {
        $ownTracksPoint = $this->persistPoint($this->em, $this->source, '2025-01-01 00:00:00', 10, 20);
        $this->em->flush();
        $this->loginOwner();
        $json = '{"rawSignals":[{"position":{"LatLng":"11°, 21°","timestamp":"2025-01-01T00:00:00Z"}}]}';
        $this->uploadTimeline($json, ['form[name]' => 'Timeline to remove']);
        self::assertResponseRedirects('/settings/sources/', 303);
        $this->uploadTimeline(str_replace('11°', '12°', $json), ['form[name]' => 'Timeline to keep']);
        self::assertResponseRedirects('/settings/sources/', 303);

        $target = $this->em->getRepository(LocationSource::class)->findOneBy(['name' => 'Timeline to remove']);
        $targetId = $target->getId();
        $ownTracksPoint->setSupersedes($target->getLocationPoints()->first());
        $this->em->flush();

        $crawler = $this->client->followRedirect();
        $timelineRow = $crawler->filter('tbody tr')->reduce(static fn (Crawler $row): bool => str_contains($row->text(), 'Timeline to remove'));
        self::assertCount(0, $timelineRow->selectButton('Disable'));
        $ownTracksRow = $crawler->filter('tbody tr')->reduce(static fn (Crawler $row): bool => str_contains($row->text(), 'OwnTracks phone'));
        self::assertCount(0, $ownTracksRow->selectLink('Delete'));
        self::assertCount(1, $ownTracksRow->selectButton('Disable'));
        $this->client->click($timelineRow->selectLink('Delete')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'permanently deletes Timeline to remove');
        self::assertSame(1, $this->em->getRepository(LocationPoint::class)->count(['source' => $targetId]));

        $this->client->submitForm('Delete source');
        self::assertResponseRedirects('/settings/sources/', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="status"]', 'Deleted the Google Timeline source');
        self::assertSelectorTextNotContains('tbody', 'Timeline to remove');
        self::assertSelectorTextContains('tbody', 'Timeline to keep');
        $this->em->clear();
        self::assertNull($this->em->find(LocationSource::class, $targetId));
        self::assertSame(0, $this->em->getRepository(RawRecord::class)->count(['source' => $targetId]));
        self::assertSame(0, $this->em->getRepository(LocationPoint::class)->count(['source' => $targetId]));
        self::assertSame(2, $this->em->getRepository(RawRecord::class)->count([]));
        self::assertSame(2, $this->em->getRepository(LocationPoint::class)->count([]));
        self::assertNull($this->em->find(LocationPoint::class, $ownTracksPoint->getId())->getSupersedes());

        $this->uploadTimeline($json, ['form[name]' => 'Reimported Timeline']);
        self::assertResponseRedirects('/settings/sources/', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="status"]', 'Imported 1 location point');
        self::assertSame(3, $this->em->getRepository(LocationPoint::class)->count([]));
    }

    public function testTimelineDeletionRequiresOwnershipAndAValidTokenForTheSource(): void
    {
        $this->loginOwner();
        $json = '{"rawSignals":[{"position":{"LatLng":"11°, 21°","timestamp":"2025-01-01T00:00:00Z"}}]}';
        $this->uploadTimeline($json, ['form[name]' => 'First Timeline']);
        self::assertResponseRedirects('/settings/sources/', 303);
        $this->uploadTimeline(str_replace('11°', '12°', $json), ['form[name]' => 'Second Timeline']);
        self::assertResponseRedirects('/settings/sources/', 303);

        $repository = $this->em->getRepository(LocationSource::class);
        $first = $repository->findOneBy(['name' => 'First Timeline']);
        $second = $repository->findOneBy(['name' => 'Second Timeline']);
        $firstUrl = '/settings/sources/'.$first->getId().'/remove';
        $secondUrl = '/settings/sources/'.$second->getId().'/remove';
        $this->client->request('GET', '/settings/sources/'.$this->source->getId().'/remove');
        self::assertResponseStatusCodeSame(404);
        $crawler = $this->client->request('GET', $firstUrl);
        self::assertResponseIsSuccessful();
        $firstToken = $crawler->filter('input[name="token"]')->attr('value');
        $this->client->request('POST', $secondUrl, ['token' => $firstToken]);
        self::assertResponseRedirects('/login');

        $other = $this->createSource($this->em, 'other');
        $this->client->loginUser($other->getUser(), 'main');
        $this->client->request('GET', $firstUrl);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(2, $repository->count(['source_type' => LocationSource::TYPE_GOOGLE_TIMELINE]));
        self::assertSame(2, $this->em->getRepository(LocationPoint::class)->count([]));
    }

    public function testTimelineDeletionWaitsForAnOngoingImport(): void
    {
        $this->loginOwner();
        $this->uploadTimeline('{"rawSignals":[{"position":{"LatLng":"11°, 21°","timestamp":"2025-01-01T00:00:00Z"}}]}');
        self::assertResponseRedirects('/settings/sources/', 303);
        $crawler = $this->client->followRedirect();
        $this->client->click($crawler->selectLink('Delete')->link());
        $lock = static::getContainer()->get(LockFactory::class)->createLock(GoogleTimelineImporter::OWNER_LOCK_PREFIX.$this->source->getUser()->getId());
        self::assertTrue($lock->acquire());

        try {
            $this->client->submitForm('Delete source');
            self::assertResponseStatusCodeSame(409);
            self::assertSelectorTextContains('[role="alert"]', 'Another Google Timeline operation is in progress');
            self::assertSame(1, $this->em->getRepository(LocationPoint::class)->count([]));
        } finally {
            $lock->release();
        }
    }

    #[DataProvider('invalidTimelineUploads')]
    public function testInvalidTimelineUploadDoesNotCreateSource(?string $content, array $fields, string $error): void
    {
        $this->loginOwner();
        $this->uploadTimeline($content, $fields);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', $error);
        self::assertSame(1, $this->em->getRepository(LocationSource::class)->count([]));
        self::assertSame(0, $this->em->getRepository(LocationPoint::class)->count([]));
    }

    public static function invalidTimelineUploads(): iterable
    {
        $valid = '{"rawSignals":[{"position":{"LatLng":"0°, 0°","timestamp":"2025-01-01T00:00:00Z"}}]}';

        yield 'missing file' => [null, [], 'Select a Timeline JSON file.'];
        yield 'invalid CSRF' => [$valid, ['form[_token]' => 'invalid'], 'The CSRF token is invalid.'];
        yield 'different export format' => ['{"locations":[]}', [], 'Select a Timeline JSON file exported from Google Maps on Android.'];
        yield 'empty timeline' => ['{"semanticSegments":[],"rawSignals":[]}', [], 'The Timeline JSON contains no locations'];
        yield 'invalid coordinates' => [str_replace('0°, 0°', '91°, 0°', $valid), [], 'The Timeline JSON contains no locations'];
        yield 'invalid date' => [str_replace('2025-01-01', '2025-02-30', $valid), [], 'The Timeline JSON contains no locations'];
        yield 'invalid segment syntax' => ['{"semanticSegments":[{"timelinePath" []}]}', [], 'The uploaded file is not valid Timeline JSON.'];
        yield 'trailing data' => [$valid.' false', [], 'The uploaded file is not valid Timeline JSON.'];
    }

    private function uploadTimeline(?string $content, array $fields = []): void
    {
        $crawler = $this->client->request('GET', '/settings/sources/new');
        self::assertSelectorCount(1, 'form');
        $form = $crawler->selectButton('Save')->form(array_replace([
            'form[name]' => 'Android history',
            'form[source_type]' => (string) LocationSource::TYPE_GOOGLE_TIMELINE,
        ], $fields));
        self::assertSame('multipart/form-data', $form->getNode()->getAttribute('enctype'));
        $path = null;

        try {
            $files = [];
            if (null !== $content) {
                $path = tempnam(sys_get_temp_dir(), 'timeline-test-');
                file_put_contents($path, $content);
                $files['form']['file'] = new UploadedFile($path, 'Timeline.json', 'application/json', test: true);
            }

            $this->client->request('POST', '/settings/sources/new', $form->getPhpValues(), $files);
        } finally {
            if (null !== $path) {
                unlink($path);
            }
        }
    }

    public function testDisableRevokesCredentialsPreservesHistoryAndUpdatesList(): void
    {
        $sourceId = $this->source->getId();
        // Authenticate successfully first, then try the same credentials after disabling.
        $credentials = ['CONTENT_TYPE' => 'application/json', 'PHP_AUTH_USER' => 'phone', 'PHP_AUTH_PW' => 'submit-password'];
        $this->client->request('POST', '/api/v1/submit/owntracks', server: $credentials,
            content: '{"_type":"location","tst":1700000000,"lat":35,"lon":139}');
        self::assertResponseIsSuccessful();
        $this->loginOwner();
        $token = $this->disableToken();
        $this->client->request('POST', '/settings/sources/'.$sourceId.'/delete', ['token' => $token]);
        self::assertResponseRedirects('/settings/sources/');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('tbody tr', 'No');
        self::assertSelectorNotExists('input[value="Disable"]');
        $disabled = $this->reloadSource();
        self::assertNotNull($disabled->getDeletedAt());
        self::assertNull($disabled->getAuthUsername());
        self::assertNull($disabled->getPassword());
        self::assertSame([], $disabled->getRoles());
        self::assertCount(1, $disabled->getRawRecords());
        self::assertCount(1, $disabled->getLocationPoints());

        $this->client->request('POST', '/api/v1/submit/owntracks', server: $credentials,
            content: '{"_type":"location","tst":1700000001,"lat":36,"lon":140}');
        self::assertResponseStatusCodeSame(401);
        self::assertSame(1, $this->em->getRepository(RawRecord::class)->count([]));
        self::assertSame(1, $this->em->getRepository(LocationPoint::class)->count([]));
    }

    #[DataProvider('invalidTokens')]
    public function testDisableRequiresValidCsrfToken(array $parameters): void
    {
        $this->loginOwner();
        $this->client->request('POST', '/settings/sources/'.$this->source->getId().'/delete', $parameters);
        // IsCsrfTokenValid raises an authentication exception in Symfony 8.1.
        self::assertResponseRedirects('/login');
        $source = $this->reloadSource();
        self::assertNull($source->getDeletedAt());
        self::assertSame('phone', $source->getAuthUsername());
        self::assertNotNull($source->getPassword());
    }

    public static function invalidTokens(): iterable
    {
        yield 'missing token' => [[]];
        yield 'invalid token' => [['token' => 'invalid']];
    }

    public function testOwnerCannotDisableAnotherOwnersSource(): void
    {
        $other = $this->createSource($this->em, 'other');
        $otherId = $other->getId();
        $this->loginOwner();
        $this->client->request('POST', '/settings/sources/'.$otherId.'/delete', ['token' => $this->disableToken()]);
        self::assertResponseStatusCodeSame(403);
        $other = $this->reloadSource($otherId);
        self::assertNull($other->getDeletedAt());
        self::assertSame('other', $other->getAuthUsername());
        self::assertNotNull($other->getPassword());
    }

    public function testDisableIsPostOnly(): void
    {
        $this->loginOwner();
        $this->client->request('GET', '/settings/sources/'.$this->source->getId().'/delete');
        self::assertResponseStatusCodeSame(405);
        self::assertNull($this->reloadSource()->getDeletedAt());
    }

    public function testMissingSourceCannotBeDisabled(): void
    {
        $this->loginOwner();
        $token = $this->disableToken();
        $this->client->request('POST', '/settings/sources/2147483647/delete', ['token' => $token]);
        self::assertResponseStatusCodeSame(404);
        self::assertNull($this->reloadSource()->getDeletedAt());
    }

    #[DataProvider('tamperedCreationFields')]
    public function testTamperedCreationIsRejected(array $changes): void
    {
        $this->loginOwner();
        $crawler = $this->client->request('GET', '/settings/sources/new');
        $form = $crawler->selectButton('Save')->form([
            'form[name]' => 'New phone', 'form[source_type]' => '1',
            'form[auth_username]' => 'new-phone', 'form[auth_password_hash]' => 'new-secret',
        ]);
        $values = $form->getPhpValues();
        $values['form'] = array_replace($values['form'], $changes);
        $this->client->request('POST', '/settings/sources/new', $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.invalid-feedback, .alert-danger, ul li');
        self::assertSame(1, $this->em->getRepository(LocationSource::class)->count([]));
    }

    public static function tamperedCreationFields(): iterable
    {
        yield 'unsupported source type' => [['source_type' => '999']];
        yield 'owner injected' => [['user' => '2147483647']];
    }

    public function testDisablingTwiceReturnsNotFoundWithoutChangingTimestamp(): void
    {
        $this->loginOwner();
        $token = $this->disableToken();
        $path = '/settings/sources/'.$this->source->getId().'/delete';
        $this->client->request('POST', $path, ['token' => $token]);
        self::assertResponseRedirects('/settings/sources/');
        $timestamp = $this->reloadSource()->getDeletedAt();
        $this->client->request('POST', $path, ['token' => $token]);
        self::assertResponseStatusCodeSame(404);
        self::assertEquals($timestamp, $this->reloadSource()->getDeletedAt());
    }
}
