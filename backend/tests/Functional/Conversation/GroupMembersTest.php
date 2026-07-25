<?php

declare(strict_types=1);

namespace App\Tests\Functional\Conversation;

use App\Tests\Functional\DatabaseTestCase;
use App\Tests\Support\InMemoryEventPublisher;

final class GroupMembersTest extends DatabaseTestCase
{
    public function testAddingAMemberPublishesOnTheirPersonalTopic(): void
    {
        $this->login('alice');
        $carolId = $this->userId('carol');
        $groupId = $this->createGroup('Nouveau groupe', [$this->userId('bob')]);

        // L'espion remplace MercureEventPublisher en test (services_test.yaml) :
        // on assertionne le topic ET la charge utile sans lever de hub.
        $publisher = static::getContainer()->get(InMemoryEventPublisher::class);

        $this->addMembers($groupId, [$carolId]);

        self::assertResponseIsSuccessful();

        $membershipEvents = array_values(array_filter(
            $publisher->published(),
            static fn(array $entry): bool => 'membership.changed' === $entry['type'],
        ));

        self::assertNotEmpty($membershipEvents);

        $last = end($membershipEvents);

        self::assertSame(sprintf('/users/%s/system', $carolId), $last['topic']);
        self::assertSame('joined', $last['payload']['change']);
    }

    /** Un 403 confirmerait l'existence de la conversation. */
    public function testANonMemberGetsA404AndNeverA403(): void
    {
        $this->login('alice');
        $groupId = $this->createGroup('Groupe prive', []);

        $this->login('carol');
        $this->client->request('GET', sprintf('/api/conversations/%s', $groupId));

        self::assertResponseStatusCodeSame(404);
    }

    /** Le test qui verrouille la decision de securite. */
    public function testAnUnknownIdIsIndistinguishableFromAnInaccessibleOne(): void
    {
        $this->login('alice');
        $groupId = $this->createGroup('Groupe prive', []);

        $this->login('carol');

        $this->client->request('GET', sprintf('/api/conversations/%s', $groupId));
        $inaccessible = $this->problemWithoutCorrelationId();

        $this->client->request('GET', '/api/conversations/01J9ZQ7X8K3M4N5P6Q7R8S9TZZ');
        $unknown = $this->problemWithoutCorrelationId();

        self::assertSame($unknown, $inaccessible);
    }

    public function testAMemberSeesTheConversationAndItsMembers(): void
    {
        $this->login('alice');
        $groupId = $this->createGroup('Equipe', [$this->userId('bob')]);

        $this->client->request('GET', sprintf('/api/conversations/%s', $groupId));

        self::assertResponseIsSuccessful();

        $body = $this->json();

        self::assertSame('group', $body['type']);
        self::assertSame('Equipe', $body['title']);
        self::assertCount(2, (array) $body['members']);
    }

    /** L'appartenance est etablie, seul le role manque : 403, pas 404. */
    public function testAPlainMemberCannotManageMembers(): void
    {
        $this->login('alice');
        $bobId = $this->userId('bob');
        $carolId = $this->userId('carol');
        $groupId = $this->createGroup('Equipe', [$bobId, $carolId]);

        $this->login('bob');
        $this->addMembers($groupId, [$carolId]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCanRemoveAMember(): void
    {
        $this->login('alice');
        $bobId = $this->userId('bob');
        $groupId = $this->createGroup('Equipe', [$bobId]);

        $this->client->request('DELETE', sprintf('/api/conversations/%s/members/%s', $groupId, $bobId));

        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', sprintf('/api/conversations/%s', $groupId));

        self::assertCount(1, (array) $this->json()['members']);
    }

    /** La composition d'un direct est fixe : la modifier n'a pas de sens. */
    public function testMembersOfADirectCannotBeChanged(): void
    {
        $this->login('alice');
        $carolId = $this->userId('carol');

        $this->client->request(
            'POST',
            '/api/conversations',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['type' => 'direct', 'member_ids' => [$carolId]], \JSON_THROW_ON_ERROR),
        );

        /** @var array{id: string} $created */
        $created = $this->json();

        $this->addMembers($created['id'], [$this->userId('bob')]);

        self::assertResponseStatusCodeSame(422);
    }

    /** @param list<string> $memberIds */
    private function createGroup(string $title, array $memberIds): string
    {
        $this->client->request(
            'POST',
            '/api/conversations',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(
                ['type' => 'group', 'title' => $title, 'member_ids' => $memberIds],
                \JSON_THROW_ON_ERROR,
            ),
        );

        self::assertResponseStatusCodeSame(201);

        /** @var array{id: string} $body */
        $body = $this->json();

        return $body['id'];
    }

    /** @param list<string> $userIds */
    private function addMembers(string $conversationId, array $userIds): void
    {
        $this->client->request(
            'POST',
            sprintf('/api/conversations/%s/members', $conversationId),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['user_ids' => $userIds], \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Retire les deux membres qui varient legitimement d'une requete a l'autre :
     * `correlation_id`, propre a la requete, et `instance`, qui reprend l'URL
     * demandee — que l'appelant connait deja, donc qui ne revele rien.
     *
     * Tout le reste doit etre identique a l'octet pres.
     *
     * @return array<string, mixed>
     */
    private function problemWithoutCorrelationId(): array
    {
        $problem = $this->json();
        unset($problem['correlation_id'], $problem['instance']);

        return $problem;
    }
}
