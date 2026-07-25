<?php

declare(strict_types=1);

namespace App\Tests\Functional\Realtime;

use App\Tests\Functional\DatabaseTestCase;

final class RealtimeTokenTest extends DatabaseTestCase
{
    /**
     * Le cookie est limite au chemin du hub : il ne part donc PAS avec les
     * appels a /api. Le chercher sur '/' ne le trouverait jamais.
     */
    private const string HUB_PATH = '/.well-known/mercure';

    public function testTokenEndpointRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/realtime/token');

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * Identity ne connait pas Mercure : la contrainte inter-contextes a
     * supprime le besoin plutot que de le contourner. C'est le front qui
     * appelle /api/realtime/token juste apres, puisqu'il lui faut de toute
     * facon la liste des topics.
     */
    public function testLoginAloneDoesNotSetTheMercureCookie(): void
    {
        $this->login('alice');

        self::assertNull($this->client->getCookieJar()->get('mercureAuthorization', self::HUB_PATH));
    }

    public function testTokenEndpointSetsTheCookieAndReturnsTheTopics(): void
    {
        $this->login('alice');

        $this->client->request('GET', '/api/realtime/token');

        self::assertResponseIsSuccessful();

        $cookie = $this->client->getCookieJar()->get('mercureAuthorization', self::HUB_PATH);

        self::assertNotNull($cookie);
        // Le front ne doit jamais pouvoir lire le jeton en JavaScript.
        self::assertTrue($cookie->isHttpOnly());

        $body = $this->json();

        self::assertIsString($body['hub_url']);
        self::assertStringContainsString('/.well-known/mercure', $body['hub_url']);

        /** @var list<string> $topics */
        $topics = $body['topics'];

        $personalTopics = array_filter(
            $topics,
            static fn(string $topic): bool => str_ends_with($topic, '/system'),
        );

        self::assertCount(1, $personalTopics, 'Le topic personnel doit toujours etre present.');
        self::assertGreaterThan(1, count($topics), 'Alice est aussi membre de conversations.');
    }

    /** Un utilisateur ne doit jamais recevoir les topics d'un autre. */
    public function testTopicsAreScopedToTheCurrentUser(): void
    {
        $this->login('carol');

        $this->client->request('GET', '/api/realtime/token');

        /** @var list<string> $topics */
        $topics = $this->json()['topics'];

        // Carol n'est membre que du groupe, pas du direct Alice-Bob.
        self::assertCount(2, $topics);
    }
}
