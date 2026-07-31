<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\File;

use App\Media\Application\MimeTypeDetectorInterface;
use App\Media\Domain\MediaFamily;
use App\Media\Domain\MediaMimeType;
use App\Media\Domain\MediaRejectionReason;

final readonly class FinfoMimeTypeDetector implements MimeTypeDetectorInterface
{
    /**
     * Les deux seuls types texte qu'un navigateur RENDRAIT au lieu de les
     * afficher. Ils restent exclus malgre la disposition `attachment` : la
     * disposition est une ligne de code qui peut regresser, l'allowlist est
     * la seconde barriere.
     */
    private const array RENDERABLE_TEXT = ['text/html', 'text/xml'];

    public function detect(string $localPath): MediaMimeType|MediaRejectionReason
    {
        // `@` parce que finfo::file() emet un warning PHP sur un fichier
        // manquant, et `failOnWarning` est actif dans la suite de tests.
        $detected = @(new \finfo(FILEINFO_MIME_TYPE))->file($localPath);

        if (false === $detected) {
            return MediaRejectionReason::UnsupportedType;
        }

        $exact = MediaMimeType::tryFrom($detected);

        // Un type texte reconnu tel quel (text/plain, text/csv si libmagic
        // le sait) passe quand meme par la garde : c'est elle qui verifie
        // que les octets SONT du texte.
        if (null !== $exact && MediaFamily::Image === $exact->family()) {
            return $exact;
        }

        if (MediaMimeType::Pdf === $exact) {
            return MediaMimeType::Pdf;
        }

        if (!str_starts_with($detected, 'text/') || \in_array($detected, self::RENDERABLE_TEXT, true)) {
            return MediaRejectionReason::UnsupportedType;
        }

        // finfo ne rend pas toujours text/plain sur du texte : un shebang
        // donne text/x-shellscript, du C donne text/x-c. Plutot qu'une
        // denylist, on VERIFIE les octets — c'est fidele a « les octets
        // decident », et ca vaut mieux qu'un parametre charset declaratif.
        return $this->readAsText($localPath);
    }

    private function readAsText(string $localPath): MediaMimeType|MediaRejectionReason
    {
        // Lire le fichier entier est sans danger PARCE QUE le handler a deja
        // rejete tout ce qui depasse MediaObject::MAX_BYTES avant d'appeler
        // detect() : l'ordre est un invariant, pas un detail.
        $bytes = @file_get_contents($localPath);

        if (false === $bytes) {
            return MediaRejectionReason::UnsupportedType;
        }

        if (str_contains($bytes, "\0")) {
            // Un octet NUL n'est jamais du texte legitime — c'est un signal
            // de deguisement, au meme titre qu'un HTML renomme .txt. En
            // pratique libmagic classe deja ces octets `application/octet-stream`
            // et le rejet intervient plus haut (garde `text/`) ; cette
            // branche reste ecrite pour le jour ou libmagic changerait d'avis.
            return MediaRejectionReason::UnsupportedType;
        }

        if (!mb_check_encoding($bytes, 'UTF-8')) {
            // Texte legitime, encodage different — un CSV exporte par Excel
            // en cp1252, par exemple. Ce n'est PAS un deguisement : motif
            // distinct pour ne pas declencher le warning reserve au signal
            // de securite.
            return MediaRejectionReason::UnsupportedEncoding;
        }

        return MediaMimeType::Text;
    }
}
