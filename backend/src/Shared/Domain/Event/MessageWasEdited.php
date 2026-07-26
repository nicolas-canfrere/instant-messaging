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
 * ne transporte que des types de Shared et des scalaires.
 *
 * Il porte un ETAT complet, pas un delta. Un « ajouter 3 caracteres en position
 * 12 » exigerait un ordre de livraison garanti, que SSE ne promet pas. Un etat
 * complet est idempotent et commutatif : c'est ce qui permet de se passer
 * d'accuse, de sequence et de rejeu.
 *
 * Modifier cette signature est un changement cassant.
 */
final readonly class MessageWasEdited implements DomainEventInterface
{
    public function __construct(
        public MessageId $messageId,
        public ConversationId $conversationId,
        public UserId $senderId,
        public string $content,
        public \DateTimeImmutable $editedAt,
    ) {
    }
}
