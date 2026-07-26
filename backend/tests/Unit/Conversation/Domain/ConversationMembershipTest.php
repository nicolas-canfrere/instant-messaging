<?php

declare(strict_types=1);

namespace App\Tests\Unit\Conversation\Domain;

use App\Conversation\Domain\AdminCannotLeaveException;
use App\Conversation\Domain\Conversation;
use App\Conversation\Domain\NotAGroupException;
use App\Shared\Domain\Event\MembershipChange;
use App\Shared\Domain\Event\MembershipChanged;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\UserId;
use PHPUnit\Framework\TestCase;

final class ConversationMembershipTest extends TestCase
{
    private const string CONVERSATION = '01J9ZQ7X8K3M4N5P6Q7R8S9TAA';
    private const string ALICE = '01J9ZQ7X8K3M4N5P6Q7R8S9TAB';
    private const string BOB = '01J9ZQ7X8K3M4N5P6Q7R8S9TAC';
    private const string CAROL = '01J9ZQ7X8K3M4N5P6Q7R8S9TAD';

    public function testTheGroupCreatorIsAdminAndOthersAreMembers(): void
    {
        $group = $this->group();

        self::assertTrue($group->isAdmin(UserId::fromString(self::ALICE)));
        self::assertTrue($group->hasMember(UserId::fromString(self::BOB)));
        self::assertFalse($group->isAdmin(UserId::fromString(self::BOB)));
    }

    public function testAddingAMemberRecordsAnEvent(): void
    {
        $group = $this->group();
        $group->addMember(UserId::fromString(self::CAROL), new \DateTimeImmutable('2026-07-25 10:00:00'));

        $events = $group->releaseEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(MembershipChanged::class, $events[0]);
        self::assertSame(self::CAROL, $events[0]->userId->toString());
        self::assertSame(MembershipChange::Joined, $events[0]->change);
        self::assertTrue($group->hasMember(UserId::fromString(self::CAROL)));
    }

    /** Sans quoi un ajout redondant republierait un evenement au client. */
    public function testAddingAnExistingMemberIsANoOp(): void
    {
        $group = $this->group();
        $group->addMember(UserId::fromString(self::BOB), new \DateTimeImmutable('2026-07-25 10:00:00'));

        self::assertSame([], $group->releaseEvents(), 'Reajouter un membre ne doit rien produire.');
    }

    public function testRemovingAMemberRecordsAnEvent(): void
    {
        $group = $this->group();
        $group->removeMember(UserId::fromString(self::BOB));

        $events = $group->releaseEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(MembershipChanged::class, $events[0]);
        self::assertSame(MembershipChange::Left, $events[0]->change);
        self::assertFalse($group->hasMember(UserId::fromString(self::BOB)));
    }

    public function testAPlainMemberCanLeaveTheGroup(): void
    {
        $group = $this->group();
        $group->leave(UserId::fromString(self::BOB));

        $events = $group->releaseEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(MembershipChanged::class, $events[0]);
        self::assertSame(self::BOB, $events[0]->userId->toString());
        self::assertSame(MembershipChange::Left, $events[0]->change);
        self::assertFalse($group->hasMember(UserId::fromString(self::BOB)));
    }

    /**
     * Le role ne MANQUE pas, il est trop eleve : l'admin doit d'abord
     * transferer ses droits. Le transfert n'existe pas encore, c'est assume.
     */
    public function testAnAdminCannotLeaveTheGroup(): void
    {
        $group = $this->group();

        $this->expectException(AdminCannotLeaveException::class);

        try {
            $group->leave(UserId::fromString(self::ALICE));
        } finally {
            self::assertTrue($group->hasMember(UserId::fromString(self::ALICE)));
            self::assertSame([], $group->releaseEvents());
        }
    }

    /** Un second depart ne doit pas republier un evenement au hub. */
    public function testLeavingTwiceIsANoOp(): void
    {
        $group = $this->group();
        $group->leave(UserId::fromString(self::BOB));
        $group->releaseEvents();

        $group->leave(UserId::fromString(self::BOB));

        self::assertSame([], $group->releaseEvents());
    }

    /** Un direct a deux participants pour toujours : on n'en part pas. */
    public function testNobodyCanLeaveADirect(): void
    {
        $direct = Conversation::direct(
            ConversationId::fromString(self::CONVERSATION),
            UserId::fromString(self::ALICE),
            UserId::fromString(self::BOB),
            new \DateTimeImmutable('2026-07-25 09:00:00'),
        );

        $this->expectException(NotAGroupException::class);

        $direct->leave(UserId::fromString(self::BOB));
    }

    public function testEventsAreReleasedOnlyOnce(): void
    {
        $group = $this->group();
        $group->addMember(UserId::fromString(self::CAROL), new \DateTimeImmutable('2026-07-25 10:00:00'));

        $group->releaseEvents();

        self::assertSame([], $group->releaseEvents());
    }

    /** Les deux membres d'un direct sont fixes : sa composition ne se modifie pas. */
    public function testTheMembersOfADirectCannotBeChanged(): void
    {
        $direct = Conversation::direct(
            ConversationId::fromString(self::CONVERSATION),
            UserId::fromString(self::ALICE),
            UserId::fromString(self::BOB),
            new \DateTimeImmutable('2026-07-25 09:00:00'),
        );

        $this->expectException(NotAGroupException::class);

        $direct->addMember(UserId::fromString(self::CAROL), new \DateTimeImmutable('2026-07-25 10:00:00'));
    }

    /** Le createur ne doit pas figurer deux fois s'il se liste lui-meme. */
    public function testTheCreatorIsNotDuplicatedWhenListedAmongMembers(): void
    {
        $group = Conversation::group(
            ConversationId::fromString(self::CONVERSATION),
            'Equipe projet',
            UserId::fromString(self::ALICE),
            [UserId::fromString(self::ALICE), UserId::fromString(self::BOB)],
            new \DateTimeImmutable('2026-07-25 09:00:00'),
        );

        self::assertCount(2, $group->memberIds());
        self::assertTrue($group->isAdmin(UserId::fromString(self::ALICE)));
    }

    /**
     * Les evenements de CREATION sont draines avant de rendre l'agregat : les
     * tests ci-dessus portent sur l'ajout et le retrait, pas sur la creation,
     * qui a ses propres tests plus bas.
     */
    /**
     * Creer une conversation EST un changement d'appartenance pour celui qu'on
     * y met. Sans cet evenement, le destinataire n'apprend jamais que le fil
     * existe : son JWT a ete emis avant, il ne couvre donc pas le topic, et le
     * hub ne lui livrera pas non plus le premier message. Le topic personnel
     * `/users/{id}/system` est le seul canal par lequel il peut l'apprendre.
     */
    public function testCreatingADirectNotifiesThePeerOnly(): void
    {
        $direct = Conversation::direct(
            ConversationId::fromString(self::CONVERSATION),
            UserId::fromString(self::ALICE),
            UserId::fromString(self::BOB),
            new \DateTimeImmutable('2026-07-25 09:00:00'),
        );

        $events = $direct->releaseEvents();

        self::assertCount(1, $events, 'L initiateur n a pas a etre prevenu de ce qu il vient de faire.');
        self::assertInstanceOf(MembershipChanged::class, $events[0]);
        self::assertSame(self::BOB, $events[0]->userId->toString());
        self::assertSame(MembershipChange::Joined, $events[0]->change);
        self::assertSame(self::CONVERSATION, $events[0]->conversationId->toString());
    }

    public function testCreatingAGroupNotifiesEveryMemberButTheCreator(): void
    {
        $group = Conversation::group(
            ConversationId::fromString(self::CONVERSATION),
            'Equipe projet',
            UserId::fromString(self::ALICE),
            [UserId::fromString(self::BOB), UserId::fromString(self::CAROL)],
            new \DateTimeImmutable('2026-07-25 09:00:00'),
        );

        $events = $group->releaseEvents();

        self::assertCount(2, $events);

        $notified = [];
        foreach ($events as $event) {
            self::assertInstanceOf(MembershipChanged::class, $event);
            self::assertSame(MembershipChange::Joined, $event->change);

            $notified[] = $event->userId->toString();
        }

        self::assertEqualsCanonicalizing([self::BOB, self::CAROL], $notified);
        self::assertNotContains(self::ALICE, $notified);
    }

    /** Le createur qui se liste lui-meme ne doit pas recevoir d'evenement pour autant. */
    public function testTheCreatorListedAmongMembersIsStillNotNotified(): void
    {
        $group = Conversation::group(
            ConversationId::fromString(self::CONVERSATION),
            'Equipe projet',
            UserId::fromString(self::ALICE),
            [UserId::fromString(self::ALICE), UserId::fromString(self::BOB)],
            new \DateTimeImmutable('2026-07-25 09:00:00'),
        );

        $events = $group->releaseEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(MembershipChanged::class, $events[0]);
        self::assertSame(self::BOB, $events[0]->userId->toString());
    }

    private function group(): Conversation
    {
        $group = $this->newGroup();
        $group->releaseEvents();

        return $group;
    }

    private function newGroup(): Conversation
    {
        return Conversation::group(
            ConversationId::fromString(self::CONVERSATION),
            'Equipe projet',
            UserId::fromString(self::ALICE),
            [UserId::fromString(self::BOB)],
            new \DateTimeImmutable('2026-07-25 09:00:00'),
        );
    }
}
