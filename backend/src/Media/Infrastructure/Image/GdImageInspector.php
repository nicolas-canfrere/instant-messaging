<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Image;

use App\Media\Application\ImageInspectorInterface;
use App\Media\Application\InspectedImage;
use App\Media\Domain\MediaMimeType;

final readonly class GdImageInspector implements ImageInspectorInterface
{
    /** Cote long de la miniature. 400 px suffit a un apercu dans un fil. */
    private const int THUMBNAIL_MAX_SIDE = 400;

    private const int THUMBNAIL_QUALITY = 82;

    public function inspect(string $localPath): ?InspectedImage
    {
        $detected = (new \finfo(FILEINFO_MIME_TYPE))->file($localPath);

        if (false === $detected) {
            return null;
        }

        $mimeType = MediaMimeType::tryFrom($detected);

        if (null === $mimeType) {
            return null;
        }

        // Le type est bon, mais un fichier tronque le porte encore : seul le
        // decodage tranche vraiment. `@` parce que getimagesize() emet un
        // warning PHP sur un fichier corrompu, et `failOnWarning` est actif.
        $size = @getimagesize($localPath);

        if (false === $size) {
            return null;
        }

        $bytes = filesize($localPath);

        if (false === $bytes) {
            return null;
        }

        return new InspectedImage($mimeType, $size[0], $size[1], $bytes);
    }

    public function thumbnail(string $localPath, string $targetPath): void
    {
        $source = @imagecreatefromstring((string) file_get_contents($localPath));

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

        imagejpeg($thumbnail, $targetPath, self::THUMBNAIL_QUALITY);
    }
}
