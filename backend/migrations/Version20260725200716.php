<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260725200716 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Watermarks distribue et lu sur conversation_members (tranche 2).';
    }

    public function up(Schema $schema): void
    {
        // Deux colonnes, rien d'autre. Ni presence ni frappe : ce sont des etats
        // ephemeres, et le fait qu'aucune migration ne les mentionne EST la
        // demonstration de la these de la tranche.
        //
        // Aucun index : la cle primaire (conversation_id, user_id) couvre
        // l'UPDATE, et l'agregation « lu par 3/5 » balaie les quelques membres
        // d'un seul fil. Un index sur une colonne reecrite a chaque message lu
        // couterait plus qu'il ne rapporterait.
        //
        // Aucune FOREIGN KEY vers messages : un watermark est un curseur, pas une
        // reference. Il doit survivre a la suppression du message qu'il designe
        // (tranche 3) — un ON DELETE SET NULL ferait RECULER le curseur, ce que
        // toute la tranche s'emploie a rendre impossible.
        $this->addSql(<<<'SQL'
            ALTER TABLE conversation_members
                ADD COLUMN last_delivered_message_id CHAR(26) DEFAULT NULL,
                ADD COLUMN last_read_message_id      CHAR(26) DEFAULT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE conversation_members
                DROP COLUMN last_delivered_message_id,
                DROP COLUMN last_read_message_id
            SQL);
    }
}
