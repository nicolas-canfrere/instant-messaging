<?php

declare(strict_types=1);

use App\Kernel;

require_once dirname(__DIR__) . '/vendor/autoload_runtime.php';

/**
 * Le contexte vient de `$_SERVER`, alimenté par symfony/dotenv : PHPStan `max`
 * le voit donc en `mixed` et refuse de le passer tel quel au noyau. On restreint
 * explicitement plutôt que de caster — un `APP_ENV` non textuel est un défaut de
 * configuration, pas une valeur à convertir en silence.
 *
 * @param array<string, mixed> $context
 */
return static function (array $context): Kernel {
    $environment = $context['APP_ENV'] ?? null;

    if (!is_string($environment)) {
        throw new LogicException('APP_ENV doit etre defini et de type chaine.');
    }

    return new Kernel($environment, (bool) ($context['APP_DEBUG'] ?? false));
};
