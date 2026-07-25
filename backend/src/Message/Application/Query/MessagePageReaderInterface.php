<?php

declare(strict_types=1);

namespace App\Message\Application\Query;

use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\MessageId;

interface MessagePageReaderInterface
{
    /**
     * @param positive-int $limit
     *
     * @return list<MessageView> du plus recent au plus ancien
     */
    public function page(ConversationId $conversationId, ?MessageId $before, int $limit): array;
}
