<?php

declare(strict_types=1);

namespace App\Tests\Unit\Message\Domain;

use App\Message\Domain\ClientMessageId;
use App\Message\Domain\Message;
use App\Message\Domain\MessageContent;
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
