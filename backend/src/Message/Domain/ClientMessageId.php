<?php

declare(strict_types=1);

namespace App\Message\Domain;

use App\Shared\Domain\Identifier\AbstractUlidIdentifier;

/**
 * Cle d'idempotence generee par le CLIENT avant le premier envoi.
 *
 * Elle reste dans le contexte Message : aucun autre contexte n'en a besoin.
 */
final class ClientMessageId extends AbstractUlidIdentifier
{
}
