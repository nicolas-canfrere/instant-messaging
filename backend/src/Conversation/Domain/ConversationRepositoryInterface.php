<?php

declare(strict_types=1);

namespace App\Conversation\Domain;

use App\Shared\Domain\Identifier\ConversationId;

interface ConversationRepositoryInterface
{
    public function save(Conversation $conversation): void;

    /** @throws ConversationNotFoundException */
    public function ofId(ConversationId $id): Conversation;

    public function ofDirectKey(DirectKey $key): ?Conversation;
}
