<?php

declare(strict_types=1);

namespace App\Message\Infrastructure\Http\Payload;

use App\Message\Domain\MessageContent;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class EditMessagePayload
{
    public function __construct(
        // Garde de bordure sur l'entree BRUTE, qui rend une violation nommee.
        // MessageContent revalide sur la chaine rognee : c'est l'invariant du
        // domaine, et il est le seul a faire foi.
        #[Assert\NotBlank(message: 'Un message ne peut pas etre vide.')]
        #[Assert\Length(
            max: MessageContent::MAX_LENGTH,
            maxMessage: 'Un message ne peut pas depasser {{ limit }} caracteres.',
        )]
        public string $content = '',
    ) {
    }
}
