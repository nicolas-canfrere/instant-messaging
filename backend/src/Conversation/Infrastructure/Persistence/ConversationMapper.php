<?php

declare(strict_types=1);

namespace App\Conversation\Infrastructure\Persistence;

use App\Conversation\Domain\Conversation;
use App\Conversation\Domain\ConversationType;
use App\Conversation\Domain\Member;
use App\Conversation\Domain\MemberRole;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\UserId;

/** Frontiere unique ou la ligne brute devient un type precis (PHPStan max). */
final readonly class ConversationMapper
{
    /**
     * @param array{id: string, type: string, title: string|null, direct_key: string|null, created_by: string, created_at: string} $row
     * @param list<array{user_id: string, role: string, joined_at: string}>                                                        $memberRows
     */
    public function fromRows(array $row, array $memberRows): Conversation
    {
        $members = array_map(
            static fn(array $memberRow): Member => new Member(
                UserId::fromString($memberRow['user_id']),
                MemberRole::from($memberRow['role']),
                new \DateTimeImmutable($memberRow['joined_at']),
            ),
            $memberRows,
        );

        return Conversation::reconstitute(
            ConversationId::fromString($row['id']),
            ConversationType::from($row['type']),
            $row['title'],
            null === $row['direct_key'] ? null : DirectKeyHydrator::fromString($row['direct_key']),
            UserId::fromString($row['created_by']),
            new \DateTimeImmutable($row['created_at']),
            $members,
        );
    }
}
