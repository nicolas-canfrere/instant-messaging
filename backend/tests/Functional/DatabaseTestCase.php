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

    /** Ouvre une session pour un utilisateur des fixtures. Mot de passe commun : `password`. */
    protected function login(string $username): void
    {
        $this->client->request(
            'POST',
            '/api/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['username' => $username, 'password' => 'password'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
    }

    /** @return array<string, mixed> le corps de la reponse courante, decode */
    protected function json(): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        return $decoded;
    }
}
