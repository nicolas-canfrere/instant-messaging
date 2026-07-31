<?php

declare(strict_types=1);

namespace App\Tests\Support\QueryRecorder;

/**
 * Le carnet ou `QueryRecorderMiddleware` note le SQL qui passe. Un test l'efface
 * juste avant le geste qu'il observe, puis compte.
 *
 * Il existe parce que le profileur de doctrine-bundle ne convient PAS ici :
 * `doctrine.debug_data_holder` porte le tag `kernel.reset`, donc le
 * `ServicesResetter` le vide apres CHAQUE requete HTTP — y compris avec
 * `disableReboot()`. Un test qui assertionne apres la reponse ne trouverait
 * plus rien. Meme raison que `MediaCommandSpy` (cf. services_test.yaml).
 */
final class RecordedQueries
{
    /** @var list<string> */
    private array $statements = [];

    public function record(string $sql): void
    {
        $this->statements[] = $sql;
    }

    public function clear(): void
    {
        $this->statements = [];
    }

    /** Nombre de requetes dont le SQL contient ce fragment, depuis le dernier `clear()`. */
    public function countMatching(string $fragment): int
    {
        return count(array_filter(
            $this->statements,
            static fn(string $sql): bool => str_contains($sql, $fragment),
        ));
    }
}
