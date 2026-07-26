<?php

declare(strict_types=1);

namespace App\Tests\Functional\Conversation;

use App\Tests\Functional\DatabaseTestCase;
use App\Tests\Support\InMemoryEventPublisher;

final class LeaveGroupTest extends DatabaseTestCase
{
    /**
     * Ce test verrouille aussi la route : si `/members/{userId}` captait
     * `/members/me`, Bob recevrait un 403 faute de droits d'admin.
     */
    public function testAPlainMemberLeavesAndTheGroupForgetsHim(): void
    {
        $this->login('alice');
        $groupId = $this->createGroup('Equipe projet', [$this->userId('bob')]);

        $this->login('bob');
        $this->leave($groupId);

        self::assertResponseStatusCodeSame(204);

        // Sa liste laterale ne la contient plus.
        $this->client->request('GET', '/api/conversations');
        /** @var list<array{id: string}> $conversations */
        $conversations = $this->json();
        self::assertNotContains($groupId, array_column($conversations, 'id'));

        // Le detail lui est desormais inaccessible — 404 et non 403.
        $this->client->request('GET', sprintf('/api/conversations/%s', $groupId));
        self::assertResponseStatusCodeSame(404);

        // Et Alice ne le voit plus parmi les membres.
        $this->login('alice');
        $this->client->request('GET', sprintf('/api/conversations/%s', $groupId));
        self::assertCount(1, (array) $this->json()['members']);
    }

    /**
     * Le « je ne suis plus notifie » se verifie ici, sans lever de hub : le
     * jeton ne liste que les topics des conversations dont on est membre.
     */
    public function testTheTokenOfTheFormerMemberNoLongerCarriesTheTopic(): void
    {
        $this->login('alice');
        $groupId = $this->createGroup('Equipe projet', [$this->userId('bob')]);

        $this->login('bob');

        $this->client->request('GET', '/api/realtime/token');
        /** @var array{topics: list<string>} $before */
        $before = $this->json();
        self::assertContains(sprintf('/conversations/%s', $groupId), $before['topics']);

        $this->leave($groupId);

        $this->client->request('GET', '/api/realtime/token');
        /** @var array{topics: list<string>} $after */
        $after = $this->json();
        self::assertNotContains(sprintf('/conversations/%s', $groupId), $after['topics']);
    }

    /** C'est par son topic systeme que le partant apprend qu'il doit se reabonner. */
    public function testLeavingPublishesOnThePersonalTopicOfTheLeaver(): void
    {
        $this->login('alice');
        $bobId = $this->userId('bob');
        $groupId = $this->createGroup('Equipe projet', [$bobId]);

        $publisher = static::getContainer()->get(InMemoryEventPublisher::class);

        $this->login('bob');
        $this->leave($groupId);

        $membershipEvents = array_values(array_filter(
            $publisher->published(),
            static fn(array $entry): bool => 'membership.changed' === $entry['type'],
        ));

        self::assertNotEmpty($membershipEvents);

        $last = end($membershipEvents);

        self::assertSame(sprintf('/users/%s/system', $bobId), $last['topic']);
        self::assertSame('left', $last['payload']['change']);
    }

    /** Le role ne manque pas, il est trop eleve : 409, et non 403. */
    public function testAnAdminCannotLeave(): void
    {
        $this->login('alice');
        $groupId = $this->createGroup('Equipe projet', [$this->userId('bob')]);

        $this->leave($groupId);

        self::assertResponseStatusCodeSame(409);

        /** @var array{type: string} $problem */
        $problem = $this->json();
        self::assertSame('/problems/admin-cannot-leave', $problem['type']);
    }

    /** Un non-membre ne doit rien apprendre de l'existence du groupe. */
    public function testANonMemberGetsA404(): void
    {
        $this->login('alice');
        $groupId = $this->createGroup('Groupe prive', []);

        $this->login('carol');
        $this->leave($groupId);

        self::assertResponseStatusCodeSame(404);
    }

    /** Consequence assumee du cadrage par l'appartenance : on ne part qu'une fois. */
    public function testLeavingTwiceGivesA404(): void
    {
        $this->login('alice');
        $groupId = $this->createGroup('Equipe projet', [$this->userId('bob')]);

        $this->login('bob');
        $this->leave($groupId);
        self::assertResponseStatusCodeSame(204);

        $this->leave($groupId);
        self::assertResponseStatusCodeSame(404);
    }

    private function leave(string $conversationId): void
    {
        $this->client->request(
            'DELETE',
            sprintf('/api/conversations/%s/members/me', $conversationId),
        );
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
}
