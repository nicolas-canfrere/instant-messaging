<?php

declare(strict_types=1);

namespace App\Tests\Functional\Message;

use App\Message\Application\Query\GetMessagePageQueryHandler;
use App\Shared\Domain\IdGeneratorInterface;
use App\Tests\Functional\DatabaseTestCase;

final class MessagePaginationTest extends DatabaseTestCase
{
    public function testWalkingBackThroughHistoryHasNoGapAndNoDuplicate(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();

        $sent = $this->sendMany($conversationId, 120);

        $collected = [];
        $before = null;

        do {
            $page = $this->fetchPage($conversationId, $before, 50);
            $collected = [...$collected, ...array_column($page['items'], 'id')];
            $before = $page['next_before'];
        } while (null !== $before);

        self::assertCount(120, $collected, 'Aucun message ne doit manquer.');
        self::assertSame(array_unique($collected), $collected, 'Aucun doublon.');
        self::assertSame(array_reverse($sent), $collected, 'Ordre : du plus recent au plus ancien.');
    }

    /**
     * Le motif que la pagination par curseur achete : avec un OFFSET, un
     * message arrive entre deux pages decalerait la fenetre et ferait sauter
     * un element.
     */
    public function testAMessageInsertedBetweenTwoPagesNeverShiftsTheWindow(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();

        $sent = $this->sendMany($conversationId, 60);

        $firstPage = $this->fetchPage($conversationId, null, 50);

        $this->sendMany($conversationId, 1);

        $secondPage = $this->fetchPage($conversationId, $firstPage['next_before'], 50);

        $ids = [...array_column($firstPage['items'], 'id'), ...array_column($secondPage['items'], 'id')];

        self::assertSame(array_unique($ids), $ids, 'Aucun doublon malgre l\'insertion concurrente.');
        self::assertContains($sent[0], $ids, 'Le plus ancien message doit rester atteignable.');
    }

    /** Derniere page : plus rien a remonter, donc pas de curseur suivant. */
    public function testTheLastPageHasNoCursor(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();

        $this->sendMany($conversationId, 3);

        $page = $this->fetchPage($conversationId, null, 50);

        self::assertCount(3, $page['items']);
        self::assertNull($page['next_before']);
    }

    /**
     * Le cas limite : le nombre de messages tombe pile sur un multiple de la
     * limite. Deduire « il reste quelque chose » du seul fait que la page est
     * pleine annoncerait ici un curseur qui ne ramenerait rien — un « charger
     * plus » vide dans le front, et un aller-retour paye pour rien.
     */
    public function testAFullPageThatExhaustsTheHistoryHasNoCursor(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();

        $this->sendMany($conversationId, 10);

        $page = $this->fetchPage($conversationId, null, 5);
        self::assertCount(5, $page['items']);
        self::assertNotNull($page['next_before'], 'Il reste cinq messages plus anciens.');

        $lastPage = $this->fetchPage($conversationId, $page['next_before'], 5);
        self::assertCount(5, $lastPage['items']);
        self::assertNull($lastPage['next_before'], 'Page pleine, mais plus rien derriere.');
    }

    public function testAnEmptyConversationYieldsAnEmptyPage(): void
    {
        $this->login('alice');

        $page = $this->fetchPage($this->firstConversationId(), null, 50);

        self::assertSame([], $page['items']);
        self::assertNull($page['next_before']);
    }

    /**
     * La borne haute protege la base d'une demande demesuree. Il faut plus de
     * MAX_LIMIT messages pour que le plafond soit reellement observable :
     * avec moins, retirer l'ecretage du handler laisserait le test au vert.
     */
    public function testTheLimitIsCappedRatherThanRejected(): void
    {
        $this->login('alice');
        $conversationId = $this->firstConversationId();

        $this->sendMany($conversationId, GetMessagePageQueryHandler::MAX_LIMIT + 1);

        $page = $this->fetchPage($conversationId, null, 100_000);

        self::assertCount(GetMessagePageQueryHandler::MAX_LIMIT, $page['items']);
    }

    public function testAMalformedCursorIsRejected(): void
    {
        $this->login('alice');

        $this->client->request(
            'GET',
            sprintf('/api/conversations/%s/messages?before=pas-un-ulid', $this->firstConversationId()),
        );

        self::assertResponseStatusCodeSame(422);
    }

    /** Un non-membre ne lit pas l'historique, et recoit un 404. */
    public function testANonMemberCannotReadTheHistory(): void
    {
        $this->login('alice');
        $conversationId = $this->conversationIdOfType('direct');

        $this->login('carol');
        $this->client->request('GET', sprintf('/api/conversations/%s/messages', $conversationId));

        self::assertResponseStatusCodeSame(404);
    }

    /** @return list<string> identifiants dans l'ordre d'envoi */
    private function sendMany(string $conversationId, int $count): array
    {
        $generator = static::getContainer()->get(IdGeneratorInterface::class);
        $ids = [];

        for ($i = 0; $i < $count; ++$i) {
            $this->client->request(
                'POST',
                sprintf('/api/conversations/%s/messages', $conversationId),
                server: ['CONTENT_TYPE' => 'application/json'],
                content: json_encode(
                    ['client_message_id' => $generator->generate(), 'content' => sprintf('message %d', $i)],
                    \JSON_THROW_ON_ERROR,
                ),
            );

            // Sans cette assertion, un envoi refuse ressortirait plus loin en
            // « undefined array key id », dans une aide de test plutot que la
            // ou le probleme se trouve.
            self::assertResponseStatusCodeSame(201);

            /** @var array{id: string} $body */
            $body = json_decode(
                (string) $this->client->getResponse()->getContent(),
                true,
                512,
                \JSON_THROW_ON_ERROR,
            );

            $ids[] = $body['id'];
        }

        return $ids;
    }

    /** @return array{items: list<array{id: string, content: string}>, next_before: string|null} */
    private function fetchPage(string $conversationId, ?string $before, int $limit): array
    {
        $query = ['limit' => (string) $limit];

        if (null !== $before) {
            $query['before'] = $before;
        }

        $this->client->request(
            'GET',
            sprintf('/api/conversations/%s/messages?%s', $conversationId, http_build_query($query)),
        );

        self::assertResponseIsSuccessful();

        /** @var array{items: list<array{id: string, content: string}>, next_before: string|null} $body */
        $body = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        return $body;
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

    /** @return list<array{id: string, type: string}> */
    private function conversations(): array
    {
        $this->client->request('GET', '/api/conversations');

        /** @var list<array{id: string, type: string}> $body */
        $body = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        return $body;
    }
}
