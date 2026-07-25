<?php

declare(strict_types=1);

namespace App\Conversation\Application\Query;

use App\Conversation\Domain\DirectKey;
use App\Shared\Application\Bus\QueryHandlerInterface;
use App\Shared\Domain\Identifier\ConversationId;

final readonly class GetDirectConversationIdQueryHandler implements QueryHandlerInterface
{
    public function __construct(private ConversationReaderInterface $conversations)
    {
    }

    public function __invoke(GetDirectConversationIdQuery $query): ConversationId
    {
        $key = DirectKey::forPair($query->initiator, $query->peer);

        return $this->conversations->directIdForKey($key)
            ?? throw DirectConversationNotFoundException::forKey($key);
    }
}
