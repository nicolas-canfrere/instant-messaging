<?php

declare(strict_types=1);

namespace App\Media\Application;

use App\Media\Domain\MediaMimeType;
use App\Media\Domain\MediaRejectionReason;

/**
 * Point UNIQUE du projet ou l'on decide ce que sont vraiment des octets. Le
 * type declare par le client n'entre jamais ici : c'est toute la these de la
 * tranche 4, et elle survit aux documents.
 *
 * Un `Domain` dans la signature d'un port d'`Application` reste dans les
 * clous de deptrac : c'est un type de retour, pas une dependance vendor.
 */
interface MimeTypeDetectorInterface
{
    public function detect(string $localPath): MediaMimeType|MediaRejectionReason;
}
