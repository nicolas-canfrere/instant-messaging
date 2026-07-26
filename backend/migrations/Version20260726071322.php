<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260726071322 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cycle de vie des messages : edition et suppression pour tous (tranche 3).';
    }

    public function up(Schema $schema): void
    {
        // `content` devient nullable parce que la suppression pour tous EFFACE
        // reellement la charge utile : record soft, payload hard. Masquer a
        // l'affichage ne suffirait pas, un client modifie lirait encore le texte.
        //
        // Ni `deleted_by` ni `deletion_scope` : seul l'auteur supprime, la
        // premiere vaudrait donc toujours `sender_id` ; et une seule portee
        // existe, la seconde n'aurait qu'une valeur. La moderation (tranche 5)
        // les ajoutera quand elle aura un lecteur.
        //
        // Aucun index nouveau : rien ne filtre sur `deleted_at`, les tombstones
        // sont rendus et non masques. La requete dominante reste
        // (conversation_id, id DESC).
        $this->addSql(<<<'SQL'
            ALTER TABLE messages
                ALTER COLUMN content DROP NOT NULL,
                ADD COLUMN edited_at  TIMESTAMPTZ DEFAULT NULL,
                ADD COLUMN deleted_at TIMESTAMPTZ DEFAULT NULL
            SQL);

        // L'invariant central de la tranche, ecrit la ou la base peut le tenir
        // elle-meme : un message est vivant si et seulement si il a une charge
        // utile. Ce n'est pas une redondance avec l'agregat — c'est ce qui
        // protege d'une migration future, d'une correction en psql ou d'une
        // fixture bâclee.
        $this->addSql(<<<'SQL'
            ALTER TABLE messages
                ADD CONSTRAINT messages_tombstone_has_no_payload
                CHECK ((deleted_at IS NULL) = (content IS NOT NULL))
            SQL);

        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN messages.content IS 'Texte du message. NULL uniquement sur un message supprime pour tous : la charge utile est reellement effacee.'
            SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN messages.edited_at IS 'Instant de la derniere edition, en UTC. NULL si jamais edite.'
            SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN messages.deleted_at IS 'Instant de la suppression pour tous, en UTC. NULL si le message est vivant.'
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Le contenu des tombstones est definitivement perdu : on les supprime
        // plutot que de laisser `ALTER COLUMN SET NOT NULL` echouer.
        $this->addSql('DELETE FROM messages WHERE deleted_at IS NOT NULL');
        $this->addSql('ALTER TABLE messages DROP CONSTRAINT messages_tombstone_has_no_payload');
        $this->addSql(<<<'SQL'
            ALTER TABLE messages
                DROP COLUMN edited_at,
                DROP COLUMN deleted_at,
                ALTER COLUMN content SET NOT NULL
            SQL);
    }
}
