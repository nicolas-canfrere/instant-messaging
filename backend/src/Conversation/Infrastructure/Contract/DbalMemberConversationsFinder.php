<?php

declare(strict_types=1);

namespace App\Conversation\Infrastructure\Contract;

use App\Conversation\Application\Contract\MemberConversationsFinderInterface;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\UserId;
use Doctrine\DBAL\Connection;

/** Conversation est le SEUL contexte a lire conversation_members. */
final readonly class DbalMemberConversationsFinder implements MemberConversationsFinderInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function conversationIdsFor(UserId $userId): array
    {
        /** @var list<array{conversation_id: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT conversation_id
                FROM conversation_members
                WHERE user_id = :user_id
                SQL,
            ['user_id' => $userId->toString()],
        );

        return array_map(
            static fn(array $row): ConversationId => ConversationId::fromString($row['conversation_id']),
            $rows,
        );
    }
}
