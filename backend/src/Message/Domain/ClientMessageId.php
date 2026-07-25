<?php

declare(strict_types=1);

namespace App\Message\Domain;

use App\Shared\Domain\Identifier\AbstractUlidIdentifier;

/**
 * Cle d'idempotence generee par le CLIENT avant le premier envoi.
 *
 * Le VO reste dans le contexte Message. Seule sa valeur `string` franchit la
 * frontiere, portee par MessageWasSent, parce que Realtime doit la remettre
 * dans la charge utile `message.created`.
 */
final class ClientMessageId extends AbstractUlidIdentifier
{
}
