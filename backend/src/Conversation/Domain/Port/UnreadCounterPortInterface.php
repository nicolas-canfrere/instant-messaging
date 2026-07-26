<?php

declare(strict_types=1);

namespace App\Conversation\Domain\Port;

use App\Shared\Domain\Identifier\UserId;

/**
 * Le BESOIN de Conversation, exprime dans son propre langage. L'adaptateur qui
 * le realise delegue au contrat publie de Message.
 *
 * Ce port existe pour que le contexte n'ait pas a nommer directement un autre
 * contexte dans sa couche Application : le seul endroit ou Message apparait est
 * l'adaptateur, en Infrastructure.
 */
interface UnreadCounterPortInterface
{
    /**
     * @param array<string, string|null> $watermarkByConversation
     *
     * @return array<string, int>
     */
    public function countUnread(UserId $reader, array $watermarkByConversation): array;
}
