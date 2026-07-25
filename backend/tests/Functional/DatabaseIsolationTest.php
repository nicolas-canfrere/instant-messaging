<?php

declare(strict_types=1);

namespace App\Tests\Functional;

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
