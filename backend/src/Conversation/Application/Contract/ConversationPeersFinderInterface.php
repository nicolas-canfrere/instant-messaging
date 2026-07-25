<?php

declare(strict_types=1);

namespace App\Conversation\Application\Contract;

use App\Shared\Domain\Identifier\UserId;

/**
 * Surface PUBLIEE de Conversation : « avec qui cette personne partage-t-elle
 * un fil ». Realtime en a besoin pour borner la presence qu'il expose.
 *
 * Interface distincte de MemberConversationsFinderInterface, et non une methode
 * ajoutee a celle-ci : elargir un contrat publie deja consomme est un
 * changement cassant, et les deux questions sont differentes — « quels fils
 * puis-je ecouter » n'est pas « qui sont mes interlocuteurs ».
 *
 * Modifier cette signature est un changement cassant.
 */
interface ConversationPeersFinderInterface
{
    /** @return list<UserId> jamais l'utilisateur lui-meme */
    public function peerIdsFor(UserId $userId): array;
}
