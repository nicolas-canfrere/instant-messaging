<?php

declare(strict_types=1);

namespace App\Message\Infrastructure\Http\Payload;

use App\Message\Domain\MessageContent;
use App\Shared\Domain\Identifier\AbstractUlidIdentifier;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class SendMessagePayload
{
    public function __construct(
        #[Assert\NotBlank(message: 'La cle d\'idempotence du client est requise.')]
        #[Assert\Regex(
            pattern: AbstractUlidIdentifier::PATTERN,
            message: 'Cet identifiant n\'est pas un ULID valide.',
        )]
        public string $clientMessageId = '',

        // Garde de taille sur l'entree BRUTE, qui rend une violation nommee.
        // MessageContent revalide ensuite sur la chaine ROGNEE : c'est
        // l'invariant du domaine, et il est le seul a faire foi. Cette
        // contrainte-ci est donc legerement plus stricte, ce qui est le bon
        // sens pour une garde de bordure.
        #[Assert\NotBlank(message: 'Un message ne peut pas etre vide.')]
        #[Assert\Length(
            max: MessageContent::MAX_LENGTH,
            maxMessage: 'Un message ne peut pas depasser {{ limit }} caracteres.',
        )]
        public string $content = '',
    ) {
    }
}
