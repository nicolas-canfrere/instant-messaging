<?php

declare(strict_types=1);

namespace App\Message\Infrastructure\Contract;

use App\Message\Application\Contract\UnreadCounterInterface;
use App\Shared\Domain\Identifier\UserId;
use Doctrine\DBAL\Connection;

final readonly class DbalUnreadCounter implements UnreadCounterInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function countUnread(UserId $reader, array $watermarkByConversation): array
    {
        if ([] === $watermarkByConversation) {
            return [];
        }

        $pairs = [];
        foreach ($watermarkByConversation as $conversationId => $watermark) {
            $pairs[] = ['conversation_id' => $conversationId, 'watermark' => $watermark];
        }

        /** @var list<array{conversation_id: string, unread: int}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            // `jsonb_to_recordset` transporte les paires (conversation, watermark)
            // en UN SEUL parametre lie. `IN (...)` ne conviendrait pas : chaque
            // conversation a SON propre watermark, donc il faut des paires, pas
            // une liste. Et ArrayParameterType ne produit pas un tableau
            // PostgreSQL — il developpe en (?, ?, ?), ce qui rendrait
            // `:ids::text[]` invalide.
            //
            // COALESCE(w.watermark, '') : la chaine vide precede tout ULID, donc
            // un membre qui n'a jamais rien lu a tous ses messages non lus, sans
            // branche conditionnelle.
            //
            // LEFT JOIN et non JOIN : une conversation sans message non lu doit
            // rendre 0, pas disparaitre du resultat.
            <<<'SQL'
                SELECT w.conversation_id, COUNT(m.id) AS unread
                  FROM jsonb_to_recordset(:pairs::jsonb) AS w(conversation_id text, watermark text)
                  LEFT JOIN messages m
                         ON m.conversation_id = w.conversation_id
                        AND m.id > COALESCE(w.watermark, '')
                        AND m.sender_id <> :reader_id
                 GROUP BY w.conversation_id
                SQL,
            [
                'pairs' => json_encode($pairs, \JSON_THROW_ON_ERROR),
                'reader_id' => $reader->toString(),
            ],
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['conversation_id']] = $row['unread'];
        }

        return $counts;
    }
}
