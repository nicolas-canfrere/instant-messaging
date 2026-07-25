<?php

declare(strict_types=1);

namespace App\Conversation\Application\Contract;

use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\UserId;

/**
 * Surface PUBLIEE de Conversation : « cette personne a-t-elle acces a ce fil ».
 *
 * C'est le seul moyen pour un autre contexte de poser la question. Message ne
 * lit ni conversation_members, ni les queries internes de Conversation.
 *
 * Ne rend qu'un booleen, jamais l'agregat ni un DTO : un consommateur ne doit
 * rien pouvoir deduire de la composition du fil.
 */
interface ConversationMembershipInterface
{
    public function isMember(ConversationId $conversationId, UserId $userId): bool;
}
