<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260726144434 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tranche 4 : media_objects, message_media, et le CHECK des tombstones relache.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE media_objects (
                id                  CHAR(26)    PRIMARY KEY,
                owner_id            CHAR(26)    NOT NULL REFERENCES users(id),
                storage_key         TEXT        NOT NULL UNIQUE,
                thumbnail_key       TEXT,
                status              TEXT        NOT NULL,
                declared_mime_type  TEXT        NOT NULL,
                declared_size       INTEGER     NOT NULL,
                mime_type           TEXT,
                width               INTEGER,
                height              INTEGER,
                byte_size           INTEGER,
                rejection_reason    TEXT,
                created_at          TIMESTAMPTZ NOT NULL,
                processed_at        TIMESTAMPTZ,

                CONSTRAINT media_ready_is_measured CHECK (
                    status <> 'ready'
                    OR (mime_type IS NOT NULL AND width IS NOT NULL AND height IS NOT NULL
                        AND byte_size IS NOT NULL AND thumbnail_key IS NOT NULL)
                ),
                CONSTRAINT media_rejected_has_reason CHECK (
                    status <> 'rejected' OR rejection_reason IS NOT NULL
                )
            )
            SQL);

        // Index PARTIEL : la purge des orphelins ne balaie que le non-terminal,
        // qui reste minoritaire. Un index complet sur created_at grossirait
        // avec l'historique pour une requete qui ne le lit jamais.
        $this->addSql(<<<'SQL'
            CREATE INDEX media_objects_pending_idx ON media_objects (created_at)
            WHERE status IN ('pending', 'processing')
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE message_media (
                message_id CHAR(26) NOT NULL REFERENCES messages(id) ON DELETE CASCADE,
                media_id   CHAR(26) NOT NULL REFERENCES media_objects(id),
                position   SMALLINT NOT NULL,
                PRIMARY KEY (message_id, media_id),
                UNIQUE (media_id),
                UNIQUE (message_id, position)
            )
            SQL);

        // T3 posait une EQUIVALENCE entre « supprime » et « sans contenu ».
        // Un message qui n'a jamais porte que des images la viole. On la
        // relache en implication : « un tombstone n'a pas de contenu » reste
        // garanti, « un message sans contenu est un tombstone » cesse de
        // l'etre — c'est exactement ce que la tranche rend faux (spec §2.4).
        $this->addSql('ALTER TABLE messages DROP CONSTRAINT messages_tombstone_has_no_payload');
        $this->addSql(<<<'SQL'
            ALTER TABLE messages ADD CONSTRAINT messages_tombstone_has_no_payload
            CHECK (deleted_at IS NULL OR content IS NULL)
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Restaurer l'equivalence exige qu'aucun message image-seule ne
        // subsiste : on leur redonne un contenu, comme la migration de T3
        // le faisait pour les tombstones.
        $this->addSql(<<<'SQL'
            UPDATE messages SET content = '(image)'
            WHERE content IS NULL AND deleted_at IS NULL
            SQL);
        $this->addSql('ALTER TABLE messages DROP CONSTRAINT messages_tombstone_has_no_payload');
        $this->addSql(<<<'SQL'
            ALTER TABLE messages ADD CONSTRAINT messages_tombstone_has_no_payload
            CHECK ((deleted_at IS NULL) = (content IS NOT NULL))
            SQL);
        $this->addSql('DROP TABLE message_media');
        $this->addSql('DROP TABLE media_objects');
    }
}
