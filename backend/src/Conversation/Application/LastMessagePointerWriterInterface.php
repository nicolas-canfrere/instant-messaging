<?php

declare(strict_types=1);

namespace App\Conversation\Application;

use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\MessageId;
use App\Shared\Domain\Identifier\UserId;

/**
 * Pointeur denormalise vers le dernier message. Il ne fait pas partie de
 * l'agregat Conversation : c'est un cache d'affichage, mis a jour en reaction a
 * un fait publie par Message, pas une decision metier de Conversation.
 */
interface LastMessagePointerWriterInterface
{
    /** @return bool false si la conversation n'existe pas */
    public function record(
        ConversationId $conversationId,
        MessageId $messageId,
        UserId $senderId,
        \DateTimeImmutable $sentAt,
        string $preview,
    ): bool;
}
