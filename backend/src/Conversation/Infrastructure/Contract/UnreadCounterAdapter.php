<?php

declare(strict_types=1);

namespace App\Conversation\Infrastructure\Contract;

use App\Conversation\Domain\Port\UnreadCounterPortInterface;
use App\Message\Application\Contract\UnreadCounterInterface;
use App\Shared\Domain\Identifier\UserId;

/** Le SEUL endroit de Conversation qui nomme le contexte Message. */
final readonly class UnreadCounterAdapter implements UnreadCounterPortInterface
{
    public function __construct(private UnreadCounterInterface $counter)
    {
    }

    public function countUnread(UserId $reader, array $watermarkByConversation): array
    {
        return $this->counter->countUnread($reader, $watermarkByConversation);
    }
}
