<?php

declare(strict_types=1);

namespace App\Conversation\Application\Query;

use App\Conversation\Domain\DirectKey;
use App\Shared\Application\Bus\QueryHandlerInterface;
use App\Shared\Domain\Identifier\ConversationId;

final readonly class FindDirectConversationQueryHandler implements QueryHandlerInterface
{
    public function __construct(private ConversationReaderInterface $conversations)
    {
    }

    public function __invoke(FindDirectConversationQuery $query): ?ConversationId
    {
        return $this->conversations->directIdForKey(
            DirectKey::forPair($query->initiator, $query->peer),
        );
    }
}
