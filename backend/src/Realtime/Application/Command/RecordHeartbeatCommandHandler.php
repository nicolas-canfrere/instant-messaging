<?php

declare(strict_types=1);

namespace App\Realtime\Application\Command;

use App\Realtime\Domain\PresenceStoreInterface;
use App\Shared\Application\Bus\CommandHandlerInterface;

final readonly class RecordHeartbeatCommandHandler implements CommandHandlerInterface
{
    public function __construct(private PresenceStoreInterface $presence)
    {
    }

    public function __invoke(RecordHeartbeatCommand $command): void
    {
        // Aucun log ici : le middleware du bus loggue deja chaque commande, et
        // un battement toutes les 20 s par utilisateur noierait le journal.
        $this->presence->touch($command->userId);
    }
}
