<?php

declare(strict_types=1);

namespace App\Conversation\Infrastructure\Persistence;

use App\Conversation\Application\Query\ConversationReaderInterface;
use App\Conversation\Application\Query\ConversationView;
use App\Conversation\Domain\DirectKey;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\UserId;
use Doctrine\DBAL\Connection;

/** Cote lecture : SQL direct vers un DTO, sans passer par le domaine. */
final readonly class DbalConversationReader implements ConversationReaderInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function forMember(UserId $userId): array
    {
        /** @var list<array{id: string, type: string, title: string|null, last_message_at: string|null, last_message_preview: string|null, last_message_sender_id: string|null}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            // Aucune jointure vers `messages` : l'apercu est denormalise sur la
            // conversation, ecrit par le listener qui reagira a MessageWasSent.
            // Conversation ne lit donc jamais la table d'un autre contexte, et
            // la liste s'affiche sans chercher le dernier message de chaque fil.
            <<<'SQL'
                SELECT c.id,
                       c.type,
                       c.title,
                       c.last_message_at,
                       c.last_message_preview,
                       c.last_message_sender_id
                FROM conversations c
                INNER JOIN conversation_members cm
                        ON cm.conversation_id = c.id AND cm.user_id = :user_id
                ORDER BY c.last_message_at DESC NULLS LAST, c.id DESC
                SQL,
            ['user_id' => $userId->toString()],
        );

        return array_map(
            static fn(array $row): ConversationView => new ConversationView(
                $row['id'],
                $row['type'],
                $row['title'],
                $row['last_message_at'],
                $row['last_message_preview'],
                $row['last_message_sender_id'],
            ),
            $rows,
        );
    }

    public function directIdForKey(DirectKey $key): ?ConversationId
    {
        $id = $this->connection->fetchOne(
            <<<'SQL'
                SELECT id
                FROM conversations
                WHERE direct_key = :direct_key
                SQL,
            ['direct_key' => $key->toString()],
        );

        return is_string($id) ? ConversationId::fromString($id) : null;
    }
}
