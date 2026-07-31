<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Http\Payload;

use App\Media\Domain\MediaMimeType;
use App\Media\Domain\MediaObject;
use App\Media\Domain\OriginalFilename;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Le `filename` devient l'OriginalFilename porte par l'agregat : il n'entre
 * jamais dans la cle de stockage — construite depuis l'ULID — mais atterrit
 * dans l'en-tete Content-Disposition de l'URL de telechargement signee.
 */
final readonly class PresignUploadPayload
{
    public function __construct(
        #[Assert\NotBlank(message: 'Le nom du fichier est requis.')]
        #[Assert\Length(max: OriginalFilename::MAX_LENGTH, maxMessage: 'Ce nom de fichier est trop long.')]
        #[Assert\Regex(
            pattern: OriginalFilename::PATTERN,
            message: 'Ce nom de fichier contient des caracteres interdits.',
        )]
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
