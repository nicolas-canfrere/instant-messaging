<?php

declare(strict_types=1);

namespace App\Tests\Functional\Conversation;

use App\Tests\Functional\DatabaseTestCase;

final class UnreadCountTest extends DatabaseTestCase
{
    public function testMyOwnMessagesAreNeverCountedAsUnread(): void
    {
        $this->login('alice');
        $conversationId = $this->createDirectWith('bob');

        $this->send($conversationId, '01J9ZQ7X8K3M4N5P6Q7R8S9TC1', 'coucou');

        self::assertSame(0, $this->unreadCountFor($conversationId));
    }

    public function testAMessageFromSomeoneElseIsUnreadUntilTheWatermarkPasses(): void
    {
        $this->login('alice');
        $conversationId = $this->createDirectWith('bob');

        $this->login('bob');
        $messageId = $this->send($conversationId, '01J9ZQ7X8K3M4N5P6Q7R8S9TC2', 'salut');

        $this->login('alice');
        self::assertSame(1, $this->unreadCountFor($conversationId));

        $this->client->request(
            'POST',
            sprintf('/api/conversations/%s/receipts', $conversationId),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['read_up_to' => $messageId], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(204);

        self::assertSame(0, $this->unreadCountFor($conversationId));
    }

    /** Une conversation sans aucun message doit rendre 0, pas disparaitre de la liste. */
    public function testAnEmptyConversationReportsZero(): void
    {
        $this->login('alice');
        $conversationId = $this->createDirectWith('bob');

        self::assertSame(0, $this->unreadCountFor($conversationId));
    }

    private function unreadCountFor(string $conversationId): int
    {
        $this->client->request('GET', '/api/conversations');
        self::assertResponseIsSuccessful();

        /** @var list<array{id: string, unread_count: int}> $conversations */
        $conversations = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        foreach ($conversations as $conversation) {
            if ($conversation['id'] === $conversationId) {
                return $conversation['unread_count'];
            }
        }

        self::fail('Conversation absente de la liste.');
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
