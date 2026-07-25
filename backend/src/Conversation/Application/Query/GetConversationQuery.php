<?php

declare(strict_types=1);

namespace App\Conversation\Application\Query;

use App\Shared\Application\Bus\QueryInterface;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\UserId;

/**
 * La lecture est cadree par l'appartenance : `$requestedBy` fait partie de la
 * question, pas d'un controle pose a cote.
 *
 * C'est ce qui rend un identifiant inconnu indiscernable d'un identifiant
 * inaccessible — les deux donnent un 404 au meme document pres.
 *
 * @implements QueryInterface<ConversationDetailView>
 */
final readonly class GetConversationQuery implements QueryInterface
{
    public function __construct(
        public ConversationId $conversationId,
        public UserId $requestedBy,
    ) {
    }
}
