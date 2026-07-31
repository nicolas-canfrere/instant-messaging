<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260731154058 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Ajoute le nom de fichier d'origine sur media_objects";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media_objects ADD COLUMN original_filename TEXT');

        // Retro-remplissage : les medias de la tranche 4 n'ont jamais porte de
        // nom. On leur en fabrique un depuis la cle de stockage, ce qui donne
        // « 01JX....jpg » — laid mais honnete, et surtout telechargeable. On
        // n'invente aucune information qu'on n'a pas.
        $this->addSql("UPDATE media_objects SET original_filename = regexp_replace(storage_key, '^media/', '') WHERE original_filename IS NULL");

        $this->addSql('ALTER TABLE media_objects ALTER COLUMN original_filename SET NOT NULL');

        $this->comment(
            'COLUMN media_objects.original_filename',
            "Nom donne au fichier par l'utilisateur, tel qu'il l'a envoye. Ne devient JAMAIS un chemin : la cle de stockage vient de l'ULID. Sa seule destination est l'en-tete Content-Disposition de l'URL signee.",
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media_objects DROP COLUMN original_filename');
    }

    /** COMMENT ON n'accepte pas de parametre lie : ce SQL est entierement litteral, d'ou l'echappement manuel. */
    private function comment(string $target, string $comment): void
    {
        $this->addSql(sprintf("COMMENT ON %s IS '%s'", $target, str_replace("'", "''", $comment)));
    }
}
