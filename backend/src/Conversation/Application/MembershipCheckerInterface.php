<?php

declare(strict_types=1);

namespace App\Conversation\Application;

use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\UserId;

interface MembershipCheckerInterface
{
    public function isMember(ConversationId $conversationId, UserId $userId): bool;

    public function isAdmin(ConversationId $conversationId, UserId $userId): bool;
}
