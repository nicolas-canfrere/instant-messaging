<?php

declare(strict_types=1);

namespace App\Conversation\Infrastructure\Http\Payload;

use App\Shared\Domain\Identifier\AbstractUlidIdentifier;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class AddMembersPayload
{
    /**
     * @param list<string> $userIds
     */
    public function __construct(
        #[Assert\Count(min: 1, minMessage: 'Indiquer au moins un utilisateur a ajouter.')]
        #[Assert\All([
            new Assert\Type('string'),
            new Assert\Regex(
                pattern: AbstractUlidIdentifier::PATTERN,
                message: 'Cet identifiant n\'est pas un ULID valide.',
            ),
        ])]
        public array $userIds = [],
    ) {
    }
}
