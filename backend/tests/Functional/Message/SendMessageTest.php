<?php

declare(strict_types=1);

namespace App\Tests\Functional\Message;

use App\Tests\Functional\DatabaseTestCase;
use App\Tests\Support\InMemoryEventPublisher;

final class SendMessageTest extends DatabaseTestCase
{
    private const string CLIENT_ID = '01J9ZQ7X8K3M4N5P6Q7R8S9TAB';

    public function testReplayingTheSameClientIdCreatesOnlyOneMessage(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();

        $publisher = static::getContainer()->get(InMemoryEventPublisher::class);

        $first = $this->send($conversationId, self::CLIENT_ID, 'bonjour');
        self::assertResponseStatusCodeSame(201);

        $second = $this->send($conversationId, self::CLIENT_ID, 'bonjour');
        self::assertResponseStatusCodeSame(200);

        self::assertSame($first, $second, 'Le rejeu doit renvoyer le meme identifiant serveur.');

        $created = array_filter(
            $publisher->published(),
            static fn(array $entry): bool => 'message.created' === $entry['type'],
        );

        self::assertCount(1, $created, 'Le rejeu ne doit pas republier sur Mercure.');
    }

    /** Le premier gagne : le second envoi n'ecrase rien. */
    public function testReplayWithDifferentContentKeepsTheFirstOne(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();

        $this->send($conversationId, self::CLIENT_ID, 'premier');
        $this->send($conversationId, self::CLIENT_ID, 'second');

        self::assertResponseStatusCodeSame(200);

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
                self::assertSame('premier', $conversation['last_message_preview']);

                return;
            }
        }

        self::fail('Conversation absente de la liste.');
    }

    /** L'evenement publie porte l'ULID du message comme identifiant SSE. */
    public function testThePublishedEventCarriesTheMessageUlidAsItsId(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();

        $publisher = static::getContainer()->get(InMemoryEventPublisher::class);

        $messageId = $this->send($conversationId, self::CLIENT_ID, 'bonjour');

        $created = array_values(array_filter(
            $publisher->published(),
            static fn(array $entry): bool => 'message.created' === $entry['type'],
        ));

        self::assertCount(1, $created);
        self::assertSame($messageId, $created[0]['id']);
        self::assertSame(sprintf('/conversations/%s', $conversationId), $created[0]['topic']);
    }

    /** La choregraphie : Conversation met a jour son propre pointeur. */
    public function testSendingAMessageUpdatesTheConversationPreview(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();

        $this->send($conversationId, self::CLIENT_ID, 'le dernier mot');

        $this->client->request('GET', '/api/conversations');

        /** @var list<array{id: string, last_message_preview: string|null, last_message_at: string|null}> $conversations */
        $conversations = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        // Le pointeur sert aussi de cle de tri : la conversation qui vient de
        // recevoir un message remonte en tete.
        self::assertSame($conversationId, $conversations[0]['id']);
        self::assertSame('le dernier mot', $conversations[0]['last_message_preview']);
        self::assertNotNull($conversations[0]['last_message_at']);
    }

    public function testAnEmptyMessageIsRejectedWithAProblemDocument(): void
    {
        $this->login('alice');

        $this->send($this->firstConversationId(), self::CLIENT_ID, '   ');

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
    }

    public function testAMessageTooLongIsRejected(): void
    {
        $this->login('alice');

        $this->send($this->firstConversationId(), self::CLIENT_ID, str_repeat('a', 4001));

        self::assertResponseStatusCodeSame(422);
    }

    public function testAMalformedClientMessageIdIsRejected(): void
    {
        $this->login('alice');

        $this->send($this->firstConversationId(), 'pas-un-ulid', 'bonjour');

        self::assertResponseStatusCodeSame(422);
    }

    /** Un non-membre recoit un 404 : un 403 confirmerait l'existence du fil. */
    public function testANonMemberCannotPost(): void
    {
        $this->login('alice');
        // Le direct Alice-Bob, dont Carol n'est pas membre. Le groupe des
        // fixtures ne conviendrait pas : Carol en fait partie.
        $conversationId = $this->conversationIdOfType('direct');

        $this->login('carol');
        $this->send($conversationId, self::CLIENT_ID, 'intrusion');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * La cle d'idempotence est unique par EXPEDITEUR, pas par conversation.
     * Sans garde-fou, ce second envoi rendrait 200 avec l'identifiant d'un
     * message appartenant a l'autre fil — une reponse silencieusement fausse.
     */
    public function testReusingAClientKeyInAnotherConversationIsRejected(): void
    {
        $this->login('alice');

        $this->send($this->conversationIdOfType('direct'), self::CLIENT_ID, 'bonjour');
        self::assertResponseStatusCodeSame(201);

        $this->send($this->conversationIdOfType('group'), self::CLIENT_ID, 'bonjour');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('/problems/validation-failed', $this->json()['type']);
    }

    /**
     * Le pointeur ne doit jamais reculer : ces mises a jour arrivent dans une
     * seconde transaction, dont l'ordre est independant de celui des messages.
     */
    public function testTheConversationPreviewNeverGoesBackwards(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();

        $this->send($conversationId, '01J9ZQ7X8K3M4N5P6Q7R8S9TAB', 'premier');
        $this->send($conversationId, '01J9ZQ7X8K3M4N5P6Q7R8S9TAC', 'dernier');

        // Rejeu du plus ancien : il ne doit pas ecraser l'apercu du plus recent.
        $this->send($conversationId, '01J9ZQ7X8K3M4N5P6Q7R8S9TAB', 'premier');

        foreach ($this->conversations() as $conversation) {
            if ($conversation['id'] === $conversationId) {
                self::assertSame('dernier', $conversation['last_message_preview']);

                return;
            }
        }

        self::fail('Conversation absente de la liste.');
    }

    private function firstConversationId(): string
    {
        return $this->conversations()[0]['id'];
    }

    private function conversationIdOfType(string $type): string
    {
        foreach ($this->conversations() as $conversation) {
            if ($type === $conversation['type']) {
                return $conversation['id'];
            }
        }

        self::fail(sprintf('Aucune conversation de type %s.', $type));
    }

    /** @return list<array{id: string, type: string, last_message_preview: string|null, last_message_at: string|null}> */
    private function conversations(): array
    {
        $this->client->request('GET', '/api/conversations');

        /** @var list<array{id: string, type: string, last_message_preview: string|null, last_message_at: string|null}> $body */
        $body = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        return $body;
    }

    private function send(string $conversationId, string $clientMessageId, string $content): string
    {
        $this->client->request(
            'POST',
            sprintf('/api/conversations/%s/messages', $conversationId),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(
                ['client_message_id' => $clientMessageId, 'content' => $content],
                \JSON_THROW_ON_ERROR,
            ),
        );

        /** @var array{id?: string} $decoded */
        $decoded = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        return $decoded['id'] ?? '';
    }
}
