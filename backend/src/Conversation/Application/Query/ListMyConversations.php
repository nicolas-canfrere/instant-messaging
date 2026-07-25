<?php

declare(strict_types=1);

namespace App\Conversation\Application\Query;

use App\Shared\Domain\Identifier\UserId;

final readonly class ListMyConversations
{
    public function __construct(public UserId $userId)
    {
    }
}
