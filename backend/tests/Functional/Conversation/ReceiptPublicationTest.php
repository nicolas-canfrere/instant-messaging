<?php

declare(strict_types=1);

namespace App\Tests\Functional\Conversation;

use App\Tests\Functional\DatabaseTestCase;
use App\Tests\Support\InMemoryEventPublisher;

final class ReceiptPublicationTest extends DatabaseTestCase
{
    private const string NEWER = '01J9ZQ7X8K3M4N5P6Q7R8S9TA9';

    public function testAdvancingAWatermarkPublishesOnTheConversationTopic(): void
    {
        $this->login('alice');
        $aliceId = $this->userId('alice');
        $conversationId = $this->createDirectWith('bob');

        $publisher = static::getContainer()->get(InMemoryEventPublisher::class);

        $this->postReceipts($conversationId, ['read_up_to' => self::NEWER]);

        $receipts = array_values(array_filter(
            $publisher->published(),
            static fn(array $entry): bool => 'receipt.updated' === $entry['type'],
        ));

        self::assertCount(1, $receipts);
        self::assertSame(sprintf('/conversations/%s', $conversationId), $receipts[0]['topic']);
        self::assertSame(
            [
                'conversation_id' => $conversationId,
                'user_id' => $aliceId,
                'last_delivered_message_id' => null,
                'last_read_message_id' => self::NEWER,
            ],
            $receipts[0]['payload'],
        );
    }

    /** Le pendant exact du test d'idempotence de l'envoi : le rejeu ne republie rien. */
    public function testReplayingTheSameWatermarkPublishesNothing(): void
    {
        $this->login('alice');
        $conversationId = $this->createDirectWith('bob');

        $publisher = static::getContainer()->get(InMemoryEventPublisher::class);

        $this->postReceipts($conversationId, ['read_up_to' => self::NEWER]);
        $this->postReceipts($conversationId, ['read_up_to' => self::NEWER]);

        $receipts = array_filter(
            $publisher->published(),
            static fn(array $entry): bool => 'receipt.updated' === $entry['type'],
        );

        self::assertCount(1, $receipts, 'Un watermark deja atteint ne doit rien republier.');
    }

    /** @param array<string, string> $body */
    private function postReceipts(string $conversationId, array $body): void
    {
        $this->client->request(
            'POST',
            sprintf('/api/conversations/%s/receipts', $conversationId),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(204);
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
