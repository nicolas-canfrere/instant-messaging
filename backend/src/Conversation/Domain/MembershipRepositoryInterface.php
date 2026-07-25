<?php

declare(strict_types=1);

namespace App\Conversation\Domain;

use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\UserId;

interface MembershipRepositoryInterface
{
    /** @throws ConversationNotFoundException si la personne n'est pas membre */
    public function ofMember(ConversationId $conversationId, UserId $userId): Membership;

    public function save(Membership $membership): void;
}
