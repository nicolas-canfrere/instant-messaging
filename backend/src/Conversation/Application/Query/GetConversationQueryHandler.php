<?php

declare(strict_types=1);

namespace App\Conversation\Application\Query;

use App\Conversation\Domain\ConversationNotFoundException;
use App\Shared\Application\Bus\QueryHandlerInterface;

final readonly class GetConversationQueryHandler implements QueryHandlerInterface
{
    public function __construct(private ConversationReaderInterface $conversations)
    {
    }

    public function __invoke(GetConversationQuery $query): ConversationDetailView
    {
        return $this->conversations->detailFor($query->conversationId, $query->requestedBy)
            ?? throw ConversationNotFoundException::withId($query->conversationId);
    }
}
