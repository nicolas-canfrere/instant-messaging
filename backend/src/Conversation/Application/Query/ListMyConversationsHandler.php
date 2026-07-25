<?php

declare(strict_types=1);

namespace App\Conversation\Application\Query;

use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Cote lecture : SQL direct vers un DTO, sans passer par le domaine.
 *
 * Aucune jointure vers `messages` : l'apercu est denormalise sur la
 * conversation, ecrit par le listener qui reagira a MessageWasSent.
 * Conversation ne lit donc jamais la table d'un autre contexte, et la liste
 * s'affiche sans chercher le dernier message de chaque fil.
 */
#[AsMessageHandler(bus: 'query.bus')]
final readonly class ListMyConversationsHandler
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return list<ConversationView> */
    public function __invoke(ListMyConversations $query): array
    {
        /** @var list<array{id: string, type: string, title: string|null, last_message_at: string|null, last_message_preview: string|null, last_message_sender_id: string|null}> $rows */
        $rows = $this->connection->fetchAllAssociative(
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
            ['user_id' => $query->userId->toString()],
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
}
