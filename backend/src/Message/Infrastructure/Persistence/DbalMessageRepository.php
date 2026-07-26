<?php

declare(strict_types=1);

namespace App\Message\Infrastructure\Persistence;

use App\Message\Domain\ClientMessageId;
use App\Message\Domain\Message;
use App\Message\Domain\MessageNotFoundException;
use App\Message\Domain\MessageRepositoryInterface;
use App\Shared\Application\Event\DomainEventCollectorInterface;
use App\Shared\Domain\Identifier\UserId;
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
        // insertIfAbsent() n'insere jamais que le resultat de Message::send() :
        // un tombstone n'est jamais insere, il resulte d'une edition ulterieure
        // d'une ligne existante. Le contenu est donc toujours present ici.
        $content = $message->content();
        if (null === $content) {
            throw new \LogicException('Un message fraichement envoye a toujours un contenu.');
        }

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
                'content' => $content->toString(),
                'client_message_id' => $message->clientMessageId()->toString(),
                'created_at' => $message->createdAt()->format(\DateTimeInterface::ATOM),
            ],
        );

        if (false === $inserted) {
            return $this->ofClientKey($message->senderId(), $message->clientMessageId());
        }

        // Message n'ecrit PAS dans conversations : le pointeur est mis a jour
        // par Conversation, qui ecoute MessageWasSent.
        $this->collector->collect(...$message->releaseEvents());

        return null;
    }

    private function ofClientKey(UserId $senderId, ClientMessageId $clientMessageId): Message
    {
        /** @var array{id: string, conversation_id: string, sender_id: string, content: string|null, client_message_id: string, created_at: string, edited_at: string|null, deleted_at: string|null}|false $row */
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT id, conversation_id, sender_id, content, client_message_id, created_at, edited_at, deleted_at
                FROM messages
                WHERE sender_id = :sender_id
                  AND client_message_id = :client_message_id
                SQL,
            [
                'sender_id' => $senderId->toString(),
                'client_message_id' => $clientMessageId->toString(),
            ],
        );

        // L'INSERT vient d'entrer en conflit, donc la ligne existait a l'instant.
        // Elle peut malgre tout avoir disparu entre les deux requetes : on le
        // dit, plutot que de l'affirmer par une annotation a PHPStan.
        if (false === $row) {
            throw MessageNotFoundException::forClientKey($clientMessageId);
        }

        return $this->mapper->fromRow($row);
    }
}
