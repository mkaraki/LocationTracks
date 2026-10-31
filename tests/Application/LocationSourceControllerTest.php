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
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class LocationSourceControllerTest extends WebTestCase
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
        self::assertSelectorExists('.invalid-feedback, ul li');
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
