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

        $this->comment('TABLE media_objects', "Objets televerses (images). Possedee par le contexte Media, qui ignore l'existence de Conversation et Message : un media existe independamment de tout rattachement (spec §1.1).");
        $this->comment('COLUMN media_objects.id', 'ULID.');
        $this->comment('COLUMN media_objects.owner_id', "Utilisateur qui a demande le televersement. C'est lui, et lui seul, qui pourra rattacher ce media a un message.");
        $this->comment('COLUMN media_objects.storage_key', "Cle de l'objet original dans le bucket, fabriquee par StorageKey::forOriginal(). Unique : deux medias ne pointent jamais vers le meme objet.");
        $this->comment('COLUMN media_objects.thumbnail_key', "Cle de la miniature generee par le worker. NULL jusqu'a ce que le traitement aboutisse : voir media_ready_is_measured.");
        $this->comment('COLUMN media_objects.status', "Cycle de vie du traitement asynchrone : pending (pre-signe, rien recu) -> processing (octets recus, worker pas encore tranche) -> ready | rejected (terminaux).");
        $this->comment('COLUMN media_objects.declared_mime_type', "Type MIME ANNONCE par le client au moment de la demande de televersement, avant qu'aucun octet ne soit recu. Une promesse, pas une mesure.");
        $this->comment('COLUMN media_objects.declared_size', "Taille ANNONCEE par le client en octets, avant transfert. Permet de rejeter une demande hors plafond sans attendre l'upload.");
        $this->comment('COLUMN media_objects.mime_type', "Type MIME MESURE par le worker apres decodage reel des octets. Coexiste avec declared_mime_type : l'ecart entre les deux est ce qui declenche un rejet unsupported_type.");
        $this->comment('COLUMN media_objects.width', 'Largeur mesuree par le worker, en pixels. NULL avant traitement.');
        $this->comment('COLUMN media_objects.height', 'Hauteur mesuree par le worker, en pixels. NULL avant traitement.');
        $this->comment('COLUMN media_objects.byte_size', 'Taille REELLE mesuree par le worker, en octets. Peut differer de declared_size, ce qui declenche un rejet too_large.');
        $this->comment('COLUMN media_objects.rejection_reason', "Motif du refus. NULL sauf a l'etat rejected : voir media_rejected_has_reason.");
        $this->comment('COLUMN media_objects.created_at', 'Instant de la demande de televersement (MediaObject::request()), en UTC.');
        $this->comment('COLUMN media_objects.processed_at', "Instant ou le worker a tranche, pret ou rejete. NULL tant que le traitement n'a pas eu lieu.");

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

        $this->comment('TABLE message_media', "Rattachement d'un media a un message, distinct de media_objects : un media existe (upload, traitement) avant d'etre rattache a un message.");
        $this->comment('COLUMN message_media.message_id', 'Message porteur. Supprime en cascade avec lui.');
        $this->comment('COLUMN message_media.media_id', "Media rattache. UNIQUE : un media ne peut illustrer qu'un seul message, jamais partage entre plusieurs.");
        $this->comment('COLUMN message_media.position', "Ordre d'affichage parmi les medias d'un meme message. UNIQUE par message : deux medias ne peuvent pas revendiquer le meme rang.");

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

    /** COMMENT ON n'accepte pas de parametre lie : ce SQL est entierement litteral, d'ou l'echappement manuel. */
    private function comment(string $target, string $comment): void
    {
        $this->addSql(sprintf("COMMENT ON %s IS '%s'", $target, str_replace("'", "''", $comment)));
    }
}
