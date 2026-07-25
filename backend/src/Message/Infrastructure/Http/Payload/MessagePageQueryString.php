<?php

declare(strict_types=1);

namespace App\Message\Infrastructure\Http\Payload;

use App\Message\Application\Query\GetMessagePageQueryHandler;
use App\Shared\Domain\Identifier\AbstractUlidIdentifier;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Chaine de requete de GET /api/conversations/{id}/messages, desserialisee et
 * validee par #[MapQueryString].
 *
 * `limit` n'est pas borne ici : le handler l'ecrete. Une valeur demesuree est
 * une maladresse du client, pas une requete invalide — la refuser casserait
 * l'appel pour rien. Le format d'un curseur, lui, est bien une erreur.
 */
final readonly class MessagePageQueryString
{
    public function __construct(
        #[Assert\Regex(
            pattern: AbstractUlidIdentifier::PATTERN,
            message: 'Ce curseur n\'est pas un ULID valide.',
        )]
        public ?string $before = null,
        #[Assert\Positive(message: 'La limite doit etre un entier positif.')]
        public int $limit = GetMessagePageQueryHandler::DEFAULT_LIMIT,
    ) {
    }
}
