<?php

declare(strict_types=1);

namespace App\Tests\Functional\Conversation;

use App\Tests\Functional\DatabaseTestCase;

final class AdvanceReceiptsTest extends DatabaseTestCase
{
    private const string OLDER = '01J9ZQ7X8K3M4N5P6Q7R8S9TA0';
    private const string NEWER = '01J9ZQ7X8K3M4N5P6Q7R8S9TA9';

    public function testANonMemberGetsNotFound(): void
    {
        $this->login('alice');
        $conversationId = $this->createDirectWith('bob');

        $this->login('carol');
        $this->postReceipts($conversationId, ['read_up_to' => self::NEWER]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testAMalformedWatermarkIsRejected(): void
    {
        $this->login('alice');
        $conversationId = $this->createDirectWith('bob');

        $this->postReceipts($conversationId, ['read_up_to' => 'pas-un-ulid']);

        self::assertResponseStatusCodeSame(422);
    }

    public function testTheWatermarkIsStoredAndNeverMovesBack(): void
    {
        $this->login('alice');
        $aliceId = $this->userId('alice');
        $conversationId = $this->createDirectWith('bob');

        $this->postReceipts($conversationId, ['read_up_to' => self::NEWER]);
        self::assertResponseStatusCodeSame(204);

        $this->postReceipts($conversationId, ['read_up_to' => self::OLDER]);
        self::assertResponseStatusCodeSame(204);

        $stored = $this->connection->fetchOne(
            <<<'SQL'
                SELECT last_read_message_id
                FROM conversation_members
                WHERE conversation_id = :conversation_id AND user_id = :user_id
                SQL,
            ['conversation_id' => $conversationId, 'user_id' => $aliceId],
        );

        self::assertSame(self::NEWER, $stored, 'Un watermark ne recule jamais.');
    }

    public function testBothCursorsCanAdvanceInOneCall(): void
    {
        $this->login('alice');
        $aliceId = $this->userId('alice');
        $conversationId = $this->createDirectWith('bob');

        $this->postReceipts($conversationId, [
            'delivered_up_to' => self::NEWER,
            'read_up_to' => self::OLDER,
        ]);
        self::assertResponseStatusCodeSame(204);

        /** @var array{last_delivered_message_id: string, last_read_message_id: string} $row */
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT last_delivered_message_id, last_read_message_id
                FROM conversation_members
                WHERE conversation_id = :conversation_id AND user_id = :user_id
                SQL,
            ['conversation_id' => $conversationId, 'user_id' => $aliceId],
        );

        self::assertSame(self::NEWER, $row['last_delivered_message_id']);
        self::assertSame(self::OLDER, $row['last_read_message_id']);
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
