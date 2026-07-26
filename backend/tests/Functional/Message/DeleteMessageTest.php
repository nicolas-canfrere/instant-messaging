<?php

declare(strict_types=1);

namespace App\Tests\Functional\Message;

use App\Tests\Functional\DatabaseTestCase;
use App\Tests\Support\InMemoryEventPublisher;

final class DeleteMessageTest extends DatabaseTestCase
{
    private const string CLIENT_ID = '01J9ZQ7X8K3M4N5P6Q7R8S9TC1';

    /** LE test de la tranche : la charge utile est reellement effacee. */
    public function testDeletingErasesTheContentInTheDatabase(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();
        $messageId = $this->send($conversationId, self::CLIENT_ID, 'a effacer');

        $this->client->request('DELETE', sprintf('/api/conversations/%s/messages/%s', $conversationId, $messageId));

        self::assertResponseStatusCodeSame(204);

        $content = $this->connection->fetchOne(
            'SELECT content FROM messages WHERE id = :id',
            ['id' => $messageId],
        );

        self::assertNull($content, 'Le contenu doit etre efface, pas masque.');
    }

    public function testTheTombstoneStaysInTheHistoryWithoutContent(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();
        $messageId = $this->send($conversationId, self::CLIENT_ID, 'a effacer');

        $this->client->request('DELETE', sprintf('/api/conversations/%s/messages/%s', $conversationId, $messageId));
        $this->client->request('GET', sprintf('/api/conversations/%s/messages', $conversationId));

        /** @var array{items: list<array{id: string, content: string|null, deleted_at: string|null}>} $page */
        $page = $this->json();

        $found = null;
        foreach ($page['items'] as $item) {
            if ($item['id'] === $messageId) {
                $found = $item;
            }
        }

        self::assertNotNull($found, 'Le tombstone doit rester dans l\'historique : les watermarks le designent.');
        self::assertNull($found['content']);
        self::assertNotNull($found['deleted_at']);
    }

    public function testDeletingTwiceAnswers204AndPublishesOnlyOnce(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();
        $messageId = $this->send($conversationId, self::CLIENT_ID, 'a effacer');

        $publisher = static::getContainer()->get(InMemoryEventPublisher::class);

        $path = sprintf('/api/conversations/%s/messages/%s', $conversationId, $messageId);

        $this->client->request('DELETE', $path);
        self::assertResponseStatusCodeSame(204);

        $this->client->request('DELETE', $path);
        self::assertResponseStatusCodeSame(204);

        $deleted = array_filter(
            $publisher->published(),
            static fn(array $entry): bool => 'message.deleted' === $entry['type'],
        );

        self::assertCount(1, $deleted, 'Le rejeu ne doit rien republier.');
    }

    /** L'evenement de retractation ne transporte pas ce qu'il retracte. */
    public function testThePublishedEventCarriesNoContentAndNoEventId(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();
        $messageId = $this->send($conversationId, self::CLIENT_ID, 'secret');

        $publisher = static::getContainer()->get(InMemoryEventPublisher::class);

        $this->client->request('DELETE', sprintf('/api/conversations/%s/messages/%s', $conversationId, $messageId));

        $deleted = array_values(array_filter(
            $publisher->published(),
            static fn(array $entry): bool => 'message.deleted' === $entry['type'],
        ));

        self::assertCount(1, $deleted);
        self::assertSame(sprintf('/conversations/%s', $conversationId), $deleted[0]['topic']);
        self::assertArrayNotHasKey('content', $deleted[0]['payload']);
        self::assertNull($deleted[0]['id'], 'Un id SSE qui recule casserait Last-Event-ID.');
    }

    public function testAnotherMemberCannotDeleteMyMessage(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();
        $messageId = $this->send($conversationId, self::CLIENT_ID, 'a moi');

        $this->login('bob');
        $this->client->request('DELETE', sprintf('/api/conversations/%s/messages/%s', $conversationId, $messageId));

        self::assertResponseStatusCodeSame(403);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');

        /** @var array{type: string, title: string, status: int, correlation_id: string} $problem */
        $problem = $this->json();
        self::assertSame('/problems/not-the-author', $problem['type']);
    }

    /** Pas d'oracle : un identifiant inconnu et un message d'un autre fil sont indistinguables. */
    public function testAMessageFromAnotherConversationIsNotFound(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();
        $messageId = $this->send($conversationId, self::CLIENT_ID, 'ailleurs');

        $otherConversationId = $this->secondConversationId();

        $this->client->request(
            'DELETE',
            sprintf('/api/conversations/%s/messages/%s', $otherConversationId, $messageId),
        );
        self::assertResponseStatusCodeSame(404);
        /** @var array{type: string, title: string} $wrongConversation */
        $wrongConversation = $this->json();

        $this->client->request(
            'DELETE',
            sprintf('/api/conversations/%s/messages/%s', $otherConversationId, '01J9ZQ7X8K3M4N5P6Q7R8S9TZZ'),
        );
        self::assertResponseStatusCodeSame(404);
        /** @var array{type: string, title: string} $unknown */
        $unknown = $this->json();

        self::assertSame($unknown['type'], $wrongConversation['type']);
        self::assertSame($unknown['title'], $wrongConversation['title']);
    }

    public function testItRequiresASession(): void
    {
        $this->client->request(
            'DELETE',
            '/api/conversations/01J9ZQ7X8K3M4N5P6Q7R8S9TZ1/messages/01J9ZQ7X8K3M4N5P6Q7R8S9TZ2',
        );

        self::assertResponseStatusCodeSame(401);
    }

    public function testDeletingTheLastMessageClearsThePreview(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();
        $messageId = $this->send($conversationId, self::CLIENT_ID, 'dernier');

        $this->client->request('DELETE', sprintf('/api/conversations/%s/messages/%s', $conversationId, $messageId));

        self::assertNull(
            $this->previewOf($conversationId),
            'Laisser l\'apercu rendrait « payload hard » faux.',
        );
    }

    /** La garde du WHERE : seul le message qui EST le pointeur touche l'apercu. */
    public function testDeletingAnOlderMessageLeavesThePreviewAlone(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();

        $olderId = $this->send($conversationId, '01J9ZQ7X8K3M4N5P6Q7R8S9TC2', 'ancien');
        $this->send($conversationId, '01J9ZQ7X8K3M4N5P6Q7R8S9TC3', 'recent');

        $this->client->request('DELETE', sprintf('/api/conversations/%s/messages/%s', $conversationId, $olderId));

        self::assertSame('recent', $this->previewOf($conversationId));
    }

    private function previewOf(string $conversationId): ?string
    {
        $this->client->request('GET', '/api/conversations');

        /** @var list<array{id: string, last_message_preview: string|null}> $conversations */
        $conversations = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        foreach ($conversations as $conversation) {
            if ($conversation['id'] === $conversationId) {
                return $conversation['last_message_preview'];
            }
        }

        self::fail('Conversation absente de la liste.');
    }
}
