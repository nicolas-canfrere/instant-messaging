<?php

declare(strict_types=1);

namespace App\Conversation\Application\Command;

use App\Shared\Application\Bus\CommandInterface;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\UserId;

final readonly class AdvanceReceiptsCommand implements CommandInterface
{
    public function __construct(
        public ConversationId $conversationId,
        public UserId $userId,
        public ?string $deliveredUpTo,
        public ?string $readUpTo,
    ) {
    }
}
