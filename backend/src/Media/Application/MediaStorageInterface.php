<?php

declare(strict_types=1);

namespace App\Media\Application;

use App\Media\Domain\MediaDisposition;
use App\Media\Domain\MediaMimeType;
use App\Media\Domain\OriginalFilename;
use App\Media\Domain\StorageKey;
use App\Shared\Domain\Identifier\MediaId;

/**
 * Le besoin, exprime sans nommer S3. `Application` ne connait ni `Aws\`, ni
 * la notion de bucket, ni celle d'endpoint : elle sait signer, lire, ecrire,
 * effacer. L'adaptateur decide comment.
 */
interface MediaStorageInterface
{
    /**
     * URL signee pour un PUT, et l'expiration REELLE de cette signature dans
     * le meme objet — jamais deux constantes independantes qui pourraient
     * diverger. La signature couvre la methode, la cle ET le Content-Type :
     * le client DOIT envoyer exactement ce type, sinon MinIO refuse. Elle ne
     * plafonne pas la taille — une URL pre-signee PUT ne le peut pas
     * (spec §3.2), c'est le worker qui tranche apres transfert.
     */
    public function presignUpload(StorageKey $key, MediaMimeType $mimeType, \DateTimeImmutable $now): PresignedUpload;

    /**
     * `$filename` est `null` pour une miniature : elle n'a pas de nom a porter,
     * ce n'est pas un fichier que l'utilisateur a choisi.
     *
     * @return non-empty-string URL signee pour un GET
     */
    public function presignDownload(
        StorageKey $key,
        MediaDisposition $disposition,
        ?OriginalFilename $filename,
        \DateTimeImmutable $now,
    ): string;

    /**
     * Rapatrie l'objet dans un fichier temporaire local et rend son chemin.
     * PAS en memoire : 10 Mio passeraient aujourd'hui et ne passeraient plus
     * le jour d'une video. La forme du code ne doit pas dependre du plafond.
     *
     * @return non-empty-string|null `null` si l'objet n'existe pas
     */
    public function downloadToTemporaryFile(StorageKey $key): ?string;

    public function put(StorageKey $key, string $localPath, MediaMimeType $mimeType): void;

    /**
     * Ne leve pas si l'objet est deja absent : effacer est idempotent. Leve
     * dans tout autre cas (identifiants, reseau, bucket manquant, 5xx) — les
     * taire ferait passer l'echec d'une purge pour un succes.
     *
     * `$mediaId` ne sert qu'au journal d'un echec reel : l'appelant le
     * connait deja, l'adaptateur ne le derive jamais de `$key`.
     */
    public function delete(StorageKey $key, MediaId $mediaId): void;
}
