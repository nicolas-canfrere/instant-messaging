<?php

declare(strict_types=1);

namespace App\Conversation\Infrastructure\Persistence;

use App\Conversation\Application\MembershipCheckerInterface;
use App\Conversation\Domain\MemberRole;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\UserId;
use Doctrine\DBAL\Connection;

final readonly class DbalMembershipChecker implements MembershipCheckerInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function isMember(ConversationId $conversationId, UserId $userId): bool
    {
        return null !== $this->roleOf($conversationId, $userId);
    }

    public function isAdmin(ConversationId $conversationId, UserId $userId): bool
    {
        return MemberRole::Admin->value === $this->roleOf($conversationId, $userId);
    }

    private function roleOf(ConversationId $conversationId, UserId $userId): ?string
    {
        $role = $this->connection->fetchOne(
            <<<'SQL'
                SELECT role
                FROM conversation_members
                WHERE conversation_id = :conversation_id
                  AND user_id = :user_id
                SQL,
            [
                'conversation_id' => $conversationId->toString(),
                'user_id' => $userId->toString(),
            ],
        );

        return is_string($role) ? $role : null;
    }
}
