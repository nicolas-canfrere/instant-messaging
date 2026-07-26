<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Image;

use App\Media\Application\ImageInspectorInterface;
use App\Media\Application\InspectedImage;
use App\Media\Domain\MediaMimeType;
use App\Media\Domain\MediaRejectionReason;

final readonly class GdImageInspector implements ImageInspectorInterface
{
    /** Cote long de la miniature. 400 px suffit a un apercu dans un fil. */
    private const int THUMBNAIL_MAX_SIDE = 400;

    private const int THUMBNAIL_QUALITY = 82;

    public function inspect(string $localPath): InspectedImage|MediaRejectionReason
    {
        $detected = (new \finfo(FILEINFO_MIME_TYPE))->file($localPath);

        if (false === $detected) {
            return MediaRejectionReason::UnsupportedType;
        }

        $mimeType = MediaMimeType::tryFrom($detected);

        if (null === $mimeType) {
            return MediaRejectionReason::UnsupportedType;
        }

        // Le type est bon, mais un fichier tronque le porte encore : seul le
        // decodage tranche vraiment. `@` parce que getimagesize() emet un
        // warning PHP sur un fichier corrompu, et `failOnWarning` est actif.
        // C'est ici, et seulement ici, que « type accepte » et « decodable »
        // se distinguent : un GIF tronque n'est pas un type refuse.
        $size = @getimagesize($localPath);

        if (false === $size) {
            return MediaRejectionReason::Undecodable;
        }

        $bytes = filesize($localPath);

        if (false === $bytes) {
            return MediaRejectionReason::Undecodable;
        }

        return new InspectedImage($mimeType, $size[0], $size[1], $bytes);
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
