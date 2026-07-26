<?php

declare(strict_types=1);

namespace App\Message\Application\Command;

use App\Shared\Application\Bus\CommandInterface;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\MessageId;
use App\Shared\Domain\Identifier\UserId;

final readonly class DeleteMessageCommand implements CommandInterface
{
    public function __construct(
        public ConversationId $conversationId,
        public MessageId $messageId,
        public UserId $actorId,
    ) {
    }
}
