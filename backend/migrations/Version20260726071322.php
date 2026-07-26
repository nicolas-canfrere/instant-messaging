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
        // La contrainte tombe EN PREMIER : elle interdit un contenu non nul sur
        // un message supprime, donc le remplissage ci-dessous echouerait tant
        // qu'elle est en place.
        $this->addSql('ALTER TABLE messages DROP CONSTRAINT messages_tombstone_has_no_payload');

        // On remplit, on ne supprime pas. Un `DELETE` laisserait
        // `conversations.last_message_id` et les deux watermarks de la tranche 2
        // designer des lignes disparues, et creuserait un trou dans la
        // pagination keyset : exactement les references pendantes que le
        // tombstone existe pour empecher. Une retro-migration ne doit pas
        // rouvrir le bug que la tranche a ferme.
        //
        // Le contenu d'origine est DEFINITIVEMENT perdu — « record soft,
        // payload hard » l'efface reellement, aucune retro-migration ne peut le
        // retrouver. On prefere donc un tombstone marque et lisible a un trou
        // dans l'ordre. Et surtout pas une chaine vide :
        // `MessageContent::fromString('')` leve `EmptyMessageContentException`,
        // une base retrogradee exploserait a la relecture de ces lignes.
        $this->addSql(<<<'SQL'
            UPDATE messages SET content = '(message supprime)' WHERE content IS NULL
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE messages
                DROP COLUMN edited_at,
                DROP COLUMN deleted_at,
                ALTER COLUMN content SET NOT NULL
            SQL);
    }
}
