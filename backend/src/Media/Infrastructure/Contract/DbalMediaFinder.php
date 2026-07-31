<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Contract;

use App\Media\Application\Contract\MediaFinderInterface;
use App\Media\Application\Contract\MediaView;
use App\Media\Application\MediaStorageInterface;
use App\Media\Domain\MediaDisposition;
use App\Media\Domain\MediaFamily;
use App\Media\Domain\MediaMimeType;
use App\Media\Domain\MediaStatus;
use App\Media\Domain\OriginalFilename;
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

        /** @var list<array{id: string, status: string, storage_key: string, thumbnail_key: string|null, mime_type: string|null, width: int|null, height: int|null, original_filename: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT id, status, storage_key, thumbnail_key, mime_type, width, height, original_filename
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
            // sont un media servable (spec §4.3) ; vers un `rejected`, elle
            // donnerait acces a ce qu'on vient precisement de refuser.
            //
            // La miniature ne peut plus servir de temoin : un document pret
            // n'en a pas. C'est le statut qui tranche, et le CHECK
            // `media_ready_is_measured` garantit qu'une ligne `ready` porte
            // tout ce que sa famille exige.
            $isServable = MediaStatus::Ready === $status;
            $mimeType = null === $row['mime_type'] ? null : MediaMimeType::from($row['mime_type']);
            $filename = OriginalFilename::fromString($row['original_filename']);

            // Un document se telecharge, une image s'affiche : c'est la
            // famille du type constate qui decide, pas le statut.
            $disposition = MediaFamily::Document === $mimeType?->family()
                ? MediaDisposition::Attachment
                : MediaDisposition::Inline;

            $views[$row['id']] = new MediaView(
                $row['id'],
                $status->value,
                $isServable ? $row['mime_type'] : null,
                $isServable ? $row['width'] : null,
                $isServable ? $row['height'] : null,
                $isServable
                    // Avec nom : l'image s'affiche toujours dans <img>, mais
                    // « Enregistrer sous… » propose enfin le vrai nom.
                    ? $this->storage->presignDownload(StorageKey::fromString($row['storage_key']), $disposition, $filename, $now)
                    : null,
                // Reste conditionne a la miniature ELLE-MEME : elle est nulle
                // pour tout document, et le front s'en sert pour choisir son
                // rendu.
                $isServable && null !== $row['thumbnail_key']
                    ? $this->storage->presignDownload(StorageKey::fromString($row['thumbnail_key']), MediaDisposition::Inline, null, $now)
                    : null,
                $filename->toString(),
            );
        }

        return $views;
    }
}
