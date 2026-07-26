<?php

declare(strict_types=1);

namespace App\Tests\Unit\Message\Domain;

use App\Message\Domain\ClientMessageId;
use App\Message\Domain\EditWindowExpiredException;
use App\Message\Domain\Message;
use App\Message\Domain\MessageAlreadyDeletedException;
use App\Message\Domain\MessageContent;
use App\Message\Domain\NotTheAuthorException;
use App\Shared\Domain\Event\MessageWasDeleted;
use App\Shared\Domain\Event\MessageWasEdited;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\MessageId;
use App\Shared\Domain\Identifier\UserId;
use PHPUnit\Framework\TestCase;

final class MessageLifecycleTest extends TestCase
{
    private const string MESSAGE_ID = '01J9ZQ7X8K3M4N5P6Q7R8S9TA1';
    private const string CONVERSATION_ID = '01J9ZQ7X8K3M4N5P6Q7R8S9TA2';
    private const string AUTHOR_ID = '01J9ZQ7X8K3M4N5P6Q7R8S9TA3';
    private const string CLIENT_ID = '01J9ZQ7X8K3M4N5P6Q7R8S9TA5';
    private const string OTHER_USER_ID = '01J9ZQ7X8K3M4N5P6Q7R8S9TA4';

    public function testAFreshMessageIsNeitherEditedNorDeleted(): void
    {
        $message = self::send();

        self::assertFalse($message->isDeleted());
        self::assertNull($message->editedAt());
        self::assertNull($message->deletedAt());
        self::assertSame('bonjour', $message->content()?->toString());
    }

    /** Un tombstone se relit sans contenu : c'est ce que la colonne nullable permet. */
    public function testATombstoneCanBeReconstitutedWithoutContent(): void
    {
        $deletedAt = new \DateTimeImmutable('2026-07-26T10:00:00+00:00');

        $message = Message::reconstitute(
            MessageId::fromString(self::MESSAGE_ID),
            ConversationId::fromString(self::CONVERSATION_ID),
            UserId::fromString(self::AUTHOR_ID),
            null,
            ClientMessageId::fromString(self::CLIENT_ID),
            new \DateTimeImmutable('2026-07-26T09:00:00+00:00'),
            null,
            $deletedAt,
        );

        self::assertTrue($message->isDeleted());
        self::assertNull($message->content());
        self::assertEquals($deletedAt, $message->deletedAt());
        self::assertSame([], $message->releaseEvents(), 'reconstitute() n\'enregistre jamais d\'evenement.');
    }

    public function testDeletingForEveryoneErasesTheContentAndRecordsTheFact(): void
    {
        $message = self::send();
        $message->releaseEvents();
        $deletedAt = new \DateTimeImmutable('2026-07-26T11:00:00+00:00');

        $message->deleteForEveryone(UserId::fromString(self::AUTHOR_ID), $deletedAt);

        self::assertTrue($message->isDeleted());
        self::assertNull($message->content(), 'La charge utile doit etre reellement effacee.');
        self::assertEquals($deletedAt, $message->deletedAt());

        $events = $message->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(MessageWasDeleted::class, $events[0]);
        self::assertEquals($deletedAt, $events[0]->deletedAt);
    }

    public function testOnlyTheAuthorCanDelete(): void
    {
        $message = self::send();

        $this->expectException(NotTheAuthorException::class);

        $message->deleteForEveryone(UserId::fromString(self::OTHER_USER_ID), new \DateTimeImmutable());
    }

    /**
     * Le rejeu n'enregistre AUCUN evenement : c'est ce qui fait que DELETE reste
     * l'operation idempotente que HTTP promet, sans un seul `if` dans le handler.
     */
    public function testDeletingTwiceRecordsNothingAndKeepsTheFirstInstant(): void
    {
        $message = self::send();
        $message->releaseEvents();
        $first = new \DateTimeImmutable('2026-07-26T11:00:00+00:00');

        $message->deleteForEveryone(UserId::fromString(self::AUTHOR_ID), $first);
        $message->releaseEvents();

        $message->deleteForEveryone(
            UserId::fromString(self::AUTHOR_ID),
            new \DateTimeImmutable('2026-07-26T12:00:00+00:00'),
        );

        self::assertSame([], $message->releaseEvents());
        self::assertEquals($first, $message->deletedAt(), 'Le premier instant est conserve.');
    }

    public function testEditingReplacesTheContentAndStampsTheInstant(): void
    {
        $message = self::send();
        $message->releaseEvents();
        $editedAt = new \DateTimeImmutable('2026-07-26T09:05:00+00:00');

        $message->edit(UserId::fromString(self::AUTHOR_ID), MessageContent::fromString('bonsoir'), $editedAt);

        self::assertSame('bonsoir', $message->content()?->toString());
        self::assertEquals($editedAt, $message->editedAt());

        $events = $message->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(MessageWasEdited::class, $events[0]);
        self::assertSame('bonsoir', $events[0]->content);
    }

    public function testEditingIsAllowedUntilTheWindowCloses(): void
    {
        $message = self::send();

        $message->edit(
            UserId::fromString(self::AUTHOR_ID),
            MessageContent::fromString('juste a temps'),
            new \DateTimeImmutable('2026-07-26T09:00:00+00:00')
                ->modify(sprintf('+%d seconds', Message::EDIT_WINDOW_SECONDS)),
        );

        self::assertSame('juste a temps', $message->content()?->toString());
    }

    public function testEditingAfterTheWindowIsRefused(): void
    {
        $message = self::send();

        $this->expectException(EditWindowExpiredException::class);

        $message->edit(
            UserId::fromString(self::AUTHOR_ID),
            MessageContent::fromString('trop tard'),
            new \DateTimeImmutable('2026-07-26T09:00:00+00:00')
                ->modify(sprintf('+%d seconds', Message::EDIT_WINDOW_SECONDS + 1)),
        );
    }

    public function testOnlyTheAuthorCanEdit(): void
    {
        $message = self::send();

        $this->expectException(NotTheAuthorException::class);

        $message->edit(
            UserId::fromString(self::OTHER_USER_ID),
            MessageContent::fromString('pas a moi'),
            new \DateTimeImmutable('2026-07-26T09:01:00+00:00'),
        );
    }

    public function testATombstoneCannotBeEdited(): void
    {
        $message = self::send();
        $message->deleteForEveryone(
            UserId::fromString(self::AUTHOR_ID),
            new \DateTimeImmutable('2026-07-26T09:01:00+00:00'),
        );

        $this->expectException(MessageAlreadyDeletedException::class);

        $message->edit(
            UserId::fromString(self::AUTHOR_ID),
            MessageContent::fromString('ressusciter'),
            new \DateTimeImmutable('2026-07-26T09:02:00+00:00'),
        );
    }

    /** Meme mecanique que le rejeu d'envoi : rien d'enregistre, donc rien de republie. */
    public function testEditingWithTheSameContentRecordsNothing(): void
    {
        $message = self::send();
        $message->releaseEvents();

        $message->edit(
            UserId::fromString(self::AUTHOR_ID),
            MessageContent::fromString('bonjour'),
            new \DateTimeImmutable('2026-07-26T09:01:00+00:00'),
        );

        self::assertSame([], $message->releaseEvents());
        self::assertNull($message->editedAt(), 'Un no-op ne marque pas le message comme modifie.');
    }

    /**
     * La fenetre ne se controle qu'apres le no-op : rejouer a l'identique un
     * PATCH deja applique reste sans effet passe quinze minutes, au lieu de
     * repondre 403 pour un changement qui n'aurait rien change.
     */
    public function testEditingWithTheSameContentAfterTheWindowIsStillANoOp(): void
    {
        $message = self::send();
        $message->releaseEvents();

        $message->edit(
            UserId::fromString(self::AUTHOR_ID),
            MessageContent::fromString('bonjour'),
            new \DateTimeImmutable('2026-07-26T09:00:00+00:00')
                ->modify(sprintf('+%d seconds', Message::EDIT_WINDOW_SECONDS + 1)),
        );

        self::assertSame([], $message->releaseEvents());
        self::assertNull($message->editedAt());
    }

    private static function send(): Message
    {
        return Message::send(
            MessageId::fromString(self::MESSAGE_ID),
            ConversationId::fromString(self::CONVERSATION_ID),
            UserId::fromString(self::AUTHOR_ID),
            MessageContent::fromString('bonjour'),
            ClientMessageId::fromString(self::CLIENT_ID),
            new \DateTimeImmutable('2026-07-26T09:00:00+00:00'),
        );
    }
}
