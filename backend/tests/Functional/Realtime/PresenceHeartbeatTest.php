<?php

declare(strict_types=1);

namespace App\Tests\Functional\Realtime;

use App\Tests\Functional\DatabaseTestCase;

final class PresenceHeartbeatTest extends DatabaseTestCase
{
    /**
     * Redis n'est PAS transactionnel : le rollback de DatabaseTestCase ne
     * l'atteint pas, et une cle de presence vit 30 s. Sans ce nettoyage, un
     * test verrait la presence laissee par le precedent — un echec qui
     * n'apparaitrait qu'en suite complete, jamais en `--filter`.
     */
    protected function setUp(): void
    {
        parent::setUp();

        /** @var \Redis $redis */
        $redis = static::getContainer()->get(\Redis::class);
        $redis->flushDb();
    }

    public function testHeartbeatRequiresAuthentication(): void
    {
        $this->client->request('POST', '/api/presence/heartbeat');

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * Le battement doit renvoyer la presence, pas seulement l'enregistrer :
     * c'est ce qui evite un second aller-retour toutes les 20 s.
     */
    public function testHeartbeatReturnsTheOnlinePeers(): void
    {
        $this->login('alice');
        $bobId = $this->userId('bob');
        // La conversation est creee par le test et non supposee dans les
        // fixtures : c'est elle qui fait de Bob un « pair » d'Alice.
        $this->createDirectWith('bob');

        $this->client->request('POST', '/api/presence/heartbeat');
        self::assertResponseIsSuccessful();

        // Alice seule a battu : personne d'autre n'est en ligne.
        self::assertSame(['online_user_ids' => []], $this->json());

        // Bob bat a son tour, dans sa propre session.
        $this->login('bob');
        $this->client->request('POST', '/api/presence/heartbeat');
        self::assertResponseIsSuccessful();

        // Alice revoit la presence de Bob, avec qui elle partage un fil.
        $this->login('alice');
        $this->client->request('POST', '/api/presence/heartbeat');

        /** @var array{online_user_ids: list<string>} $body */
        $body = $this->json();
        self::assertContains($bobId, $body['online_user_ids']);
    }

    /** On ne se voit jamais soi-meme dans la liste : la pastille ne s'affiche pas sur soi. */
    public function testTheCallerIsNeverListedAmongThePeers(): void
    {
        $this->login('alice');
        $aliceId = $this->userId('alice');
        $this->createDirectWith('bob');

        $this->client->request('POST', '/api/presence/heartbeat');

        /** @var array{online_user_ids: list<string>} $body */
        $body = $this->json();
        self::assertNotContains($aliceId, $body['online_user_ids']);
    }

    private function createDirectWith(string $username): string
    {
        $peerId = $this->userId($username);

        $this->client->request(
            'POST',
            '/api/conversations',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(
                ['type' => 'direct', 'member_ids' => [$peerId]],
                \JSON_THROW_ON_ERROR,
            ),
        );

        /** @var array{id: string} $body */
        $body = $this->json();

        return $body['id'];
    }
}
