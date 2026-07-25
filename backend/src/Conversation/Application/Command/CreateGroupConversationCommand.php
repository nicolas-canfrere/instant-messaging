<?php

declare(strict_types=1);

namespace App\Conversation\Application\Command;

use App\Shared\Application\Bus\CommandInterface;
use App\Shared\Domain\Identifier\ConversationId;
use App\Shared\Domain\Identifier\UserId;

/**
 * L'identifiant est fourni par l'appelant, pas genere par le handler.
 *
 * Contrairement a un direct, creer un groupe n'a rien d'idempotent : rien a
 * reconcilier, donc l'identifiant pre-genere est forcement celui de la ligne
 * ecrite. Le controleur peut le rendre sans relire la base.
 */
final readonly class CreateGroupConversationCommand implements CommandInterface
{
    /** @param list<UserId> $members */
    public function __construct(
        public ConversationId $conversationId,
        public UserId $creator,
        public string $title,
        public array $members,
    ) {
    }
}
