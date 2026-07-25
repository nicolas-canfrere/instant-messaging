<?php

declare(strict_types=1);

namespace App\Message\Application\Query;

use App\Shared\Application\Bus\QueryInterface;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\MessageId;
use App\Shared\Domain\Identifier\UserId;

/**
 * Historique d'une conversation, du plus recent au plus ancien.
 *
 * `$requestedBy` fait partie de la question : la lecture est cadree par
 * l'appartenance, ce qui donne un 404 a un non-membre sans jamais confirmer
 * l'existence du fil.
 *
 * @implements QueryInterface<MessagePage>
 */
final readonly class GetMessagePageQuery implements QueryInterface
{
    public function __construct(
        public ConversationId $conversationId,
        public UserId $requestedBy,
        public ?MessageId $before,
        public int $limit,
    ) {
    }
}
