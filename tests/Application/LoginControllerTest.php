<?php

namespace App\Tests\Application;

use App\Tests\Support\DatabaseTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class LoginControllerTest extends WebTestCase
{
    use DatabaseTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->createSource($this->prepareDatabase());
    }

    #[DataProvider('invalidCredentials')]
    public function testInvalidCredentialsReturnGenericError(string $email, string $password): void
    {
        $this->client->request('GET', '/login');
        self::assertResponseIsSuccessful();
        $this->client->submitForm('Sign in', ['_username' => $email, '_password' => $password]);
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-danger', 'Invalid credentials.');
    }

    public static function invalidCredentials(): iterable
    {
        yield 'unknown email' => ['unknown@example.com', 'owner-password'];
        yield 'wrong password' => ['phone@example.com', 'wrong'];
    }

    public function testValidCredentialsAuthenticateOwner(): void
    {
        $this->client->request('GET', '/login');
        self::assertResponseIsSuccessful();
        $this->client->submitForm('Sign in', ['_username' => 'phone@example.com', '_password' => 'owner-password']);
        self::assertResponseRedirects('/');
        // No map/home route exists yet; inspect the authenticated session on /login.
        $this->client->request('GET', '/login');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'You are logged in as phone@example.com');
        self::assertSelectorNotExists('.alert-danger');
    }

    public function testLoginRejectsInvalidCsrfToken(): void
    {
        $this->client->request('POST', '/login', [
            '_username' => 'phone@example.com', '_password' => 'owner-password', '_csrf_token' => 'invalid',
        ]);
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Invalid CSRF token.');
        self::assertSelectorTextNotContains('body', 'You are logged in as');
    }
}
