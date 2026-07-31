<?php

declare(strict_types=1);

namespace App\Shared\Application\Bus;

use App\Shared\Domain\Event\DomainEventInterface;

/**
 * Pendant de `CommandDispatcherInterface`, pour l'autre sortie d'un abonne :
 * traduire un fait recu en un fait de SON contexte, sans qu'Application
 * connaisse Messenger.
 *
 * A ne pas confondre avec `DomainEventCollectorInterface`, qui accumule les
 * evenements d'un agregat pour que `TransactionalMiddleware` les publie APRES
 * le commit. Ce port-ci s'utilise depuis un abonne, donc deja apres le commit :
 * il n'y a plus de transaction dont il faudrait attendre l'issue, la
 * publication est immediate.
 */
interface DomainEventDispatcherInterface
{
    public function dispatch(DomainEventInterface $event): void;
}
