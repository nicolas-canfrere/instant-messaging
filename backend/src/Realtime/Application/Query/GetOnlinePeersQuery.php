<?php

declare(strict_types=1);

namespace App\Realtime\Application\Query;

use App\Shared\Application\Bus\QueryInterface;
use App\Shared\Domain\Identifier\UserId;

/** @implements QueryInterface<list<string>> */
final readonly class GetOnlinePeersQuery implements QueryInterface
{
    public function __construct(public UserId $userId)
    {
    }
}
