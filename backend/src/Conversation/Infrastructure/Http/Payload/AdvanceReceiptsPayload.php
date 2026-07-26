<?php

declare(strict_types=1);

namespace App\Conversation\Infrastructure\Http\Payload;

use App\Shared\Domain\Identifier\AbstractUlidIdentifier;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Les deux curseurs sont optionnels : le client n'envoie que celui qui a bouge.
 * Un `null` n'est donc pas une demande de recul, c'est « ce curseur ne change
 * pas » — le domaine l'interprete ainsi.
 */
final readonly class AdvanceReceiptsPayload
{
    public function __construct(
        #[Assert\Regex(
            pattern: AbstractUlidIdentifier::PATTERN,
            message: 'Cet identifiant n\'est pas un ULID valide.',
        )]
        public ?string $deliveredUpTo = null,
        #[Assert\Regex(
            pattern: AbstractUlidIdentifier::PATTERN,
            message: 'Cet identifiant n\'est pas un ULID valide.',
        )]
        public ?string $readUpTo = null,
    ) {
    }
}
