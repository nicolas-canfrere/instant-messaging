<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Contract;

use App\Media\Application\Contract\MediaFinderInterface;
use App\Media\Application\Contract\MediaView;
use App\Media\Application\MediaStorageInterface;
use App\Media\Domain\MediaStatus;
use App\Media\Domain\StorageKey;
use App\Shared\Domain\Identifier\MediaId;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * Realise le contrat publie. N'interroge que `media_objects`, la table que
 * Media possede : elle ignore `message_media`, qui appartient a Message.
 *
 * Signer coute une construction d'URL par ligne, pas un aller-retour reseau :
 * une pre-signature est un calcul local. Le cout de cette classe est donc celui
 * de son UNIQUE requete.
 */
final readonly class DbalMediaFinder implements MediaFinderInterface
{
    public function __construct(
        private Connection $connection,
        private MediaStorageInterface $storage,
        private ClockInterface $clock,
    ) {
    }

    public function viewsFor(array $mediaIds): array
    {
        if ([] === $mediaIds) {
            return [];
        }

        /** @var list<array{id: string, status: string, storage_key: string, thumbnail_key: string|null, mime_type: string|null, width: int|null, height: int|null}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT id, status, storage_key, thumbnail_key, mime_type, width, height
                FROM media_objects
                WHERE id IN (:ids)
                SQL,
            ['ids' => array_map(static fn(MediaId $id): string => $id->toString(), $mediaIds)],
            ['ids' => ArrayParameterType::STRING],
        );

        $now = $this->clock->now();
        $views = [];

        foreach ($rows as $row) {
            // `from` et non `tryFrom` : un statut inconnu en base est une
            // corruption, pas un cas metier — il doit exploser bruyamment.
            $status = MediaStatus::from($row['status']);

            // On ne signe QUE le terminal reussi. Une URL vers un `processing`
            // pointerait des octets dont personne n'a encore verifie qu'ils
            // sont une image (spec §4.3) ; vers un `rejected`, elle donnerait
            // acces a ce qu'on vient precisement de refuser.
            //
            // Le CHECK `media_ready_is_measured` garantit que les mesures et la
            // miniature d'une ligne `ready` sont toutes renseignees. La
            // miniature sert donc de temoin unique de « servable » : c'est elle
            // qui porte la seule valeur dont l'absence casserait la signature.
            $thumbnailKey = MediaStatus::Ready === $status ? $row['thumbnail_key'] : null;

            $views[$row['id']] = new MediaView(
                $row['id'],
                $status->value,
                null === $thumbnailKey ? null : $row['mime_type'],
                null === $thumbnailKey ? null : $row['width'],
                null === $thumbnailKey ? null : $row['height'],
                null === $thumbnailKey
                    ? null
                    : $this->storage->presignDownload(StorageKey::fromString($row['storage_key']), $now),
                null === $thumbnailKey
                    ? null
                    : $this->storage->presignDownload(StorageKey::fromString($thumbnailKey), $now),
            );
        }

        return $views;
    }
}
