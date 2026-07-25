<?php

declare(strict_types=1);

namespace App\Message\Infrastructure\Persistence;

use App\Message\Domain\Message;
use App\Message\Domain\MessageRepositoryInterface;
use App\Shared\Application\Event\DomainEventCollectorInterface;
use Doctrine\DBAL\Connection;

final readonly class DbalMessageRepository implements MessageRepositoryInterface
{
    public function __construct(
        private Connection $connection,
        private MessageMapper $mapper,
        private DomainEventCollectorInterface $collector,
    ) {
    }

    public function insertIfAbsent(Message $message): ?Message
    {
        // Zero ligne rendue = la cle (sender_id, client_message_id) existe deja.
        // Le rejeu passe par du controle de flux ordinaire, pas par une
        // exception d'unicite rattrapee.
        $inserted = $this->connection->fetchOne(
            <<<'SQL'
                INSERT INTO messages (id, conversation_id, sender_id, content, client_message_id, created_at)
                VALUES (:id, :conversation_id, :sender_id, :content, :client_message_id, :created_at)
                ON CONFLICT (sender_id, client_message_id) DO NOTHING
                RETURNING id
                SQL,
            [
                'id' => $message->id()->toString(),
                'conversation_id' => $message->conversationId()->toString(),
                'sender_id' => $message->senderId()->toString(),
                'content' => $message->content()->toString(),
                'client_message_id' => $message->clientMessageId()->toString(),
                'created_at' => $message->createdAt()->format(\DateTimeInterface::ATOM),
            ],
        );

        if (false === $inserted) {
            return $this->ofClientKey(
                $message->senderId()->toString(),
                $message->clientMessageId()->toString(),
            );
        }

        // Message n'ecrit PAS dans conversations : le pointeur est mis a jour
        // par Conversation, qui ecoute MessageWasSent.
        $this->collector->collect(...$message->releaseEvents());

        return null;
    }

    private function ofClientKey(string $senderId, string $clientMessageId): Message
    {
        /** @var array{id: string, conversation_id: string, sender_id: string, content: string, client_message_id: string, created_at: string} $row */
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT id, conversation_id, sender_id, content, client_message_id, created_at
                FROM messages
                WHERE sender_id = :sender_id
                  AND client_message_id = :client_message_id
                SQL,
            ['sender_id' => $senderId, 'client_message_id' => $clientMessageId],
        );

        return $this->mapper->fromRow($row);
    }
}
