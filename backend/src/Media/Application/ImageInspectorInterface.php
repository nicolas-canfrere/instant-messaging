<?php

declare(strict_types=1);

namespace App\Media\Application;

use App\Media\Domain\MediaRejectionReason;

/**
 * Ne decide plus de RIEN : la detection est passee a
 * MimeTypeDetectorInterface. Ce port ne sait que mesurer et miniaturiser, et
 * on ne l'appelle que sur des octets deja reconnus comme une image.
 */
interface ImageInspectorInterface
{
    /**
     * Rend `MediaRejectionReason::Undecodable` quand le type etait bon mais
     * que le decodage echoue : fichier tronque ou corrompu. C'est la
     * distinction que la tranche 4 avait prise soin d'etablir, et elle
     * survit au decoupage.
     */
    public function measure(string $localPath): ImageDimensions|MediaRejectionReason;

    /** Ecrit une miniature JPEG dans `$targetPath`, ratio preserve. */
    public function thumbnail(string $localPath, string $targetPath): void;
}
