<?php

declare(strict_types=1);

namespace App\Media\Application\Command;

use App\Media\Application\MediaStorageInterface;
use App\Media\Application\Query\OrphanMediaReaderInterface;
use App\Media\Domain\MediaRepositoryInterface;
use App\Media\Domain\StorageKey;
use App\Shared\Application\Bus\CommandHandlerInterface;
use App\Shared\Domain\Identifier\MediaId;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final readonly class PurgeOrphanMediaCommandHandler implements CommandHandlerInterface
{
    /**
     * Vingt-quatre heures. C'est une regle metier, pas un reglage : en deca, un
     * televersement peut encore etre en cours ou en attente d'un envoi, et
     * purger effacerait les octets d'un utilisateur en train de les envoyer.
     */
    public const string AGE_THRESHOLD = '-24 hours';

    /**
     * Plafond par execution. Une premiere purge sur un historique charge ne
     * doit pas tenir la base pendant plusieurs minutes ; il en restera pour la
     * prochaine, et le journal le dit.
     */
    public const int BATCH_SIZE = 500;

    public function __construct(
        private OrphanMediaReaderInterface $orphans,
        private MediaRepositoryInterface $media,
        private MediaStorageInterface $storage,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(PurgeOrphanMediaCommand $command): void
    {
        $orphans = $this->orphans->orphansOlderThan(
            $this->clock->now()->modify(self::AGE_THRESHOLD),
            self::BATCH_SIZE,
        );

        foreach ($orphans as $orphan) {
            $mediaId = MediaId::fromString($orphan['id']);

            // Les OCTETS d'abord, la ligne ensuite. Si `delete()` echoue, la
            // ligne reste et la purge suivante reessaiera. L'ordre inverse
            // laisserait des octets sans reference : plus rien ne pourrait les
            // retrouver, ils occuperaient le bucket pour toujours.
            $this->storage->delete(StorageKey::fromString($orphan['storageKey']), $mediaId);

            if (null !== $orphan['thumbnailKey']) {
                $this->storage->delete(StorageKey::fromString($orphan['thumbnailKey']), $mediaId);
            }

            $this->media->remove($mediaId);

            $this->logger->notice('Media orphelin {media_id} purge', ['media_id' => $orphan['id']]);
        }

        // Un plafond silencieux se lirait comme « tout a ete purge ». On dit
        // donc explicitement qu'il en reste, et a quel niveau on s'est arrete.
        if (self::BATCH_SIZE === \count($orphans)) {
            $this->logger->warning('Purge interrompue au plafond de {batch_size} medias : relancer', [
                'batch_size' => self::BATCH_SIZE,
            ]);

            return;
        }

        $this->logger->info('Purge des orphelins terminee : {purged_count} medias', [
            'purged_count' => \count($orphans),
        ]);
    }
}
