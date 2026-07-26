<?php

declare(strict_types=1);

namespace App\Message\Application\Query;

use App\Shared\Application\Bus\QueryInterface;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\MessageId;
use App\Shared\Domain\Identifier\UserId;

/** @implements QueryInterface<MessageView> */
final readonly class GetMessageQuery implements QueryInterface
{
    public function __construct(
        public ConversationId $conversationId,
        public MessageId $messageId,
        public UserId $requestedBy,
    ) {
    }
}
