<?php

declare(strict_types=1);

namespace App\Realtime\Application\Query;

use App\Conversation\Application\Contract\ConversationPeersFinderInterface;
use App\Realtime\Domain\PresenceStoreInterface;
use App\Shared\Application\Bus\QueryHandlerInterface;
use App\Shared\Domain\Identifier\UserId;

final readonly class GetOnlinePeersQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private ConversationPeersFinderInterface $peers,
        private PresenceStoreInterface $presence,
    ) {
    }

    /** @return list<string> */
    public function __invoke(GetOnlinePeersQuery $query): array
    {
        $online = $this->presence->onlineAmong($this->peers->peerIdsFor($query->userId));

        return array_map(static fn(UserId $id): string => $id->toString(), $online);
    }
}
