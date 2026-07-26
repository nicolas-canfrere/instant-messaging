<?php

declare(strict_types=1);

namespace App\Conversation\Application\Command;

use App\Shared\Application\Bus\CommandInterface;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\MessageId;

final readonly class RefreshLastMessagePreviewCommand implements CommandInterface
{
    public function __construct(
        public ConversationId $conversationId,
        public MessageId $messageId,
        /** `null` efface l'apercu : le message a ete supprime pour tous. */
        public ?string $preview,
    ) {
    }
}
