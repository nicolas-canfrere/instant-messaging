<?php

declare(strict_types=1);

namespace App\Media\Application\Command;

use App\Media\Domain\MediaNotFoundException;
use App\Media\Domain\MediaRepositoryInterface;
use App\Media\Domain\MediaStatus;
use App\Shared\Application\Bus\CommandDispatcherInterface;
use App\Shared\Application\Bus\CommandHandlerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final readonly class ConfirmMediaUploadCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private MediaRepositoryInterface $media,
        private CommandDispatcherInterface $commands,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ConfirmMediaUploadCommand $command): void
    {
        // `ofId` leve MediaNotFoundException — traduite en 404. Un acteur sans
        // lien avec ce media (juste en dessous) leve la MEME exception : les
        // deux cas doivent rester indistinguables, sinon un 403 confirmerait a
        // Bob que le media d'Alice existe (CLAUDE.md, « 404 et non 403 pour
        // un non-membre » — ici, un non-proprietaire).
        $media = $this->media->ofId($command->mediaId);

        if (!$media->ownerId()->equals($command->confirmedBy)) {
            $this->logger->warning('Confirmation du media {media_id} par un acteur qui n\'y a aucun droit', [
                'media_id' => $command->mediaId->toString(),
                'actor_id' => $command->confirmedBy->toString(),
            ]);

            throw MediaNotFoundException::withId($command->mediaId);
        }

        // Le backend ne verifie PAS ici que l'objet existe dans le bucket : ce
        // serait un appel reseau synchrone pour une information que le worker
        // va de toute facon chercher. Un objet absent devient un Rejected avec
        // la raison `missing_object` (spec §3.3).
        $wasPending = MediaStatus::Pending === $media->status();
        $media->markUploaded($this->clock->now());
        $this->media->save($media);

        // Ne publier le traitement QUE si la transition a eu lieu : sans cette
        // garde, un rejeu ferait retraiter le meme media. L'agregat est
        // idempotent, le dispatch ne l'est pas.
        if (!$wasPending) {
            return;
        }

        $this->commands->dispatch(new ProcessMediaCommand($command->mediaId));

        $this->logger->info('Traitement du media {media_id} demande', [
            'media_id' => $command->mediaId->toString(),
        ]);
    }
}
