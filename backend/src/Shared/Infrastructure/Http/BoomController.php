<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

/**
 * Leve une exception non rattrapee, pour verifier que le listener d'exception
 * la traduit en Problem Details sans laisser fuir son message.
 *
 * Volontairement sans attribut #[Route] : la route est declaree dans
 * `config/routes/test/`, que Symfony ne charge qu'en environnement de test.
 * Un `condition: "env('APP_ENV') === 'test'"` aurait exige
 * symfony/expression-language, et n'aurait de toute facon pas empeche la route
 * d'exister en production.
 */
final class BoomController
{
    public function __invoke(): never
    {
        throw new \RuntimeException('secret-interne : ne doit jamais sortir');
    }
}
