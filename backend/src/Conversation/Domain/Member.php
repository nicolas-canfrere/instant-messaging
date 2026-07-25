<?php

declare(strict_types=1);

namespace App\Conversation\Domain;

use App\Shared\Domain\Identifier\UserId;

final readonly class Member
{
    public function __construct(
        public UserId $userId,
        public MemberRole $role,
        public \DateTimeImmutable $joinedAt,
    ) {
    }
}
