<?php

declare(strict_types=1);

namespace App\Tests\Functional\Conversation;

use App\Tests\Functional\DatabaseTestCase;
use App\Tests\Support\InMemoryEventPublisher;

/**
 * Non-regression. Creer une conversation ne prevenait personne : seuls
 * `addMember()` et `removeMember()` enregistraient un MembershipChanged, jamais
 * les constructeurs. Le destinataire n'apprenait donc pas que le fil existait,
 * et comme son JWT avait ete emis avant, le hub ne lui livrait pas non plus le
 * premier message. Il ne voyait rien jusqu'au rechargement de la page.
 */
final class CreationNotifiesMembersTest extends DatabaseTestCase
{
    public function testCreatingADirectNotifiesThePeerOnTheirSystemTopic(): void
    {
        $this->login('carol');
        $aliceId = $this->userId('alice');
        $carolId = $this->userId('carol');

        $publisher = static::getContainer()->get(InMemoryEventPublisher::class);

        $this->createDirectWith($aliceId);

        $notifications = $this->membershipNotifications($publisher);

        self::assertCount(1, $notifications, 'Carol n a pas a etre prevenue de ce qu elle vient de faire.');
        self::assertSame(sprintf('/users/%s/system', $aliceId), $notifications[0]['topic']);
        self::assertSame('joined', $notifications[0]['payload']['change']);
        self::assertNotSame(sprintf('/users/%s/system', $carolId), $notifications[0]['topic']);
    }

    /** Rouvrir un direct deja ouvert ne doit rien republier : la creation est idempotente. */
    public function testReopeningAnExistingDirectNotifiesNobody(): void
    {
        $this->login('carol');
        $aliceId = $this->userId('alice');

        $this->createDirectWith($aliceId);

        $publisher = static::getContainer()->get(InMemoryEventPublisher::class);
        $before = count($this->membershipNotifications($publisher));

        $this->createDirectWith($aliceId);

        self::assertCount(
            $before,
            $this->membershipNotifications($publisher),
            'Un direct deja ouvert ne doit produire aucune nouvelle notification.',
        );
    }

    public function testCreatingAGroupNotifiesEveryMemberButTheCreator(): void
    {
        $this->login('alice');
        $aliceId = $this->userId('alice');
        $bobId = $this->userId('bob');
        $carolId = $this->userId('carol');

        $publisher = static::getContainer()->get(InMemoryEventPublisher::class);

        $this->client->request(
            'POST',
            '/api/conversations',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(
                ['type' => 'group', 'title' => 'Equipe projet', 'member_ids' => [$bobId, $carolId]],
                \JSON_THROW_ON_ERROR,
            ),
        );
        self::assertResponseIsSuccessful();

        $topics = array_map(
            static fn(array $entry): string => $entry['topic'],
            $this->membershipNotifications($publisher),
        );

        self::assertEqualsCanonicalizing(
            [sprintf('/users/%s/system', $bobId), sprintf('/users/%s/system', $carolId)],
            $topics,
        );
        self::assertNotContains(sprintf('/users/%s/system', $aliceId), $topics);
    }

    /**
     * @return list<array{topic: string, type: string, payload: array<string, mixed>, id: string}>
     */
    private function membershipNotifications(InMemoryEventPublisher $publisher): array
    {
        return array_values(array_filter(
            $publisher->published(),
            static fn(array $entry): bool => 'membership.changed' === $entry['type'],
        ));
    }

    private function createDirectWith(string $peerId): void
    {
        $this->client->request(
            'POST',
            '/api/conversations',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(
                ['type' => 'direct', 'member_ids' => [$peerId]],
                \JSON_THROW_ON_ERROR,
            ),
        );

        self::assertResponseIsSuccessful();
    }
}
