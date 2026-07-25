<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Chaque test s'execute dans une transaction annulee : la base repart propre
 * sans re-migrer entre deux tests.
 *
 * Cela ne tient que parce que DBAL 4 imbrique les transactions avec des
 * savepoints, systematiquement — le commit de TransactionalMiddleware pendant
 * un test relache un savepoint et ne ferme pas la transaction du test.
 * `DatabaseIsolationTest` verrouille ce comportement.
 */
abstract class DatabaseTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);
        $this->connection = $connection;
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }
}
