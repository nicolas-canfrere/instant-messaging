<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Image;

use App\Media\Application\ImageDimensions;
use App\Media\Application\ImageInspectorInterface;
use App\Media\Domain\MediaRejectionReason;

final readonly class GdImageInspector implements ImageInspectorInterface
{
    /** Cote long de la miniature. 400 px suffit a un apercu dans un fil. */
    private const int THUMBNAIL_MAX_SIDE = 400;

    private const int THUMBNAIL_QUALITY = 82;

    public function measure(string $localPath): ImageDimensions|MediaRejectionReason
    {
        // `@` parce que getimagesize() emet un warning PHP sur un fichier
        // corrompu, et `failOnWarning` est actif dans la suite de tests.
        $size = @getimagesize($localPath);

        return false === $size
            ? MediaRejectionReason::Undecodable
            : new ImageDimensions($size[0], $size[1]);
    }

    public function thumbnail(string $localPath, string $targetPath): void
    {
        $contents = file_get_contents($localPath);

        if (false === $contents) {
            // Distinct du decodage : ici l'octet n'a meme pas pu etre lu
            // (permissions, fichier disparu entre l'inspection et la
            // miniature). Un `(string)` sur `false` masquerait cette panne
            // d'E/S en la faisant passer pour une image vide indecodable.
            throw new \RuntimeException('La miniature ne peut pas etre produite : lecture du fichier source impossible.');
        }

        $source = @imagecreatefromstring($contents);

        if (false === $source) {
            throw new \RuntimeException('La miniature ne peut pas etre produite : image indecodable.');
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $ratio = min(self::THUMBNAIL_MAX_SIDE / $width, self::THUMBNAIL_MAX_SIDE / $height, 1.0);

        $thumbnail = imagescale($source, (int) round($width * $ratio), (int) round($height * $ratio));

        if (false === $thumbnail) {
            throw new \RuntimeException('La mise a l\'echelle de la miniature a echoue.');
        }

        if (false === imagejpeg($thumbnail, $targetPath, self::THUMBNAIL_QUALITY)) {
            throw new \RuntimeException('L\'ecriture de la miniature a echoue.');
        }
    }
}
