<?php

declare(strict_types=1);

namespace App\Conversation\Application\Query;

use App\Shared\Application\Bus\QueryHandlerInterface;

final readonly class ListMyConversationsQueryHandler implements QueryHandlerInterface
{
    public function __construct(private ConversationReaderInterface $conversations)
    {
    }

    /** @return list<ConversationView> */
    public function __invoke(ListMyConversationsQuery $query): array
    {
        return $this->conversations->forMember($query->userId);
    }
}
