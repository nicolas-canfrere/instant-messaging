<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rend le CHECK media_ready_is_measured bilateral : il interdisait deja une
 * image prete sans mesures, il interdit maintenant aussi un document pret
 * portant une miniature. Sans ce second sens, la contrainte ne couvrirait
 * que la moitie des etats impossibles.
 */
final class Version20260731162354 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rend le CHECK media_ready_is_measured bilateral (image mesuree, document nu)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media_objects DROP CONSTRAINT media_ready_is_measured');
        $this->addSql(<<<'SQL'
            ALTER TABLE media_objects ADD CONSTRAINT media_ready_is_measured CHECK (
                status <> 'ready'
                OR (
                    mime_type IS NOT NULL
                    AND byte_size IS NOT NULL
                    AND (
                        (mime_type LIKE 'image/%'
                            AND width IS NOT NULL AND height IS NOT NULL AND thumbnail_key IS NOT NULL)
                        OR
                        (mime_type NOT LIKE 'image/%'
                            AND width IS NULL AND height IS NULL AND thumbnail_key IS NULL)
                    )
                )
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media_objects DROP CONSTRAINT media_ready_is_measured');
        $this->addSql(<<<'SQL'
            ALTER TABLE media_objects ADD CONSTRAINT media_ready_is_measured CHECK (
                status <> 'ready'
                OR (mime_type IS NOT NULL AND width IS NOT NULL AND height IS NOT NULL
                    AND byte_size IS NOT NULL AND thumbnail_key IS NOT NULL)
            )
            SQL);
    }
}
