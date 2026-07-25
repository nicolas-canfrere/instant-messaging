<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;

/** Verifie les garanties sur lesquelles reposeront tous les tests de persistance. */
final class DatabaseIsolationTest extends DatabaseTestCase
{
    public function testTheSchemaIsMigrated(): void
    {
        $tables = $this->connection->createSchemaManager()->listTableNames();

        self::assertContains('users', $tables);
        self::assertContains('conversations', $tables);
        self::assertContains('conversation_members', $tables);
        self::assertContains('messages', $tables);
    }

    /**
     * Le point que `use_savepoints: true` conditionne : TransactionalMiddleware
     * ouvre sa propre transaction pendant un test. Sans savepoints, son commit
     * fermerait celle du test et le rollBack final n'annulerait plus rien —
     * les donnees d'un test fuiteraient dans le suivant.
     */
    public function testANestedCommitDoesNotCloseTheTestTransaction(): void
    {
        self::assertTrue($this->connection->isTransactionActive());

        $this->connection->transactional(static fn(): null => null);

        self::assertTrue(
            $this->connection->isTransactionActive(),
            'Le commit imbrique a ferme la transaction du test : verifier use_savepoints.',
        );
    }

    /**
     * KernelBrowser reconstruit le conteneur avant chaque requete par defaut.
     * La requete HTTP obtiendrait alors une autre Connection que celle sur
     * laquelle le test a ouvert sa transaction : ses ecritures seraient
     * commitees pour de bon et fuiteraient dans les tests suivants.
     * `disableReboot()` dans setUp() est ce qui l'empeche.
     */
    public function testAnHttpRequestSharesTheTestConnection(): void
    {
        $this->client->request('GET', '/api/ping');

        self::assertSame(
            $this->connection,
            static::getContainer()->get(Connection::class),
            'La requete HTTP n\'utilise pas la connexion du test : verifier disableReboot().',
        );
    }

    public function testWritesArePossibleInsideATest(): void
    {
        $this->connection->executeStatement(
            'INSERT INTO users (id, username, display_name, email, provider, created_at)
             VALUES (:id, :username, :display_name, :email, :provider, NOW())',
            [
                'id' => '01J9ZQ7X8K3M4N5P6Q7R8S9TAB',
                'username' => 'temoin-isolation',
                'display_name' => 'Temoin',
                'email' => 'temoin@example.test',
                'provider' => 'local',
            ],
        );

        $count = $this->connection->fetchOne(
            'SELECT count(*) FROM users WHERE username = :username',
            ['username' => 'temoin-isolation'],
        );

        // `fetchOne` rend `mixed` : on restreint avant de convertir.
        self::assertIsNumeric($count);
        self::assertSame(1, (int) $count);
    }
}
