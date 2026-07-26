<?php

declare(strict_types=1);

namespace App\Tests\Functional\Realtime;

use App\Tests\Functional\DatabaseTestCase;
use App\Tests\Support\InMemoryEventPublisher;

final class TypingTest extends DatabaseTestCase
{
    /** Un non-membre recoit 404, jamais 403 : un 403 confirmerait l'existence du fil. */
    public function testANonMemberGetsNotFound(): void
    {
        $this->login('alice');
        $conversationId = $this->createDirectWith('bob');

        $this->login('carol');
        $this->client->request('POST', sprintf('/api/conversations/%s/typing', $conversationId));

        self::assertResponseStatusCodeSame(404);
    }

    public function testAMemberPublishesTypingOnTheConversationTopic(): void
    {
        $this->login('alice');
        $aliceId = $this->userId('alice');
        $conversationId = $this->createDirectWith('bob');

        $publisher = static::getContainer()->get(InMemoryEventPublisher::class);

        $this->client->request('POST', sprintf('/api/conversations/%s/typing', $conversationId));
        self::assertResponseStatusCodeSame(204);

        $typing = array_values(array_filter(
            $publisher->published(),
            static fn(array $entry): bool => 'typing.started' === $entry['type'],
        ));

        self::assertCount(1, $typing);
        self::assertSame(sprintf('/conversations/%s', $conversationId), $typing[0]['topic']);
        self::assertSame(
            ['conversation_id' => $conversationId, 'user_id' => $aliceId],
            $typing[0]['payload'],
        );
        // Aucun identifiant SSE : rejouer une frappe terminee n'a aucun sens.
        self::assertNull($typing[0]['id']);
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
