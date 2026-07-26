<?php

declare(strict_types=1);

namespace App\Shared\Domain\Event;

use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\MessageId;
use App\Shared\Domain\Identifier\UserId;

/**
 * Emis par Message, ecoute par Realtime ET par Conversation — c'est ce qui le
 * fait vivre dans Shared plutot que dans son contexte d'origine.
 *
 * Il ne transporte AUCUN contenu, et ce n'est pas un oubli : un evenement de
 * retractation qui embarquerait la charge utile qu'il retracte la diffuserait
 * a tout le monde par le hub.
 *
 * Modifier cette signature est un changement cassant.
 */
final readonly class MessageWasDeleted implements DomainEventInterface
{
    public function __construct(
        public MessageId $messageId,
        public ConversationId $conversationId,
        public UserId $senderId,
        public \DateTimeImmutable $deletedAt,
    ) {
    }
}
