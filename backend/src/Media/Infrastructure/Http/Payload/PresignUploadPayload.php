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
        // `normalizer: 'trim'` est indispensable : sans lui, NotBlank compare
        // la chaine brute a `!$value` (falsiness PHP), qui laisse passer
        // "   " puisqu'une chaine d'espaces est truthy. Regex et Length ne
        // le rattrapent pas non plus (l'espace est un caractere autorise par
        // OriginalFilename::PATTERN) : sans ce normalizer, un nom compose
        // uniquement d'espaces atteint le controleur, ou OriginalFilename le
        // refuse par une exception que le listener ne sait pas traduire en
        // 422 — un 500 sur une simple entree malformee.
        #[Assert\NotBlank(message: 'Le nom du fichier est requis.', normalizer: 'trim')]
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
