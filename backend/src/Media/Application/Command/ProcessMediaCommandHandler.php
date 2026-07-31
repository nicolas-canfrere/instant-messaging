<?php

declare(strict_types=1);

namespace App\Media\Application\Command;

use App\Media\Application\ImageInspectorInterface;
use App\Media\Application\MediaStorageInterface;
use App\Media\Application\MimeTypeDetectorInterface;
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
        // `MediaObject::markReady()`/`markRejected()` ne leve — deux
        // aller-retours S3 gaspilles, puis le message finit quand meme en
        // echec dans le transport `failed`.
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
            $byteSize = filesize($localPath);

            if (false === $byteSize) {
                $this->reject($media, MediaRejectionReason::MissingObject, $now, eraseBytes: false);

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

            $media->markReady($mimeType, $dimensions->width, $dimensions->height, $byteSize, $thumbnailKey, $now);
            $this->media->save($media);

            if ($mimeType !== $media->declaredMimeType()) {
                // Signal actionnable : un client qui declare autre chose que ce
                // qu'il envoie est un bug, ou pire.
                $this->logger->warning('Le type declare du media {media_id} ne correspond pas aux octets', [
                    'media_id' => $media->id()->toString(),
                    'declared_mime_type' => $media->declaredMimeType()->value,
                    'actual_mime_type' => $mimeType->value,
                ]);
            }

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
