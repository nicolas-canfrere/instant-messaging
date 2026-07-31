<?php

declare(strict_types=1);

namespace App\Tests\Functional\Media;

use App\Media\Application\Command\ProcessMediaCommand;
use App\Media\Application\Command\ProcessMediaCommandHandler;
use App\Media\Application\MediaStorageInterface;
use App\Media\Domain\MediaMimeType;
use App\Media\Domain\MediaObject;
use App\Media\Domain\MediaRejectionReason;
use App\Media\Domain\MediaRepositoryInterface;
use App\Media\Domain\MediaStatus;
use App\Media\Domain\OriginalFilename;
use App\Media\Domain\StorageKey;
use App\Shared\Domain\Identifier\MediaId;
use App\Shared\Domain\Identifier\UserId;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MediaProcessingTest extends KernelTestCase
{
    private const string FIXTURES = __DIR__ . '/../../Fixtures/media/';

    public function testAValidImageBecomesReadyWithAThumbnail(): void
    {
        $mediaId = $this->uploaded('valide.jpg', MediaMimeType::Jpeg);

        $this->process($mediaId);

        $media = $this->repository()->ofId($mediaId);
        self::assertSame(MediaStatus::Ready, $media->status());
        self::assertSame(MediaMimeType::Jpeg, $media->mimeType());
        self::assertSame(1600, $media->width());
        self::assertNotNull($media->thumbnailKey());
        $this->assertThumbnailIsAReal400PxJpeg($media->thumbnailKey());
    }

    public function testAPngOriginalStillProducesAJpegThumbnail(): void
    {
        // C'est la raison d'etre de StorageKey::forThumbnail() : le worker
        // choisit toujours JPEG pour la miniature, quel que soit le format de
        // l'original. `valide.png` est la seule fixture qui le met a
        // l'epreuve.
        $mediaId = $this->uploaded('valide.png', MediaMimeType::Png);

        $this->process($mediaId);

        $media = $this->repository()->ofId($mediaId);
        self::assertSame(MediaStatus::Ready, $media->status());
        self::assertSame(MediaMimeType::Png, $media->mimeType());
        self::assertNotNull($media->thumbnailKey());
        $this->assertThumbnailIsAReal400PxJpeg($media->thumbnailKey());
    }

    public function testAPhpFileRenamedJpgIsRejectedAsUnsupportedTypeAndItsBytesAreDestroyed(): void
    {
        $mediaId = $this->uploaded('piege.jpg', MediaMimeType::Jpeg);

        $this->process($mediaId);

        $media = $this->repository()->ofId($mediaId);
        self::assertSame(MediaStatus::Rejected, $media->status());
        self::assertSame(MediaRejectionReason::UnsupportedType, $media->rejectionReason());
        // On ne conserve pas les octets d'un fichier qu'on ne servira jamais.
        self::assertNull($this->storage()->downloadToTemporaryFile($media->storageKey()));
    }

    public function testATruncatedFileIsRejectedAsUndecodableRatherThanUnsupportedType(): void
    {
        // `tronque.gif` porte une vraie signature PNG tronquee : le type est
        // dans l'allowlist, seul le decodage echoue. Une confusion avec
        // UnsupportedType enverrait l'operateur corriger le mauvais probleme
        // — c'est cette distinction que ce test verifie de bout en bout, worker compris.
        $mediaId = $this->uploaded('tronque.gif', MediaMimeType::Gif);

        $this->process($mediaId);

        $media = $this->repository()->ofId($mediaId);
        self::assertSame(MediaStatus::Rejected, $media->status());
        self::assertSame(MediaRejectionReason::Undecodable, $media->rejectionReason());
        self::assertNull($this->storage()->downloadToTemporaryFile($media->storageKey()));
    }

    public function testAMissingObjectIsRejectedRatherThanRetriedForever(): void
    {
        // Litteral plutot que derive de crc32() : ce test n'a pas de fixture
        // associee, seulement un identifiant qui doit rester un ULID valide.
        $mediaId = MediaId::fromString('01JQZ000000000000000099999');
        $this->repository()->add(MediaObject::request(
            $mediaId,
            $this->anyUserId(),
            StorageKey::forOriginal($mediaId, MediaMimeType::Jpeg),
            OriginalFilename::fromString('photo.jpg'),
            MediaMimeType::Jpeg,
            2_000,
            new \DateTimeImmutable('2026-07-26T09:00:00+00:00'),
        ));

        $this->process($mediaId);

        self::assertSame(MediaRejectionReason::MissingObject, $this->repository()->ofId($mediaId)->rejectionReason());
    }

    public function testRedeliveringTheMessageForAnAlreadyReadyMediaLeavesItUntouched(): void
    {
        // Seed distinct de celui de testAValidImageBecomesReadyWithAThumbnail :
        // les deux tests partagent la meme fixture mais doivent produire des
        // ULID distincts, sinon la seconde insertion viole la cle primaire.
        $mediaId = $this->uploaded('valide.jpg', MediaMimeType::Jpeg, idSeed: 'valide.jpg-redelivery');

        $this->process($mediaId);
        $ready = $this->repository()->ofId($mediaId);
        self::assertSame(MediaStatus::Ready, $ready->status());
        $thumbnailKeyAfterFirstProcessing = $ready->thumbnailKey();

        // Un redelivrage Messenger (au-moins-une-fois) sur un media deja
        // terminal ne doit rien rejouer : le garde-fou du handler doit
        // sortir avant tout acces au stockage.
        $this->process($mediaId);

        $stillReady = $this->repository()->ofId($mediaId);
        self::assertSame(MediaStatus::Ready, $stillReady->status());
        self::assertSame((string) $thumbnailKeyAfterFirstProcessing, (string) $stillReady->thumbnailKey());
        self::assertSame(1600, $stillReady->width());
    }

    /** Verifie la miniature dans le bucket : dimensions ET format reels, pas seulement sa presence. */
    private function assertThumbnailIsAReal400PxJpeg(StorageKey $thumbnailKey): void
    {
        $thumbnailPath = $this->storage()->downloadToTemporaryFile($thumbnailKey);
        self::assertNotNull($thumbnailPath);

        $size = getimagesize($thumbnailPath);
        @unlink($thumbnailPath);

        self::assertIsArray($size);
        self::assertSame(IMAGETYPE_JPEG, $size[2]);
        self::assertSame(400, $size[0]);
        self::assertSame(225, $size[1]);
    }

    /**
     * Depose reellement le fichier dans MinIO, puis rend l'identifiant.
     *
     * `$idSeed` : par defaut `$fixture` lui-meme. A fournir explicitement
     * quand deux tests reutilisent la meme fixture — sinon l'ULID derive de
     * `crc32()` collide, et la seconde insertion viole la cle primaire.
     */
    private function uploaded(string $fixture, MediaMimeType $declared, ?string $idSeed = null): MediaId
    {
        self::bootKernel();
        $mediaId = MediaId::fromString(sprintf('01JQZ0000000000000000%05d', crc32($idSeed ?? $fixture) % 100_000));
        $key = StorageKey::forOriginal($mediaId, $declared);

        $media = MediaObject::request(
            $mediaId,
            $this->anyUserId(),
            $key,
            OriginalFilename::fromString('photo.jpg'),
            $declared,
            2_000,
            new \DateTimeImmutable('2026-07-26T09:00:00+00:00'),
        );
        $media->markUploaded(new \DateTimeImmutable('2026-07-26T09:00:10+00:00'));
        $this->repository()->add($media);
        $this->repository()->save($media);

        $this->storage()->put($key, self::FIXTURES . $fixture, $declared);

        return $mediaId;
    }

    /**
     * Invoque le vrai handler directement, sans passer par le bus : le
     * transport `media` est `in-memory://` en test (aucun RabbitMQ requis
     * en CI), donc `dispatch()` ne ferait qu'y deposer le message sans le
     * consommer — `HandleMessageMiddleware` ne s'execute jamais quand un
     * message est route vers un transport. C'est le worker (conteneur
     * separe) qui consomme la vraie file ; ici on declenche le handler « a
     * la main », comme le prevoit ce test.
     *
     * Consequence a connaitre pour la suite : cet appel direct CONTOURNE
     * `TransactionalMiddleware`, seul composant qui publie les domain events
     * collectes par l'agregat une fois la transaction commitee. Rien dans ce
     * fichier ne prouve donc que `MediaWasProcessed` est reellement publie —
     * seul le nouvel etat de l'agregat (statut, motif, cle de miniature) est
     * verifie ici. La tache 8, qui construit la choregraphie sur cet
     * evenement, doit couvrir sa publication par un autre chemin (test qui
     * passe par le vrai `command.bus` + `TransactionalMiddleware`, ou test
     * dedie de ce middleware).
     */
    private function process(MediaId $mediaId): void
    {
        self::bootKernel();

        /** @var ProcessMediaCommandHandler $handler */
        $handler = self::getContainer()->get(ProcessMediaCommandHandler::class);
        $handler(new ProcessMediaCommand($mediaId));
    }

    private function repository(): MediaRepositoryInterface
    {
        self::bootKernel();

        /** @var MediaRepositoryInterface */
        return self::getContainer()->get(MediaRepositoryInterface::class);
    }

    private function storage(): MediaStorageInterface
    {
        self::bootKernel();

        /** @var MediaStorageInterface */
        return self::getContainer()->get(MediaStorageInterface::class);
    }

    private function anyUserId(): UserId
    {
        self::bootKernel();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        /** @var string $id */
        $id = $connection->fetchOne('SELECT id FROM users ORDER BY id LIMIT 1');

        return UserId::fromString($id);
    }
}
