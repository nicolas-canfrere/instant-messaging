<?php

declare(strict_types=1);

namespace App\Tests\Unit\Conversation\Domain;

use App\Conversation\Domain\Membership;
use App\Shared\Domain\Event\ReceiptWatermarkAdvanced;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\UserId;
use PHPUnit\Framework\TestCase;

final class MembershipTest extends TestCase
{
    private const string CONVERSATION = '01J9ZQ7X8K3M4N5P6Q7R8S9TAA';
    private const string USER = '01J9ZQ7X8K3M4N5P6Q7R8S9TAB';
    private const string OLDER = '01J9ZQ7X8K3M4N5P6Q7R8S9TA0';
    private const string NEWER = '01J9ZQ7X8K3M4N5P6Q7R8S9TA9';

    public function testAFirstWatermarkAdvancesFromNull(): void
    {
        $membership = $this->membership(null, null);

        $membership->advanceReadTo(self::OLDER);

        self::assertSame(self::OLDER, $membership->lastReadMessageId());
        self::assertCount(1, $membership->releaseEvents());
    }

    public function testAnOlderWatermarkNeverMovesTheCursorBack(): void
    {
        $membership = $this->membership(null, self::NEWER);

        $membership->advanceReadTo(self::OLDER);

        self::assertSame(self::NEWER, $membership->lastReadMessageId());
        self::assertSame([], $membership->releaseEvents(), 'Aucun evenement si rien n a bouge.');
    }

    public function testAnIdenticalWatermarkRecordsNothing(): void
    {
        $membership = $this->membership(null, self::NEWER);

        $membership->advanceReadTo(self::NEWER);

        self::assertSame([], $membership->releaseEvents());
    }

    public function testANullWatermarkIsIgnored(): void
    {
        $membership = $this->membership(null, self::NEWER);

        // Le client n'envoie que le curseur qui a bouge : l'autre arrive a null.
        $membership->advanceReadTo(null);

        self::assertSame(self::NEWER, $membership->lastReadMessageId());
        self::assertSame([], $membership->releaseEvents());
    }

    public function testBothCursorsAdvanceIndependently(): void
    {
        $membership = $this->membership(null, null);

        $membership->advanceDeliveredTo(self::NEWER);
        $membership->advanceReadTo(self::OLDER);

        self::assertSame(self::NEWER, $membership->lastDeliveredMessageId());
        self::assertSame(self::OLDER, $membership->lastReadMessageId());
    }

    /** L'evenement porte TOUJOURS les deux curseurs, meme si un seul a bouge. */
    public function testTheRecordedEventCarriesBothCursors(): void
    {
        $membership = $this->membership(self::OLDER, null);

        $membership->advanceReadTo(self::NEWER);

        $events = $membership->releaseEvents();
        self::assertCount(1, $events);

        $event = $events[0];
        self::assertInstanceOf(ReceiptWatermarkAdvanced::class, $event);
        self::assertSame(self::OLDER, $event->lastDeliveredMessageId);
        self::assertSame(self::NEWER, $event->lastReadMessageId);
    }

    /** Deux curseurs avances d'un coup ne produisent qu'UN evenement. */
    public function testAdvancingBothCursorsRecordsASingleEvent(): void
    {
        $membership = $this->membership(null, null);

        $membership->advanceDeliveredTo(self::NEWER);
        $membership->advanceReadTo(self::NEWER);

        self::assertCount(1, $membership->releaseEvents());
    }

    private function membership(?string $delivered, ?string $read): Membership
    {
        return Membership::reconstitute(
            ConversationId::fromString(self::CONVERSATION),
            UserId::fromString(self::USER),
            $delivered,
            $read,
        );
    }
}
