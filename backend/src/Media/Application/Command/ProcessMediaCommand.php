<?php

declare(strict_types=1);

namespace App\Media\Application\Command;

use App\Shared\Application\Bus\CommandInterface;

/**
 * Coquille vide, deliberement. `framework.messenger.routing` est valide a la
 * compilation du conteneur : la classe nommee dans messenger.yaml doit exister
 * avant que quoi que ce soit ne la dispatche, sinon bin/console casse au
 * premier appel. Le constructeur et le handler arrivent avec le worker.
 */
final readonly class ProcessMediaCommand implements CommandInterface
{
}
