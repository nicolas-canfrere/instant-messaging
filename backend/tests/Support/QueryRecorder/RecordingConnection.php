<?php

declare(strict_types=1);

namespace App\Tests\Support\QueryRecorder;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * Note le SQL au moment ou il entre dans le pilote.
 *
 * On observe `prepare()` et pas l'execution du `Statement` qu'il rend : DBAL ne
 * met aucun statement en cache, donc chaque `executeQuery()` en prepare un neuf
 * et les deux comptes coincident. Emballer le statement en plus n'ajouterait
 * qu'une classe pour le meme resultat.
 */
final class RecordingConnection extends AbstractConnectionMiddleware
{
    public function __construct(Connection $connection, private readonly RecordedQueries $recorded)
    {
        parent::__construct($connection);
    }

    public function prepare(string $sql): Statement
    {
        $this->recorded->record($sql);

        return parent::prepare($sql);
    }

    public function query(string $sql): Result
    {
        $this->recorded->record($sql);

        return parent::query($sql);
    }

    public function exec(string $sql): int|string
    {
        $this->recorded->record($sql);

        return parent::exec($sql);
    }
}
