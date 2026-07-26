<?php

declare(strict_types=1);

namespace App\Media\Application;

use App\Media\Domain\MediaMimeType;
use App\Media\Domain\StorageKey;

/**
 * Le besoin, exprime sans nommer S3. `Application` ne connait ni `Aws\`, ni
 * la notion de bucket, ni celle d'endpoint : elle sait signer, lire, ecrire,
 * effacer. L'adaptateur decide comment.
 */
interface MediaStorageInterface
{
    /**
     * URL signee pour un PUT. La signature couvre la methode, la cle ET le
     * Content-Type : le client DOIT envoyer exactement ce type, sinon MinIO
     * refuse. Elle ne plafonne pas la taille — une URL pre-signee PUT ne le
     * peut pas (spec §3.2), c'est le worker qui tranche apres transfert.
     *
     * @return non-empty-string
     */
    public function presignUpload(StorageKey $key, MediaMimeType $mimeType, \DateTimeImmutable $now): string;

    /** @return non-empty-string URL signee pour un GET */
    public function presignDownload(StorageKey $key, \DateTimeImmutable $now): string;

    /**
     * Rapatrie l'objet dans un fichier temporaire local et rend son chemin.
     * PAS en memoire : 10 Mio passeraient aujourd'hui et ne passeraient plus
     * le jour d'une video. La forme du code ne doit pas dependre du plafond.
     *
     * @return non-empty-string|null `null` si l'objet n'existe pas
     */
    public function downloadToTemporaryFile(StorageKey $key): ?string;

    public function put(StorageKey $key, string $localPath, MediaMimeType $mimeType): void;

    /** Ne leve pas si l'objet est deja absent : effacer est idempotent. */
    public function delete(StorageKey $key): void;
}
