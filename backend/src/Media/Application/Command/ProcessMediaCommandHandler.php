<?php

declare(strict_types=1);

namespace App\Media\Application\Command;

use App\Media\Application\ImageInspectorInterface;
use App\Media\Application\MediaStorageInterface;
use App\Media\Domain\MediaMimeType;
use App\Media\Domain\MediaObject;
use App\Media\Domain\MediaRejectionReason;
use App\Media\Domain\MediaRepositoryInterface;
use App\Media\Domain\StorageKey;
use App\Shared\Application\Bus\CommandHandlerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final readonly class ProcessMediaCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private MediaRepositoryInterface $media,
        private MediaStorageInterface $storage,
        private ImageInspectorInterface $inspector,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ProcessMediaCommand $command): void
    {
        $media = $this->media->ofId($command->mediaId);
        $now = $this->clock->now();

        $localPath = $this->storage->downloadToTemporaryFile($media->storageKey());

        if (null === $localPath) {
            $this->reject($media, MediaRejectionReason::MissingObject, $now);

            return;
        }

        try {
            $inspected = $this->inspector->inspect($localPath);

            if (null === $inspected) {
                // Un `.jpg` qui contient du PHP meurt ICI, pas a l'affichage.
                $this->reject($media, MediaRejectionReason::UnsupportedType, $now);

                return;
            }

            if ($inspected->byteSize > MediaObject::MAX_BYTES) {
                // Le plafond ne peut pas etre applique au transfert par une URL
                // pre-signee PUT (spec §3.2) : il l'est ici.
                $this->reject($media, MediaRejectionReason::TooLarge, $now);

                return;
            }

            $thumbnailPath = sprintf('%s-thumb', $localPath);
            $thumbnailKey = StorageKey::forThumbnail($media->id());
            $this->inspector->thumbnail($localPath, $thumbnailPath);
            $this->storage->put($thumbnailKey, $thumbnailPath, MediaMimeType::Jpeg);
            @unlink($thumbnailPath);

            $media->markReady(
                $inspected->mimeType,
                $inspected->width,
                $inspected->height,
                $inspected->byteSize,
                $thumbnailKey,
                $now,
            );
            $this->media->save($media);

            if ($inspected->mimeType !== $media->declaredMimeType()) {
                // Signal actionnable : un client qui declare autre chose que ce
                // qu'il envoie est un bug, ou pire.
                $this->logger->warning('Le type declare du media {media_id} ne correspond pas aux octets', [
                    'media_id' => $media->id()->toString(),
                    'declared_mime_type' => $media->declaredMimeType()->value,
                    'actual_mime_type' => $inspected->mimeType->value,
                ]);
            }

            $this->logger->info('Media {media_id} pret', [
                'media_id' => $media->id()->toString(),
                'width' => $inspected->width,
                'height' => $inspected->height,
                'byte_size' => $inspected->byteSize,
            ]);
        } finally {
            // Le fichier temporaire part quoi qu'il arrive : un rejeu apres
            // echec ne doit pas remplir le disque du worker.
            @unlink($localPath);
        }
    }

    private function reject(MediaObject $media, MediaRejectionReason $reason, \DateTimeImmutable $now): void
    {
        $media->markRejected($reason, $now);
        $this->media->save($media);

        // On ne conserve pas les octets d'un fichier qu'on a decide de ne
        // jamais servir (spec §7.1).
        $this->storage->delete($media->storageKey(), $media->id());

        $this->logger->warning('Media {media_id} refuse : {rejection_reason}', [
            'media_id' => $media->id()->toString(),
            'rejection_reason' => $reason->value,
            'declared_mime_type' => $media->declaredMimeType()->value,
        ]);
    }
}
