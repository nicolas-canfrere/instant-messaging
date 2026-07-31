<?php

declare(strict_types=1);

namespace App\Shared\Domain\Event;

use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\MediaId;
use App\Shared\Domain\Identifier\MessageId;

/**
 * Emis par Message, ecoute par Realtime. Le fait metier que `MediaWasProcessed`
 * ne pouvait pas exprimer : Media ignore l'existence des messages, donc seul
 * Message sait QUELS messages portent ce media et dans QUEL fil.
 *
 * « BecameReady » au sens de « a fini d'etre traite » : un media REFUSE passe
 * par cet evenement lui aussi. Sans lui, le message porteur resterait « en
 * cours… » pour toujours chez tous les membres du fil.
 *
 * Ne transporte QUE des identifiants. Ni statut, ni dimensions, ni URL : la
 * vue complete est relue et RESIGNEE a la publication. Une URL signee vit
 * quinze minutes — la mettre dans un evenement, c'est y mettre du perimable.
 *
 * Modifier cette signature est un changement cassant.
 */
final readonly class MessageMediaBecameReady implements DomainEventInterface
{
    public function __construct(
        public MessageId $messageId,
        public ConversationId $conversationId,
        public MediaId $mediaId,
    ) {
    }
}
