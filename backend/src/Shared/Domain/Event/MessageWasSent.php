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
 * Meme raison pour `clientMessageId`, `string` et non ClientMessageId : ce VO
 * appartient au contexte Message. Il voyage parce que Realtime doit le
 * remettre dans la charge utile `message.created` — c'est la cle par laquelle
 * le front reconcilie son envoi optimiste avec l'echo SSE, qui lui parvient
 * avant meme la reponse du POST.
 *
 * `mediaIds` voyage en `list<string>` pour la meme raison : `MediaId` est bien
 * de Shared, mais une liste de scalaires suffit ici et Realtime n'en fait qu'un
 * argument de lecture. Les identifiants sont dans l'ORDRE D'AFFICHAGE
 * (`message_media.position`), et Realtime doit preserver cet ordre.
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
        public string $clientMessageId,
        public \DateTimeImmutable $createdAt,
        /** @var list<string> dans l'ordre d'affichage ; vide pour un message texte-seul */
        public array $mediaIds = [],
    ) {
    }
}
