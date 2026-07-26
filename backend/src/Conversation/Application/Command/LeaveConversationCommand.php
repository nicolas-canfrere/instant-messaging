<?php

declare(strict_types=1);

namespace App\Conversation\Application\Command;

use App\Shared\Application\Bus\CommandInterface;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\UserId;

/**
 * Partir de son propre chef. Distincte de RemoveMemberCommand, qui est le geste
 * d'un admin sur quelqu'un d'autre : les deux n'ont ni les memes regles
 * d'autorisation ni les memes conditions d'echec.
 */
final readonly class LeaveConversationCommand implements CommandInterface
{
    public function __construct(
        public ConversationId $conversationId,
        public UserId $userId,
    ) {
    }
}
