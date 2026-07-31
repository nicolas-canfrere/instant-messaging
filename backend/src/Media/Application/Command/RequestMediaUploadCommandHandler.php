<?php

declare(strict_types=1);

namespace App\Media\Application\Command;

use App\Media\Domain\MediaObject;
use App\Media\Domain\MediaRepositoryInterface;
use App\Media\Domain\StorageKey;
use App\Shared\Application\Bus\CommandHandlerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final readonly class RequestMediaUploadCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private MediaRepositoryInterface $media,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RequestMediaUploadCommand $command): void
    {
        $media = MediaObject::request(
            $command->mediaId,
            $command->ownerId,
            StorageKey::forOriginal($command->mediaId, $command->declaredMimeType),
            $command->originalFilename,
            $command->declaredMimeType,
            $command->declaredSize,
            $this->clock->now(),
        );

        $this->media->add($media);

        // Ni nom de fichier, ni cle de stockage : des identifiants, et le type
        // DECLARE, qui est une donnee de diagnostic — l'ecart avec le type reel
        // se lira plus tard dans les logs du worker.
        $this->logger->info('Upload {media_id} pre-signe pour {owner_id}', [
            'media_id' => $command->mediaId->toString(),
            'owner_id' => $command->ownerId->toString(),
            'declared_mime_type' => $command->declaredMimeType->value,
            'declared_size' => $command->declaredSize,
        ]);
    }
}
