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
        //
        // `NotBlank` disparait : un message peut desormais n'etre QUE des
        // images. C'est le controleur qui refuse un message sans texte NI
        // media — une regle qui croise deux champs, donc pas une contrainte.
        #[Assert\Length(
            max: MessageContent::MAX_LENGTH,
            maxMessage: 'Un message ne peut pas depasser {{ limit }} caracteres.',
        )]
        public ?string $content = null,

        /** @var list<string> */
        #[Assert\Count(max: 10, maxMessage: 'Un message ne peut pas porter plus de {{ limit }} images.')]
        #[Assert\All([
            new Assert\Regex(
                pattern: AbstractUlidIdentifier::PATTERN,
                message: 'Cet identifiant n\'est pas un ULID valide.',
            ),
        ])]
        public array $mediaIds = [],
    ) {
    }
}
