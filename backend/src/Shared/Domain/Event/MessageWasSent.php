<?php

declare(strict_types=1);

namespace App\Shared\Domain\Event;

use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\MessageId;
use App\Shared\Domain\Identifier\UserId;

/**
 * Emis par Message, ecoute par Realtime ET par Conversation.
 *
 * Le contenu voyage en `string`, PAS en MessageContent : un evenement partage
 * ne transporte que des types de Shared et des scalaires. L'inverse ferait
 * dependre Shared du contexte Message. L'invariant de validite a de toute facon
 * deja ete verifie a la construction du MessageContent, en amont.
 *
 * Modifier cette signature est un changement cassant.
 */
final readonly class MessageWasSent implements DomainEventInterface
{
    public function __construct(
        public MessageId $messageId,
        public ConversationId $conversationId,
        public UserId $senderId,
        public string $content,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
