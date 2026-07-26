<?php

declare(strict_types=1);

namespace App\Message\Application\Query;

use App\Message\Domain\ClientMessageId;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\MessageId;
use App\Shared\Domain\Identifier\UserId;

interface MessageReaderInterface
{
    public function idByClientKey(UserId $senderId, ClientMessageId $clientMessageId): ?MessageId;

    public function view(ConversationId $conversationId, MessageId $messageId): ?MessageView;
}
