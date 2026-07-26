<?php

declare(strict_types=1);

namespace App\Media\Application;

use App\Media\Domain\MediaRejectionReason;

interface ImageInspectorInterface
{
    /**
     * Lit le type REEL des octets et mesure l'image. Rend le motif de rejet
     * quand ce n'est pas une image exploitable :
     * `MediaRejectionReason::UnsupportedType` si les octets ne sont pas une
     * image de l'allowlist, `MediaRejectionReason::Undecodable` si le type
     * est bon mais le decodage echoue (fichier tronque ou corrompu).
     *
     * Le type declare par le client n'entre jamais ici : c'est le point unique
     * ou l'on decide ce qu'est vraiment le fichier. Un `Domain` dans la
     * signature d'un port d'`Application` reste dans les clous de deptrac :
     * c'est un type de retour, pas une dependance vers un vendor.
     */
    public function inspect(string $localPath): InspectedImage|MediaRejectionReason;

    /** Ecrit une miniature JPEG dans `$targetPath`, ratio preserve. */
    public function thumbnail(string $localPath, string $targetPath): void;
}
