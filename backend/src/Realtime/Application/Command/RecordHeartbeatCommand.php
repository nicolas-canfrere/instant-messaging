<?php

declare(strict_types=1);

namespace App\Realtime\Application\Command;

use App\Shared\Application\Bus\CommandInterface;
use App\Shared\Domain\Identifier\UserId;

final readonly class RecordHeartbeatCommand implements CommandInterface
{
    public function __construct(public UserId $userId)
    {
    }
}
