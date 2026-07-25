<?php

declare(strict_types=1);

namespace App\Conversation\Application\Query;

use App\Shared\Application\Bus\QueryInterface;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\UserId;

/**
 * Identifiant du direct entre deux personnes, quel que soit l'ordre, ou null.
 *
 * Rend `null` plutot que de lever : « ce direct n'existe pas encore » est une
 * reponse normale, c'est meme ce qui permet de distinguer un 201 d'un 200.
 *
 * @implements QueryInterface<ConversationId|null>
 */
final readonly class FindDirectConversationQuery implements QueryInterface
{
    public function __construct(
        public UserId $initiator,
        public UserId $peer,
    ) {
    }
}
