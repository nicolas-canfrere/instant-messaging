<?php

declare(strict_types=1);

namespace App\Media\Application\Command;

use App\Media\Application\ImageInspectorInterface;
use App\Media\Application\MediaStorageInterface;
use App\Media\Application\MimeTypeDetectorInterface;
use App\Media\Domain\MediaFamily;
use App\Media\Domain\MediaMimeType;
use App\Media\Domain\MediaObject;
use App\Media\Domain\MediaRejectionReason;
use App\Media\Domain\MediaRepositoryInterface;
use App\Media\Domain\StorageKey;
use App\Shared\Application\Bus\CommandHandlerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

final readonly class ProcessMediaCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private MediaRepositoryInterface $media,
        private MediaStorageInterface $storage,
        private MimeTypeDetectorInterface $detector,
        private ImageInspectorInterface $inspector,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ProcessMediaCommand $command): void
    {
        $media = $this->media->ofId($command->mediaId);

        // Un redelivrage Messenger sur un media deja terminal est du bruit
        // operationnel normal, pas un echec : sans ce garde-fou en tete de
        // methode, chaque redelivrage refait un telechargement, une
        // inspection ET un putObject de miniature avant que
        // `MediaObject::markImageReady()`/`markDocumentReady()`/`markRejected()`
        // ne leve — deux aller-retours S3 gaspilles, puis le message finit
        // quand meme en echec dans le transport `failed`.
        if ($media->status()->isTerminal()) {
            $this->logger->notice('Media {media_id} deja traite, redelivrage ignore', [
                'media_id' => $media->id()->toString(),
                'status' => $media->status()->value,
            ]);

            return;
        }

        $now = $this->clock->now();

        $localPath = $this->storage->downloadToTemporaryFile($media->storageKey());

        if (null === $localPath) {
            // Aucun objet a effacer : il n'est deja pas la (spec §7.1, ce
            // motif precis est justement l'absence constatee).
            $this->reject($media, MediaRejectionReason::MissingObject, $now, eraseBytes: false);

            return;
        }

        $thumbnailPath = null;

        try {
            // La taille AVANT tout le reste : rien ne plafonne un PUT pre-signe
            // (spec T4 §3.2), donc un objet de 2 Gio peut atterrir dans le bucket.
            // Le detecter avant de lire quoi que ce soit evite de le charger.
            // `@` parce que filesize() emet un warning PHP quand le fichier a
            // disparu, et `failOnWarning` est actif dans la suite de tests.
            $byteSize = @filesize($localPath);

            if (false === $byteSize) {
                // A ce stade le telechargement a deja reussi (sinon on ne
                // serait pas dans ce try) : l'objet EST dans le bucket, donc
                // MissingObject mentirait. C'est le fichier temporaire local
                // qui a disparu entre le telechargement et cette lecture
                // (course avec un nettoyage concurrent, permissions) — un
                // decodage rate au sens large, pas une absence d'objet.
                // eraseBytes:true parce que les octets sont toujours dans le
                // bucket et doivent etre purges comme tout rejet.
                $this->reject($media, MediaRejectionReason::Undecodable, $now, eraseBytes: true);

                return;
            }

            if ($byteSize > MediaObject::MAX_BYTES) {
                $this->reject($media, MediaRejectionReason::TooLarge, $now, eraseBytes: true);

                return;
            }

            $mimeType = $this->detector->detect($localPath);

            if ($mimeType instanceof MediaRejectionReason) {
                // Un `.jpg` qui contient du PHP meurt ICI, pas a l'affichage.
                $this->reject($media, $mimeType, $now, eraseBytes: true);

                return;
            }

            $declared = $media->declaredMimeType();

            if ($mimeType->family() !== $declared->family()) {
                // Familles differentes : la cle de stockage porte deja
                // l'extension du declare, et le Content-Disposition
                // servirait un nom qui ment. Place AVANT l'aiguillage par
                // famille : sinon un PDF declare comme du texte produirait
                // une miniature avant d'etre refuse.
                $this->reject($media, MediaRejectionReason::UnsupportedType, $now, eraseBytes: true);

                return;
            }

            if (!$mimeType->covers($declared)) {
                if (MediaFamily::Document === $mimeType->family()) {
                    // Meme famille Document, sous-type non couvert (pdf
                    // declare, texte mesure) : pour un document, l'extension
                    // est la SEULE chose que l'utilisateur et son systeme
                    // d'exploitation exploitent — un nom qui ment est le
                    // prejudice lui-meme, pas un signal a journaliser en
                    // laissant passer.
                    $this->reject($media, MediaRejectionReason::UnsupportedType, $now, eraseBytes: true);

                    return;
                }

                // Meme famille Image, sous-type non couvert (jpeg declare,
                // png reel) : le navigateur ne fait pas confiance a
                // l'extension pour une image, donc un signal actionnable
                // suffit — comportement tranche 4 inchange.
                $this->logger->warning('Le type declare du media {media_id} ne correspond pas aux octets', [
                    'media_id' => $media->id()->toString(),
                    'declared_mime_type' => $declared->value,
                    'actual_mime_type' => $mimeType->value,
                ]);
            }

            if (MediaFamily::Document === $mimeType->family()) {
                // Ni mesure ni miniature : un document n'en a pas (spec §3.3).
                $media->markDocumentReady($mimeType, $byteSize, $now);
                $this->media->save($media);

                $this->logger->info('Media {media_id} pret', [
                    'media_id' => $media->id()->toString(),
                    'mime_type' => $mimeType->value,
                    'byte_size' => $byteSize,
                ]);

                return;
            }

            $dimensions = $this->inspector->measure($localPath);

            if ($dimensions instanceof MediaRejectionReason) {
                // Un GIF tronque : le type etait bon, seul le decodage a echoue.
                $this->reject($media, $dimensions, $now, eraseBytes: true);

                return;
            }

            $thumbnailPath = sprintf('%s-thumb', $localPath);
            $thumbnailKey = StorageKey::forThumbnail($media->id());
            $this->inspector->thumbnail($localPath, $thumbnailPath);
            $this->storage->put($thumbnailKey, $thumbnailPath, MediaMimeType::Jpeg);

            $media->markImageReady($mimeType, $dimensions->width, $dimensions->height, $byteSize, $thumbnailKey, $now);
            $this->media->save($media);

            $this->logger->info('Media {media_id} pret', [
                'media_id' => $media->id()->toString(),
                'width' => $dimensions->width,
                'height' => $dimensions->height,
                'byte_size' => $byteSize,
            ]);
        } finally {
            // Les fichiers temporaires partent quoi qu'il arrive : un rejeu
            // apres echec ne doit pas remplir le disque du worker. La
            // miniature a sa propre garde ici, distincte de celle de
            // l'original : une panne du `put()` de la miniature (ex. panne
            // S3, chemin retente par Messenger) ne doit pas laisser un
            // fichier de plus a chaque tentative.
            @unlink($localPath);

            if (null !== $thumbnailPath) {
                @unlink($thumbnailPath);
            }
        }
    }

    private function reject(MediaObject $media, MediaRejectionReason $reason, \DateTimeImmutable $now, bool $eraseBytes): void
    {
        $media->markRejected($reason, $now);
        $this->media->save($media);

        if ($eraseBytes) {
            // On ne conserve pas les octets d'un fichier qu'on a decide de ne
            // jamais servir (spec §7.1).
            $this->storage->delete($media->storageKey(), $media->id());
        }

        // `UnsupportedType` est le seul motif ou l'operateur doit regarder :
        // un type deguise est un signal de securite. Un upload abandonne
        // (MissingObject) ou un utilisateur qui choisit une photo trop lourde
        // (TooLarge) sont des issues ordinaires — les faire lever un
        // `warning` serait exactement le bruit d'alerte que la regle
        // « warning doit etre actionnable » interdit.
        $level = MediaRejectionReason::UnsupportedType === $reason ? LogLevel::WARNING : LogLevel::NOTICE;

        $this->logger->log($level, 'Media {media_id} refuse : {rejection_reason}', [
            'media_id' => $media->id()->toString(),
            'rejection_reason' => $reason->value,
            'declared_mime_type' => $media->declaredMimeType()->value,
        ]);
    }
}
