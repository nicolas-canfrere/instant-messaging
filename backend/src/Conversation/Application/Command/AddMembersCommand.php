<?php

declare(strict_types=1);

namespace App\Conversation\Application\Command;

use App\Shared\Application\Bus\CommandInterface;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\UserId;

final readonly class AddMembersCommand implements CommandInterface
{
    /** @param list<UserId> $userIds */
    public function __construct(
        public ConversationId $conversationId,
        public array $userIds,
    ) {
    }
}
