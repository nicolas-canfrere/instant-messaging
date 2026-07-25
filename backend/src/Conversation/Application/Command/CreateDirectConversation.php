<?php

declare(strict_types=1);

namespace App\Conversation\Application\Command;

use App\Shared\Domain\Identifier\UserId;

final readonly class CreateDirectConversation
{
    public function __construct(
        public UserId $initiator,
        public UserId $peer,
    ) {
    }
}
