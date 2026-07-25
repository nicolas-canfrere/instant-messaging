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

        // La longueur est aussi verifiee par MessageContent : ici pour rendre
        // une violation nommee, la, parce que c'est un invariant du domaine.
        #[Assert\NotBlank(message: 'Un message ne peut pas etre vide.')]
        #[Assert\Length(max: MessageContent::MAX_LENGTH)]
        public string $content = '',
    ) {
    }
}
