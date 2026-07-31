<?php

declare(strict_types=1);

namespace App\Media\Domain;

/**
 * L'allowlist. « Quels fichiers cette messagerie accepte » est une regle
 * metier, au meme titre que la fenetre d'edition de 15 minutes : elle vit
 * dans le domaine, pas dans la configuration.
 *
 * Les contraintes de validation des charges utiles REFERENCENT `values()`,
 * elles ne redeclarent jamais la liste.
 */
enum MediaMimeType: string
{
    case Jpeg = 'image/jpeg';
    case Png = 'image/png';
    case Webp = 'image/webp';
    case Gif = 'image/gif';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn(self $case): string => $case->value, self::cases());
    }

    public function extension(): string
    {
        return match ($this) {
            self::Jpeg => 'jpg',
            self::Png => 'png',
            self::Webp => 'webp',
            self::Gif => 'gif',
        };
    }
}
