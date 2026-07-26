<?php

declare(strict_types=1);

namespace App\Message\Infrastructure\Persistence;

use App\Message\Application\Query\MessageReaderInterface;
use App\Message\Application\Query\MessageView;
use App\Message\Domain\ClientMessageId;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\MessageId;
use App\Shared\Domain\Identifier\UserId;
use App\Shared\Infrastructure\Persistence\DatabaseTimestamp;
use Doctrine\DBAL\Connection;

final readonly class DbalMessageReader implements MessageReaderInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function idByClientKey(UserId $senderId, ClientMessageId $clientMessageId): ?MessageId
    {
        $id = $this->connection->fetchOne(
            <<<'SQL'
                SELECT id
                FROM messages
                WHERE sender_id = :sender_id
                  AND client_message_id = :client_message_id
                SQL,
            [
                'sender_id' => $senderId->toString(),
                'client_message_id' => $clientMessageId->toString(),
            ],
        );

        return is_string($id) ? MessageId::fromString($id) : null;
    }

    public function view(ConversationId $conversationId, MessageId $messageId): ?MessageView
    {
        /** @var array{id: string, conversation_id: string, sender_id: string, content: string|null, client_message_id: string, created_at: string, edited_at: string|null, deleted_at: string|null}|false $row */
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT id, conversation_id, sender_id, content, client_message_id, created_at, edited_at, deleted_at
                FROM messages
                WHERE id = :id
                  AND conversation_id = :conversation_id
                SQL,
            [
                'id' => $messageId->toString(),
                'conversation_id' => $conversationId->toString(),
            ],
        );

        if (false === $row) {
            return null;
        }

        return new MessageView(
            $row['id'],
            $row['conversation_id'],
            $row['sender_id'],
            $row['content'],
            $row['client_message_id'],
            DatabaseTimestamp::toAtom($row['created_at']),
            DatabaseTimestamp::toAtom($row['edited_at']),
            DatabaseTimestamp::toAtom($row['deleted_at']),
        );
    }
}
