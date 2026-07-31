<?php

declare(strict_types=1);

namespace App\Message\Infrastructure\Persistence;

use App\Message\Application\Query\MessagesCarryingMediaReaderInterface;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\MediaId;
use App\Shared\Domain\Identifier\MessageId;
use Doctrine\DBAL\Connection;

final readonly class DbalMessagesCarryingMediaReader implements MessagesCarryingMediaReaderInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function carrying(MediaId $mediaId): array
    {
        // La jointure sert a rapporter `conversation_id` : Realtime en a besoin
        // pour construire le topic, et le lui faire chercher lui-meme
        // l'obligerait a lire la table d'un autre contexte.
        //
        // Les deux tables appartiennent a Message : aucune frontiere franchie.
        /** @var list<array{message_id: string, conversation_id: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT mm.message_id,
                       m.conversation_id
                FROM message_media mm
                JOIN messages m ON m.id = mm.message_id
                WHERE mm.media_id = :media_id
                SQL,
            ['media_id' => $mediaId->toString()],
        );

        return array_map(
            static fn(array $row): array => [
                'messageId' => MessageId::fromString($row['message_id']),
                'conversationId' => ConversationId::fromString($row['conversation_id']),
            ],
            $rows,
        );
    }
}
