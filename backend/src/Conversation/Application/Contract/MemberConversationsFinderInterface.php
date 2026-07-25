<?php

declare(strict_types=1);

namespace App\Conversation\Application\Contract;

use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\UserId;

/**
 * Surface PUBLIEE de Conversation, possedee par le producteur et non par Shared.
 *
 * Elle ne rend que des identifiants, jamais l'agregat : un consommateur ne doit
 * rien pouvoir deduire des internes de Conversation. Modifier cette signature
 * est un changement cassant.
 */
interface MemberConversationsFinderInterface
{
    /** @return list<ConversationId> les conversations dont l'utilisateur est membre */
    public function conversationIdsFor(UserId $userId): array;
}
