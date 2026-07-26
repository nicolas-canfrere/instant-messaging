<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Http\Payload;

use App\Media\Domain\MediaMimeType;
use App\Media\Domain\MediaObject;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Le `filename` sert UNIQUEMENT a l'ergonomie cote client : il n'entre ni dans
 * la cle de stockage — construite depuis l'ULID — ni dans une reponse. Un nom
 * de fichier controle par l'utilisateur ne doit jamais devenir un chemin.
 */
final readonly class PresignUploadPayload
{
    public function __construct(
        #[Assert\NotBlank(message: 'Le nom du fichier est requis.')]
        #[Assert\Length(max: 255, maxMessage: 'Ce nom de fichier est trop long.')]
        public string $filename = '',

        // La contrainte REFERENCE l'enum : elle ne redeclare jamais la liste.
        #[Assert\Choice(
            callback: [MediaMimeType::class, 'values'],
            message: 'Ce type de fichier n\'est pas accepte.',
        )]
        public string $contentType = '',
        #[Assert\Positive(message: 'La taille doit etre un entier positif.')]
        #[Assert\LessThanOrEqual(
            value: MediaObject::MAX_BYTES,
            message: 'Un fichier ne peut pas depasser {{ compared_value }} octets.',
        )]
        public int $size = 0,
    ) {
    }
}
