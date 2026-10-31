<?php

namespace App\Tests\Application;

use App\Entity\LocationPoint;
use App\Entity\RawRecord;
use App\Tests\Support\DatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class OwnTracksIngestionTest extends WebTestCase
{
    use DatabaseTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // Keep the same kernel and entity manager across requests within a test.
        $this->client->disableReboot();
        $this->createSource($this->prepareDatabase());
    }

    private function submit(string $body, array $credentials = ['phone', 'submit-password']): void
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($credentials !== []) {
            [$server['PHP_AUTH_USER'], $server['PHP_AUTH_PW']] = $credentials;
        }
        $this->client->request('POST', '/api/v1/submit/owntracks', server: $server, content: $body);
    }

    private function records(): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return [$em->getRepository(RawRecord::class)->findAll(), $em->getRepository(LocationPoint::class)->findAll()];
    }

    public function testAuthenticatedLocationIsStoredWithItsSource(): void
    {
        $this->submit('{"lon":139.5,"_type":"location","tst":1700000000,"lat":35.5,"tid":"AB","vel":36,"batt":80}');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');
        self::assertSame('[]', $this->client->getResponse()->getContent());
        [$raws, $points] = $this->records();
        self::assertCount(1, $raws);
        self::assertCount(1, $points);
        $raw = $raws[0];
        self::assertSame('phone', $raw->getSource()->getAuthUsername());
        self::assertSame('owntracks_location', $raw->getRecordType());
        self::assertSame('done', $raw->getParseStatus());
        self::assertSame(1700000000, $raw->getRecordedAt()->getTimestamp());
        self::assertSame('{"_type":"location","batt":80,"lat":35.5,"lon":139.5,"tid":"AB","tst":1700000000,"vel":36}', $raw->getPayload());
        self::assertSame(hash('sha256', $raw->getPayload()), $raw->getPayloadHash());
        self::assertSame($raw->getId(), $points[0]->getRawRecord()->getId());
        self::assertSame($raw->getSource()->getId(), $points[0]->getSource()->getId());
        self::assertSame(35.5, $points[0]->getLatitude());
        self::assertSame(139.5, $points[0]->getLongitude());
        self::assertSame(36.0, $points[0]->getSpeedKmh());
        self::assertSame(80, $points[0]->getBatteryPercent());
        self::assertSame(['tid' => 'AB'], $points[0]->getMetadata());
    }

    #[DataProvider('invalidCredentials')]
    public function testBasicAuthenticationIsRequired(array $credentials): void
    {
        $this->submit('{"_type":"location","tst":1700000000,"lat":35,"lon":139}', $credentials);
        self::assertResponseStatusCodeSame(401);
        self::assertResponseHasHeader('www-authenticate');
        self::assertSame([[], []], $this->records());
    }

    public static function invalidCredentials(): iterable
    {
        yield 'missing credentials' => [[]];
        yield 'unknown source' => [['unknown', 'submit-password']];
        yield 'wrong password' => [['phone', 'wrong']];
        yield 'owner credentials cannot submit' => [['phone@example.com', 'owner-password']];
    }

    #[DataProvider('invalidPayloads')]
    public function testRejectsInvalidPayloadWithoutStoringIt(string $payload, string $error): void
    {
        $this->submit($payload);
        self::assertResponseStatusCodeSame(400);
        self::assertSame(['error' => $error], json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
        self::assertSame([[], []], $this->records());
    }

    public static function invalidPayloads(): iterable
    {
        yield 'empty body' => ['', 'Invalid JSON'];
        yield 'malformed JSON' => ['{"_type":', 'Invalid JSON'];
        yield 'invalid UTF8' => ["{\"_type\":\"\xB1\"}", 'Invalid JSON'];
        foreach (['null', 'true', '42', '"location"', '[]', '[{"_type":"location"}]'] as $json) {
            yield 'non object '.$json => [$json, 'Expected JSON object'];
        }
        foreach (['{"lat":35}', '{"_type":null}', '{"_type":1}', '{"_type":[]}', '{"_type":""}'] as $json) {
            yield 'missing type '.$json => [$json, 'Missing _type'];
        }
    }

    public function testInvalidLocationIsRetainedForDiagnosisWithoutCreatingPoint(): void
    {
        $this->submit('{"_type":"location","tst":1700000000,"lat":91,"lon":139}');
        self::assertResponseIsSuccessful();
        [$raws, $points] = $this->records();
        self::assertCount(1, $raws);
        self::assertSame([], $points);
        self::assertSame('error', $raws[0]->getParseStatus());
        self::assertSame('Records: `tst`, `lat`, and `lon` are invalid.', $raws[0]->getParseError());
        self::assertNotNull($raws[0]->getParsedAt());
    }

    public function testNonLocationMessageIsRetainedWithoutCreatingPoint(): void
    {
        $this->submit('{"_type":"transition","event":"enter","desc":"home","tst":1700000000}');
        self::assertResponseIsSuccessful();
        [$raws, $points] = $this->records();
        self::assertCount(1, $raws);
        self::assertSame([], $points);
        self::assertSame('owntracks_transition', $raws[0]->getRecordType());
        self::assertSame('pending', $raws[0]->getParseStatus());
        self::assertNull($raws[0]->getParsedAt());
    }

    public function testReorderedNestedObjectKeysAreDeduplicated(): void
    {
        $this->submit('{"_type":"location","tst":1700000000,"lat":35,"lon":139,"extra":{"z":1,"a":{"y":2,"b":3}},"regions":["home","work"]}');
        self::assertResponseIsSuccessful();
        $this->submit('{ "regions":["home","work"], "extra":{"a":{"b":3,"y":2},"z":1},"lon":139,"lat":35,"tst":1700000000,"_type":"location" }');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['error' => 'Record already exists'], json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
        [$raws, $points] = $this->records();
        self::assertCount(1, $raws);
        self::assertCount(1, $points);
    }

    public function testListOrderAndFractionalNumbersArePreserved(): void
    {
        foreach (['["home","work"]', '["work","home"]'] as $regions) {
            $this->submit('{"_type":"location","tst":1700000000,"lat":35.0,"lon":139.0,"regions":'.$regions.'}');
            self::assertResponseIsSuccessful();
        }
        [$raws, $points] = $this->records();
        self::assertCount(2, $raws);
        self::assertCount(2, $points);
        self::assertStringContainsString('"lat":35.0', $raws[0]->getPayload());
        self::assertNotSame($raws[0]->getPayloadHash(), $raws[1]->getPayloadHash());
    }

    public function testRouteRejectsGet(): void
    {
        $this->client->request('GET', '/api/v1/submit/owntracks');
        self::assertResponseStatusCodeSame(405);
        self::assertSame([[], []], $this->records());
    }
}
