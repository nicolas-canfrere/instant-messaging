<?php

declare(strict_types=1);

namespace App\Conversation\Infrastructure\Contract;

use App\Conversation\Application\Contract\ConversationMembershipInterface;
use App\Conversation\Application\MembershipCheckerInterface;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\UserId;

/**
 * Realise le contrat publie en deleguant au verificateur interne : la surface
 * exposee reste stable meme si l'implementation interne change.
 */
final readonly class DbalConversationMembership implements ConversationMembershipInterface
{
    public function __construct(private MembershipCheckerInterface $membership)
    {
    }

    public function isMember(ConversationId $conversationId, UserId $userId): bool
    {
        return $this->membership->isMember($conversationId, $userId);
    }
}
