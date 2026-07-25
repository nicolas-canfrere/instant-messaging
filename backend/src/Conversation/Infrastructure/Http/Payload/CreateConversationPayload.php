<?php

declare(strict_types=1);

namespace App\Conversation\Infrastructure\Http\Payload;

use App\Conversation\Domain\ConversationType;
use App\Shared\Domain\Identifier\AbstractUlidIdentifier;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Charge utile de POST /api/conversations, desserialisee et validee par
 * #[MapRequestPayload] avant que le controleur ne soit appele.
 *
 * Le controleur ne voit donc que des donnees deja bien formees : plus de
 * `json_decode` suivi d'un `@var` qui ment a PHPStan, et une entree invalide
 * produit un 422 decrivant les champs fautifs, jamais un 500.
 */
final readonly class CreateConversationPayload
{
    /**
     * @param list<string> $memberIds
     */
    public function __construct(
        #[Assert\NotBlank(message: 'Le type de conversation est requis.')]
        #[Assert\Choice(
            callback: [ConversationType::class, 'values'],
            message: 'Type de conversation inconnu : attendu "direct" ou "group".',
        )]
        public string $type = '',

        // Requis pour un groupe seulement : la contrainte conditionnelle est
        // portee par le controleur, qui sait de quel type il s'agit.
        #[Assert\Length(max: 120)]
        public ?string $title = null,
        #[Assert\All([
            new Assert\Type('string'),
            new Assert\Regex(
                pattern: AbstractUlidIdentifier::PATTERN,
                message: 'Cet identifiant n\'est pas un ULID valide.',
            ),
        ])]
        public array $memberIds = [],
    ) {
    }
}
