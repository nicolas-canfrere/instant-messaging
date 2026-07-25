<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Schema initial de la tranche 1. SQL explicite : les index, les contraintes
 * et l'unicite de direct_key sont intentionnels, pas deduits d'un diff.
 *
 * Chaque table et chaque colonne porte un COMMENT : c'est la seule
 * documentation que `\d+` et les clients SQL affichent au moment ou on en a
 * besoin, et elle ne peut pas diverger du schema puisqu'elle y vit.
 */
final class Version20260725142549 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Schema initial : users, conversations, conversation_members, messages';
    }

    public function up(Schema $schema): void
    {
        $this->createUsers();
        $this->createConversations();
        $this->createConversationMembers();
        $this->createMessages();
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE messages');
        $this->addSql('DROP TABLE conversation_members');
        $this->addSql('DROP TABLE conversations');
        $this->addSql('DROP TABLE users');
    }

    private function createUsers(): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE users (
                id CHAR(26) PRIMARY KEY,
                username VARCHAR(50) NOT NULL UNIQUE,
                display_name VARCHAR(100) NOT NULL,
                email VARCHAR(180) NOT NULL UNIQUE,
                password_hash VARCHAR(255) DEFAULT NULL,
                provider VARCHAR(20) NOT NULL DEFAULT 'local',
                external_id VARCHAR(191) DEFAULT NULL,
                created_at TIMESTAMPTZ NOT NULL,
                CONSTRAINT users_provider_external_id_key UNIQUE (provider, external_id)
            )
            SQL);

        $this->comment('TABLE users', "Comptes utilisateurs. Possedee par le contexte Identity : aucun autre contexte ne lit cette table, ils passent par son contrat publie.");
        $this->comment('COLUMN users.id', "ULID. Triable chronologiquement par comparaison de chaines, donc l'ordre de creation est gratuit.");
        $this->comment('COLUMN users.username', "Identifiant de connexion, unique. C'est ce que l'utilisateur saisit pour s'authentifier.");
        $this->comment('COLUMN users.display_name', 'Nom affiche dans l\'interface. Modifiable, sans unicite : deux Alice sont permises.');
        $this->comment('COLUMN users.email', 'Unique. Sert de point de rattachement si un fournisseur externe est branche plus tard.');
        $this->comment('COLUMN users.password_hash', "NULL quand le compte vient d'un fournisseur externe : il n'y a alors aucun mot de passe local a verifier.");
        $this->comment('COLUMN users.provider', "Origine du compte : 'local' ou le nom d'un fournisseur externe. Determine comment l'authentification est verifiee.");
        $this->comment('COLUMN users.external_id', "Identifiant du compte chez le fournisseur. Unique par couple (provider, external_id), pour qu'un meme compte externe ne soit pas rattache deux fois.");
        $this->comment('COLUMN users.created_at', 'Date de creation du compte, en UTC.');
    }

    private function createConversations(): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE conversations (
                id CHAR(26) PRIMARY KEY,
                type VARCHAR(10) NOT NULL,
                title VARCHAR(120) DEFAULT NULL,
                created_by CHAR(26) NOT NULL REFERENCES users (id),
                direct_key CHAR(53) DEFAULT NULL UNIQUE,
                last_message_id CHAR(26) DEFAULT NULL,
                last_message_at TIMESTAMPTZ DEFAULT NULL,
                last_message_preview VARCHAR(80) DEFAULT NULL,
                last_message_sender_id CHAR(26) DEFAULT NULL,
                created_at TIMESTAMPTZ NOT NULL,
                CONSTRAINT conversations_type_check CHECK (type IN ('direct', 'group')),
                CONSTRAINT conversations_direct_needs_key CHECK (
                    (type = 'direct' AND direct_key IS NOT NULL)
                    OR (type = 'group' AND direct_key IS NULL)
                )
            )
            SQL);

        // Trie la liste des conversations d'un utilisateur par activite recente.
        // NULLS LAST : une conversation sans message reste en fin de liste.
        $this->addSql('CREATE INDEX conversations_last_message_at_idx ON conversations (last_message_at DESC NULLS LAST)');

        $this->comment('TABLE conversations', 'Fils de discussion, directs ou de groupe. Possedee par le contexte Conversation.');
        $this->comment('COLUMN conversations.id', 'ULID.');
        $this->comment('COLUMN conversations.type', "'direct' (exactement deux membres, immuables) ou 'group' (membres modifiables). Contraint par conversations_type_check.");
        $this->comment('COLUMN conversations.title', "Titre du groupe. Toujours NULL pour un direct, dont le titre est calcule cote client a partir de l'autre membre.");
        $this->comment('COLUMN conversations.created_by', "Auteur de la creation. Conserve meme s'il quitte le groupe, d'ou l'absence de suppression en cascade.");
        $this->comment('COLUMN conversations.direct_key', "Paire d'identifiants triee et jointe par ':' pour un direct, NULL pour un groupe. Son unicite rend la creation d'un direct idempotente : deux demandes concurrentes entre les memes personnes convergent vers la meme ligne.");
        $this->comment('COLUMN conversations.last_message_id', "Pointeur denormalise vers le dernier message. Volontairement sans cle etrangere vers messages : elle formerait un cycle avec messages.conversation_id. Maintenu dans la meme transaction que l'insert du message.");
        $this->comment('COLUMN conversations.last_message_at', 'Date du dernier message. Colonne de tri de la liste des conversations : elle evite un agregat sur messages a chaque affichage.');
        $this->comment('COLUMN conversations.last_message_preview', "Premiers caracteres du dernier message, pour l'apercu dans la liste. Tronque a 80 caracteres : c'est un apercu, jamais la source de verite.");
        $this->comment('COLUMN conversations.last_message_sender_id', 'Auteur du dernier message, pour afficher « Alice : ... » sans jointure.');
        $this->comment('COLUMN conversations.created_at', 'Date de creation, en UTC.');
    }

    private function createConversationMembers(): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE conversation_members (
                conversation_id CHAR(26) NOT NULL REFERENCES conversations (id) ON DELETE CASCADE,
                user_id CHAR(26) NOT NULL REFERENCES users (id),
                role VARCHAR(10) NOT NULL,
                joined_at TIMESTAMPTZ NOT NULL,
                PRIMARY KEY (conversation_id, user_id),
                CONSTRAINT conversation_members_role_check CHECK (role IN ('member', 'admin'))
            )
            SQL);

        // Sens de lecture inverse de la cle primaire : « les conversations de cet
        // utilisateur », qui est la requete de la page d'accueil.
        $this->addSql('CREATE INDEX conversation_members_user_idx ON conversation_members (user_id)');

        $this->comment('TABLE conversation_members', "Appartenance d'un utilisateur a une conversation. Source de verite de l'autorisation : ne pas y figurer se traduit par un 404, jamais par un 403, pour ne pas confirmer l'existence de la conversation.");
        $this->comment('COLUMN conversation_members.conversation_id', "Supprime en cascade avec la conversation : une appartenance sans conversation n'a aucun sens.");
        $this->comment('COLUMN conversation_members.user_id', "Pas de cascade depuis users : supprimer un compte ne doit pas faire disparaitre silencieusement l'historique des autres.");
        $this->comment('COLUMN conversation_members.role', "'member' ou 'admin'. Seul un admin peut ajouter ou retirer des membres d'un groupe.");
        $this->comment('COLUMN conversation_members.joined_at', "Date d'entree dans la conversation, en UTC.");
    }

    private function createMessages(): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE messages (
                id CHAR(26) PRIMARY KEY,
                conversation_id CHAR(26) NOT NULL REFERENCES conversations (id) ON DELETE CASCADE,
                sender_id CHAR(26) NOT NULL REFERENCES users (id),
                content TEXT NOT NULL,
                client_message_id CHAR(26) NOT NULL,
                created_at TIMESTAMPTZ NOT NULL,
                CONSTRAINT messages_sender_client_id_key UNIQUE (sender_id, client_message_id)
            )
            SQL);

        // Requete dominante : les N derniers messages d'une conversation.
        // `id DESC` suffit a la pagination keyset, l'ULID etant deja chronologique.
        $this->addSql('CREATE INDEX messages_conversation_id_idx ON messages (conversation_id, id DESC)');

        $this->comment('TABLE messages', "Messages envoyes. Possedee par le contexte Message, qui n'ecrit jamais dans conversations : il publie un fait, Conversation reagit.");
        $this->comment('COLUMN messages.id', "ULID attribue par le serveur. Sert aussi de curseur de pagination et d'identifiant de l'evenement Mercure, ce qui rendra Last-Event-ID exploitable sans changer de format.");
        $this->comment('COLUMN messages.conversation_id', 'Conversation de destination. Supprime en cascade avec elle.');
        $this->comment('COLUMN messages.sender_id', 'Auteur. Sans cascade : supprimer un compte ne doit pas trouer les conversations des autres.');
        $this->comment('COLUMN messages.content', 'Texte du message. Ne doit jamais etre journalise, meme en debug.');
        $this->comment('COLUMN messages.client_message_id', "ULID genere par le client avant l'envoi. Unique par expediteur : c'est ce qui rend l'envoi idempotent, un renvoi apres coupure reseau retombant sur la ligne deja ecrite au lieu d'en creer une seconde.");
        $this->comment('COLUMN messages.created_at', "Date d'envoi, en UTC. L'ordre d'affichage suit l'id, pas cette colonne.");
    }

    /** COMMENT ON n'accepte pas de parametre lie : ce SQL est entierement litteral, d'ou l'echappement manuel. */
    private function comment(string $target, string $comment): void
    {
        $this->addSql(sprintf("COMMENT ON %s IS '%s'", $target, str_replace("'", "''", $comment)));
    }
}
