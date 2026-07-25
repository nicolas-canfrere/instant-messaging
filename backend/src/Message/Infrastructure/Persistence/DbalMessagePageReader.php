<?php

declare(strict_types=1);

namespace App\Message\Infrastructure\Persistence;

use App\Message\Application\Query\MessagePageReaderInterface;
use App\Message\Application\Query\MessageView;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\MessageId;
use App\Shared\Infrastructure\Persistence\DatabaseTimestamp;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Pagination par curseur : consomme l'index (conversation_id, id DESC).
 *
 * Un OFFSET deviendrait faux des qu'un message arrive pendant la remontee — la
 * fenetre se decalerait et ferait sauter un element. Le curseur, lui, designe
 * une position absolue dans l'ordre : les messages PLUS RECENTS que le curseur
 * n'affectent jamais les pages suivantes.
 *
 * Ce que le curseur ne couvre pas : l'ULID est frappe avant le commit, donc une
 * transaction lente peut rendre visible un identifiant PLUS ANCIEN que le
 * curseur deja servi. Ce message-la ne sera pas vu par la remontee. C'est
 * pourquoi le front doit s'abonner au flux temps reel AVANT de charger
 * l'historique : le SSE rattrape ce que la pagination ne peut pas voir.
 *
 * Deux requetes ecrites en entier plutot qu'une seule conditionnelle : chacune
 * se lit d'un bloc et son plan d'execution est evident.
 */
final readonly class DbalMessagePageReader implements MessagePageReaderInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function page(ConversationId $conversationId, ?MessageId $before, int $limit): array
    {
        $parameters = [
            'conversation_id' => $conversationId->toString(),
            'limit' => $limit,
        ];

        if (null !== $before) {
            $parameters['before'] = $before->toString();
        }

        /** @var list<array{id: string, conversation_id: string, sender_id: string, content: string, client_message_id: string, created_at: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            null === $before
                ? <<<'SQL'
                    SELECT id, conversation_id, sender_id, content, client_message_id, created_at
                    FROM messages
                    WHERE conversation_id = :conversation_id
                    ORDER BY id DESC
                    LIMIT :limit
                    SQL
                : <<<'SQL'
                    SELECT id, conversation_id, sender_id, content, client_message_id, created_at
                    FROM messages
                    WHERE conversation_id = :conversation_id
                      AND id < :before
                    ORDER BY id DESC
                    LIMIT :limit
                    SQL,
            $parameters,
            ['limit' => ParameterType::INTEGER],
        );

        return array_map(
            static fn(array $row): MessageView => new MessageView(
                $row['id'],
                $row['conversation_id'],
                $row['sender_id'],
                $row['content'],
                $row['client_message_id'],
                DatabaseTimestamp::toAtom($row['created_at']),
            ),
            $rows,
        );
    }
}
