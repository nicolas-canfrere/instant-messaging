<?php

declare(strict_types=1);

namespace App\Media\Application;

interface ImageInspectorInterface
{
    /**
     * Lit le type REEL des octets et mesure l'image. Rend `null` si les octets
     * ne sont pas une image de l'allowlist, ou si le decodage echoue.
     *
     * Le type declare par le client n'entre jamais ici : c'est le point unique
     * ou l'on decide ce qu'est vraiment le fichier.
     */
    public function inspect(string $localPath): ?InspectedImage;

    /** Ecrit une miniature JPEG dans `$targetPath`, ratio preserve. */
    public function thumbnail(string $localPath, string $targetPath): void;
}
