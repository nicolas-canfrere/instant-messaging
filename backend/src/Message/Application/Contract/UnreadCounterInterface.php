<?php

declare(strict_types=1);

namespace App\Message\Application\Contract;

use App\Shared\Domain\Identifier\UserId;

/**
 * Surface PUBLIEE de Message : « combien de messages cette personne n'a-t-elle
 * pas lus ». Conversation possede le watermark, Message possede la table des
 * messages ; ni l'un ni l'autre ne lit la table de l'autre (ADR 0001).
 *
 * Batchee par conception. L'ecran d'accueil affiche N conversations ; un
 * contrat qui repondrait pour une seule produirait N requetes. La signature rend
 * la version lente impossible a ecrire.
 *
 * Modifier cette signature est un changement cassant.
 */
interface UnreadCounterInterface
{
    /**
     * @param array<string, string|null> $watermarkByConversation conversationId => last_read_message_id
     *
     * @return array<string, int> conversationId => nombre de non-lus
     */
    public function countUnread(UserId $reader, array $watermarkByConversation): array;
}
