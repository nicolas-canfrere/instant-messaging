<?php

declare(strict_types=1);

namespace App\Shared\Application\Bus;

/**
 * Marqueur, taggue automatiquement sur `command.bus` via `_instanceof` dans
 * services.yaml. C'est ce qui remplace l'attribut #[AsMessageHandler] : un use
 * case n'a pas a connaitre le composant Messenger.
 */
interface CommandHandlerInterface
{
}
