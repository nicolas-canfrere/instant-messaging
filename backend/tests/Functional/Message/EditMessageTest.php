<?php

declare(strict_types=1);

namespace App\Tests\Functional\Message;

use App\Message\Domain\Message;
use App\Tests\Functional\DatabaseTestCase;
use App\Tests\Support\InMemoryEventPublisher;
use Symfony\Component\Clock\MockClock;

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

    /**
     * Le critere d'acceptation n°3 de la tranche, cote serveur. L'unitaire
     * couvre l'invariant de l'agregat ; ici c'est sa TRADUCTION en 403 et en
     * `type` du catalogue qui est verifiee — le chemin qu'un appel forge suit.
     */
    public function testEditingAfterTheWindowIsForbidden(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();
        $messageId = $this->send($conversationId, self::CLIENT_ID, 'bonjor');

        // Une seconde de plus que la fenetre, sur l'horloge que le conteneur de
        // test substitue. Aucune attente reelle : le message vieillit, pas la
        // suite de tests.
        $this->clock()->sleep(Message::EDIT_WINDOW_SECONDS + 1);

        $this->edit($conversationId, $messageId, 'trop tard');

        self::assertResponseStatusCodeSame(403);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');

        /** @var array{type: string} $problem */
        $problem = $this->json();
        self::assertSame('/problems/edit-window-expired', $problem['type']);
    }

    /** L'autre cote de la borne : a la seconde pres, l'edition passe encore. */
    public function testEditingOnTheLastSecondOfTheWindowIsAllowed(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();
        $messageId = $this->send($conversationId, self::CLIENT_ID, 'bonjor');

        $this->clock()->sleep(Message::EDIT_WINDOW_SECONDS);

        $this->edit($conversationId, $messageId, 'bonjour');

        self::assertResponseStatusCodeSame(200);
    }

    public function testItRequiresASession(): void
    {
        $this->client->request(
            'PATCH',
            '/api/conversations/01J9ZQ7X8K3M4N5P6Q7R8S9TZ1/messages/01J9ZQ7X8K3M4N5P6Q7R8S9TZ2',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['content' => 'bonjour'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * Carol n'est pas membre du direct alice/bob — `secondConversationId()` le
     * rend, `firstConversationId()` rendant le groupe dont elle fait partie. Un
     * non-membre recoit 404 et non 403 : un 403 confirmerait l'existence de la
     * conversation.
     */
    public function testANonMemberGetsNotFound(): void
    {
        $this->login('alice');
        $conversationId = $this->secondConversationId();
        $messageId = $this->send($conversationId, self::CLIENT_ID, 'entre nous');

        $this->login('carol');
        $this->edit($conversationId, $messageId, 'indiscret');

        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
    }

    /** Pas d'oracle : un identifiant inconnu et un message d'un autre fil sont indistinguables. */
    public function testAMessageFromAnotherConversationIsNotFound(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();
        $messageId = $this->send($conversationId, self::CLIENT_ID, 'ailleurs');

        $otherConversationId = $this->secondConversationId();

        $this->edit($otherConversationId, $messageId, 'deplace');
        self::assertResponseStatusCodeSame(404);
        /** @var array{type: string, title: string} $wrongConversation */
        $wrongConversation = $this->json();

        $this->edit($otherConversationId, '01J9ZQ7X8K3M4N5P6Q7R8S9TZZ', 'inconnu');
        self::assertResponseStatusCodeSame(404);
        /** @var array{type: string, title: string} $unknown */
        $unknown = $this->json();

        self::assertSame($unknown['type'], $wrongConversation['type']);
        self::assertSame($unknown['title'], $wrongConversation['title']);
    }

    private function clock(): MockClock
    {
        /** @var MockClock $clock */
        $clock = static::getContainer()->get(MockClock::class);

        return $clock;
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
