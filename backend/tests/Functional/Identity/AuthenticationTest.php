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
        self::assertSame('/problems/authentication-required', $this->json()['type']);
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

    /**
     * 204 et non la redirection par defaut du pare-feu : le client est une
     * application JavaScript, pas un navigateur qui suit des liens. Un 302 vers
     * `/` renverrait le HTML de l'app, que `fetch` suivrait avant d'echouer a le
     * lire en JSON — une deconnexion reussie qui remonte en erreur.
     */
    public function testLogoutEndsTheSessionWithoutRedirecting(): void
    {
        $this->login('alice');

        $this->client->request('POST', '/api/logout');

        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/me');

        self::assertResponseStatusCodeSame(401);
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

        $problem = $this->json();

        self::assertSame('/problems/invalid-credentials', $problem['type']);
        // Le detail ne dit jamais laquelle des deux valeurs est fausse : ce
        // serait un oracle pour enumerer les comptes existants.
        self::assertIsString($problem['detail']);
        self::assertStringNotContainsString('alice', $problem['detail']);
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
}
