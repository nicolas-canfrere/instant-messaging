<?php

declare(strict_types=1);

namespace App\Tests\Functional\Media;

use App\Tests\Functional\DatabaseTestCase;
use Doctrine\DBAL\Exception\DriverException;

/**
 * La contrainte est BILATERALE, et c'est le point : elle interdit non
 * seulement une image prete sans dimensions — le bug que le placeholder a
 * proportions du front ne saurait pas gerer — mais aussi un document pret
 * AVEC une miniature, qui signalerait que le worker a pris la mauvaise
 * branche. Une contrainte qui n'interdit que la moitie des etats impossibles
 * n'est qu'un commentaire.
 */
final class MediaReadyConstraintTest extends DatabaseTestCase
{
    public function testAReadyImageWithoutDimensionsIsRefused(): void
    {
        $this->expectException(DriverException::class);

        $this->insertMedia(status: 'ready', mimeType: 'image/png', width: null, height: null, thumbnailKey: 'media/01JQZ0000000000000000000AB-thumb.jpg');
    }

    public function testAReadyDocumentCarryingAThumbnailIsRefused(): void
    {
        $this->expectException(DriverException::class);

        $this->insertMedia(status: 'ready', mimeType: 'text/plain', width: null, height: null, thumbnailKey: 'media/01JQZ0000000000000000000AB-thumb.jpg');
    }

    public function testAReadyDocumentWithoutDimensionsIsAccepted(): void
    {
        // Aucune exception attendue : c'est precisement l'etat qu'un document
        // pret doit pouvoir prendre.
        $this->insertMedia(status: 'ready', mimeType: 'text/plain', width: null, height: null, thumbnailKey: null);

        $this->addToAssertionCount(1);
    }

    private function insertMedia(string $status, string $mimeType, ?int $width, ?int $height, ?string $thumbnailKey): void
    {
        /** @var string $ownerId */
        $ownerId = $this->connection->fetchOne('SELECT id FROM users ORDER BY id LIMIT 1');

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO media_objects (
                    id, owner_id, storage_key, original_filename, thumbnail_key, status,
                    declared_mime_type, declared_size, mime_type, width, height, byte_size,
                    created_at, processed_at
                )
                VALUES (
                    :id, :owner_id, :storage_key, :original_filename, :thumbnail_key, :status,
                    :declared_mime_type, :declared_size, :mime_type, :width, :height, :byte_size,
                    NOW(), NOW()
                )
                SQL,
            [
                'id' => '01JQZ0000000000000000000EE',
                'owner_id' => $ownerId,
                'storage_key' => 'media/01JQZ0000000000000000000EE.txt',
                'original_filename' => 'fichier.txt',
                'thumbnail_key' => $thumbnailKey,
                'status' => $status,
                'declared_mime_type' => $mimeType,
                'declared_size' => 4_096,
                'mime_type' => $mimeType,
                'width' => $width,
                'height' => $height,
                'byte_size' => 4_096,
            ],
        );
    }
}
