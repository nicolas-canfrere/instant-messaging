<?php

declare(strict_types=1);

namespace App\Conversation\Application\Query;

use App\Conversation\Domain\DirectKey;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\UserId;

/**
 * Surface de lecture du contexte Conversation, exprimee comme un besoin.
 *
 * Le chemin CQS de lecture va du SQL au DTO sans traverser le domaine, mais ce
 * SQL reste de l'infrastructure : un use case declare ce dont il a besoin, il
 * n'ecrit pas de requete.
 */
interface ConversationReaderInterface
{
    /** @return list<ConversationView> triees par activite recente */
    public function forMember(UserId $userId): array;

    public function directIdForKey(DirectKey $key): ?ConversationId;

    /**
     * Rend `null` aussi bien si la conversation n'existe pas que si le
     * demandeur n'en est pas membre : c'est ce cadrage qui produit un 404
     * indiscernable dans les deux cas.
     */
    public function detailFor(ConversationId $conversationId, UserId $requestedBy): ?ConversationDetailView;
}
