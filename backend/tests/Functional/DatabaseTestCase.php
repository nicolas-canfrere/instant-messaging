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

        // Sans cela, KernelBrowser reconstruit le conteneur avant CHAQUE requete :
        // la requete HTTP obtiendrait une autre Connection que celle sur laquelle
        // le test ouvre sa transaction, et ses ecritures seraient commitees pour
        // de bon. Les donnees d'un test fuiteraient alors dans le suivant.
        $this->client->disableReboot();

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

    /** Identifiant d'un utilisateur des fixtures, lu via l'annuaire. Necessite une session. */
    protected function userId(string $username): string
    {
        $this->client->request('GET', '/api/users');

        /** @var list<array{id: string, username: string}> $users */
        $users = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        foreach ($users as $user) {
            if ($username === $user['username']) {
                return $user['id'];
            }
        }

        self::fail(sprintf('Utilisateur %s absent de l\'annuaire.', $username));
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
