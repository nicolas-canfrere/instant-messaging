<?php

declare(strict_types=1);

namespace App\Message\Infrastructure\Persistence;

use App\Message\Domain\ClientMessageId;
use App\Message\Domain\Message;
use App\Message\Domain\MessageContent;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\MessageId;
use App\Shared\Domain\Identifier\UserId;

/** Frontiere unique ou la ligne brute devient un type precis (PHPStan max). */
final readonly class MessageMapper
{
    /** @param array{id: string, conversation_id: string, sender_id: string, content: string, client_message_id: string, created_at: string} $row */
    public function fromRow(array $row): Message
    {
        return Message::reconstitute(
            MessageId::fromString($row['id']),
            ConversationId::fromString($row['conversation_id']),
            UserId::fromString($row['sender_id']),
            MessageContent::fromString($row['content']),
            ClientMessageId::fromString($row['client_message_id']),
            new \DateTimeImmutable($row['created_at']),
        );
    }
}
