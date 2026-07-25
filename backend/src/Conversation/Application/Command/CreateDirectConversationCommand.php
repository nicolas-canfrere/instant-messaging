<?php

declare(strict_types=1);

namespace App\Conversation\Application\Command;

use App\Shared\Application\Bus\CommandInterface;
use App\Shared\Domain\Identifier\UserId;

final readonly class CreateDirectConversationCommand implements CommandInterface
{
    public function __construct(
        public UserId $initiator,
        public UserId $peer,
    ) {
    }
}
