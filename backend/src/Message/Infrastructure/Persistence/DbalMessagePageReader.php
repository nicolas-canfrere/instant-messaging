<?php

declare(strict_types=1);

namespace App\Message\Infrastructure\Persistence;

use App\Media\Application\Contract\MediaFinderInterface;
use App\Media\Application\Contract\MediaView;
use App\Message\Application\Query\MessagePageReaderInterface;
use App\Message\Application\Query\MessageView;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\MediaId;
use App\Shared\Domain\Identifier\MessageId;
use App\Shared\Infrastructure\Persistence\DatabaseTimestamp;
use Doctrine\DBAL\ArrayParameterType;
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
    public function __construct(
        private Connection $connection,
        private MediaFinderInterface $mediaFinder,
    ) {
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

        /** @var list<array{id: string, conversation_id: string, sender_id: string, content: string|null, client_message_id: string, created_at: string, edited_at: string|null, deleted_at: string|null}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            null === $before
                ? <<<'SQL'
                    SELECT id, conversation_id, sender_id, content, client_message_id, created_at, edited_at, deleted_at
                    FROM messages
                    WHERE conversation_id = :conversation_id
                    ORDER BY id DESC
                    LIMIT :limit
                    SQL
                : <<<'SQL'
                    SELECT id, conversation_id, sender_id, content, client_message_id, created_at, edited_at, deleted_at
                    FROM messages
                    WHERE conversation_id = :conversation_id
                      AND id < :before
                    ORDER BY id DESC
                    LIMIT :limit
                    SQL,
            $parameters,
            ['limit' => ParameterType::INTEGER],
        );

        $mediaByMessage = $this->mediaOf(array_column($rows, 'id'));

        return array_map(
            static fn(array $row): MessageView => new MessageView(
                $row['id'],
                $row['conversation_id'],
                $row['sender_id'],
                $row['content'],
                $row['client_message_id'],
                DatabaseTimestamp::toAtom($row['created_at']),
                DatabaseTimestamp::toAtom($row['edited_at']),
                DatabaseTimestamp::toAtom($row['deleted_at']),
                $mediaByMessage[$row['id']] ?? [],
            ),
            $rows,
        );
    }

    /**
     * DEUX requetes pour toute la page, jamais deux par message : le N+1 est le
     * piege evident ici, et `MessageMediaReadTest` le verrouille par un
     * compteur de requetes.
     *
     * @param list<string> $messageIds
     *
     * @return array<string, list<MediaView>> indexe par message, dans l'ordre d'affichage
     */
    private function mediaOf(array $messageIds): array
    {
        if ([] === $messageIds) {
            return [];
        }

        /** @var list<array{message_id: string, media_id: string}> $links */
        $links = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT message_id, media_id
                FROM message_media
                WHERE message_id IN (:message_ids)
                ORDER BY message_id, position
                SQL,
            ['message_ids' => $messageIds],
            ['message_ids' => ArrayParameterType::STRING],
        );

        if ([] === $links) {
            return [];
        }

        // `ORDER BY position` ci-dessus porte jusqu'ici : les vues sont
        // indexees par ULID, mais c'est l'ordre des LIAISONS qu'on parcourt.
        $views = $this->mediaFinder->viewsFor(array_map(
            static fn(array $link): MediaId => MediaId::fromString($link['media_id']),
            $links,
        ));

        $mediaByMessage = [];
        foreach ($links as $link) {
            // Un media absent des vues a disparu entre les deux requetes (une
            // purge, par exemple). La liaison est alors ignoree plutot que
            // rendue vide : une lecture d'historique n'a pas a echouer pour ca.
            if (isset($views[$link['media_id']])) {
                $mediaByMessage[$link['message_id']][] = $views[$link['media_id']];
            }
        }

        return $mediaByMessage;
    }
}
