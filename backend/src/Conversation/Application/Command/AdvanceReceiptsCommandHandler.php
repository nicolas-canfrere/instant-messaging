<?php

declare(strict_types=1);

namespace App\Conversation\Application\Command;

use App\Conversation\Domain\MembershipRepositoryInterface;
use App\Shared\Application\Bus\CommandHandlerInterface;

final readonly class AdvanceReceiptsCommandHandler implements CommandHandlerInterface
{
    public function __construct(private MembershipRepositoryInterface $memberships)
    {
    }

    public function __invoke(AdvanceReceiptsCommand $command): void
    {
        $membership = $this->memberships->ofMember($command->conversationId, $command->userId);

        $membership->advanceDeliveredTo($command->deliveredUpTo);
        $membership->advanceReadTo($command->readUpTo);

        $this->memberships->save($membership);
    }
}
