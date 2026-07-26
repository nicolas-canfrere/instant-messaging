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
    public function record(
        ConversationId $conversationId,
        MessageId $messageId,
        UserId $senderId,
        \DateTimeImmutable $sentAt,
        string $preview,
    ): LastMessagePointerOutcome;

    /**
     * Reecrit l'apercu SI le message designe est toujours le dernier.
     *
     * `null` efface l'apercu : c'est le cas d'une suppression pour tous, ou la
     * copie doit disparaitre en meme temps que l'original.
     *
     * @return bool true si une ligne a ete touchee
     */
    public function refreshPreview(
        ConversationId $conversationId,
        MessageId $messageId,
        ?string $preview,
    ): bool;
}
