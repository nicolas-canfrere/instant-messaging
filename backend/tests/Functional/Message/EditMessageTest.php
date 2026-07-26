<?php

declare(strict_types=1);

namespace App\Tests\Functional\Message;

use App\Tests\Functional\DatabaseTestCase;
use App\Tests\Support\InMemoryEventPublisher;

final class EditMessageTest extends DatabaseTestCase
{
    private const string CLIENT_ID = '01J9ZQ7X8K3M4N5P6Q7R8S9TE1';

    public function testEditingReturnsTheUpdatedView(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();
        $messageId = $this->send($conversationId, self::CLIENT_ID, 'bonjor');

        $this->edit($conversationId, $messageId, 'bonjour');

        self::assertResponseStatusCodeSame(200);

        /** @var array{id: string, content: string|null, edited_at: string|null} $view */
        $view = $this->json();

        self::assertSame($messageId, $view['id']);
        self::assertSame('bonjour', $view['content']);
        self::assertNotNull($view['edited_at']);
    }

    public function testEditingTheLastMessageRefreshesThePreview(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();
        $messageId = $this->send($conversationId, self::CLIENT_ID, 'bonjor');

        $this->edit($conversationId, $messageId, 'bonjour');

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
                self::assertSame('bonjour', $conversation['last_message_preview']);

                return;
            }
        }

        self::fail('Conversation absente de la liste.');
    }

    public function testEditingAnOlderMessageLeavesThePreviewAlone(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();

        $olderId = $this->send($conversationId, '01J9ZQ7X8K3M4N5P6Q7R8S9TE2', 'ancien');
        $this->send($conversationId, '01J9ZQ7X8K3M4N5P6Q7R8S9TE3', 'recent');

        $this->edit($conversationId, $olderId, 'ancien corrige');

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
                self::assertSame('recent', $conversation['last_message_preview']);

                return;
            }
        }

        self::fail('Conversation absente de la liste.');
    }

    public function testThePublishedEventCarriesTheNewContentWithoutAnEventId(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();
        $messageId = $this->send($conversationId, self::CLIENT_ID, 'bonjor');

        $publisher = static::getContainer()->get(InMemoryEventPublisher::class);

        $this->edit($conversationId, $messageId, 'bonjour');

        $edited = array_values(array_filter(
            $publisher->published(),
            static fn(array $entry): bool => 'message.edited' === $entry['type'],
        ));

        self::assertCount(1, $edited);
        self::assertSame(sprintf('/conversations/%s', $conversationId), $edited[0]['topic']);
        self::assertSame('bonjour', $edited[0]['payload']['content']);
        self::assertNull($edited[0]['id']);
    }

    public function testEditingATombstoneConflicts(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();
        $messageId = $this->send($conversationId, self::CLIENT_ID, 'a effacer');

        $this->client->request('DELETE', sprintf('/api/conversations/%s/messages/%s', $conversationId, $messageId));

        $this->edit($conversationId, $messageId, 'ressusciter');

        self::assertResponseStatusCodeSame(409);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');

        /** @var array{type: string} $problem */
        $problem = $this->json();
        self::assertSame('/problems/message-already-deleted', $problem['type']);
    }

    public function testAnotherMemberCannotEditMyMessage(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();
        $messageId = $this->send($conversationId, self::CLIENT_ID, 'a moi');

        $this->login('bob');
        $this->edit($conversationId, $messageId, 'pas a moi');

        self::assertResponseStatusCodeSame(403);

        /** @var array{type: string} $problem */
        $problem = $this->json();
        self::assertSame('/problems/not-the-author', $problem['type']);
    }

    public function testAnEmptyContentIsRejectedWithViolations(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();
        $messageId = $this->send($conversationId, self::CLIENT_ID, 'bonjour');

        $this->edit($conversationId, $messageId, '   ');

        self::assertResponseStatusCodeSame(422);

        /** @var array{type: string, violations?: list<array{field: string, message: string}>} $problem */
        $problem = $this->json();
        self::assertSame('/problems/validation-failed', $problem['type']);
    }

    private function edit(string $conversationId, string $messageId, string $content): void
    {
        $this->client->request(
            'PATCH',
            sprintf('/api/conversations/%s/messages/%s', $conversationId, $messageId),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['content' => $content], \JSON_THROW_ON_ERROR),
        );
    }
}
