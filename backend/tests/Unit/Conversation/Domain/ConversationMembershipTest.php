<?php

declare(strict_types=1);

namespace App\Tests\Unit\Conversation\Domain;

use App\Conversation\Domain\Conversation;
use App\Conversation\Domain\NotAGroupException;
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
        self::assertSame('joined', $events[0]->change);
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
        self::assertSame('left', $events[0]->change);
        self::assertFalse($group->hasMember(UserId::fromString(self::BOB)));
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

    private function group(): Conversation
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
