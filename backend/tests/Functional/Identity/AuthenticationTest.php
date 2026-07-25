<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Tests\Functional\DatabaseTestCase;

final class AuthenticationTest extends DatabaseTestCase
{
    public function testMeRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/me');

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
    }

    public function testLoginThenMeReturnsTheCurrentUser(): void
    {
        $this->login('alice');

        $this->client->request('GET', '/api/me');

        self::assertResponseIsSuccessful();

        /** @var array{username: string, display_name: string, id: string} $body */
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame('alice', $body['username']);
        self::assertSame('Alice', $body['display_name']);
        self::assertMatchesRegularExpression('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/', $body['id']);
    }

    public function testLoginWithWrongPasswordIsRejected(): void
    {
        $this->client->request(
            'POST',
            '/api/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['username' => 'alice', 'password' => 'mauvais'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
    }

    /** L'annuaire sert a choisir un interlocuteur, pas a exposer les comptes. */
    public function testUsersDirectoryListsEveryoneButNeverLeaksSecrets(): void
    {
        $this->login('alice');

        $this->client->request('GET', '/api/users');

        self::assertResponseIsSuccessful();

        $raw = (string) $this->client->getResponse()->getContent();

        self::assertStringNotContainsString('password', $raw);
        self::assertStringNotContainsString('@example.test', $raw);

        /** @var list<array{username: string}> $body */
        $body = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);

        self::assertCount(3, $body);
    }

    public function testTheDirectoryIsClosedToAnonymousCallers(): void
    {
        $this->client->request('GET', '/api/users');

        self::assertResponseStatusCodeSame(401);
    }

    protected function login(string $username): void
    {
        $this->client->request(
            'POST',
            '/api/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['username' => $username, 'password' => 'password'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
    }
}
