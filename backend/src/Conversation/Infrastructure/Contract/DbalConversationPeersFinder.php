<?php

declare(strict_types=1);

namespace App\Conversation\Infrastructure\Contract;

use App\Conversation\Application\Contract\ConversationPeersFinderInterface;
use App\Shared\Domain\Identifier\UserId;
use Doctrine\DBAL\Connection;

/** Conversation est le SEUL contexte a lire conversation_members. */
final readonly class DbalConversationPeersFinder implements ConversationPeersFinderInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function peerIdsFor(UserId $userId): array
    {
        /** @var list<array{user_id: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT DISTINCT peer.user_id
                FROM conversation_members mine
                INNER JOIN conversation_members peer
                        ON peer.conversation_id = mine.conversation_id
                       AND peer.user_id <> mine.user_id
                WHERE mine.user_id = :user_id
                SQL,
            ['user_id' => $userId->toString()],
        );

        return array_map(
            static fn(array $row): UserId => UserId::fromString($row['user_id']),
            $rows,
        );
    }
}
