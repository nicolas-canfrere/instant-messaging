<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Domain\IdGeneratorInterface;
use App\Shared\Infrastructure\Log\CorrelationIdHolder;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Priorite haute sur `kernel.request` : l'identifiant doit exister avant que
 * quoi que ce soit d'autre ne puisse logguer ou echouer.
 */
#[AsEventListener(event: KernelEvents::REQUEST, method: 'onRequest', priority: 1000)]
#[AsEventListener(event: KernelEvents::RESPONSE, method: 'onResponse')]
final readonly class CorrelationIdListener
{
    public function __construct(
        private CorrelationIdHolder $holder,
        private IdGeneratorInterface $idGenerator,
    ) {
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->holder->set($this->idGenerator->generate());
    }

    public function onResponse(ResponseEvent $event): void
    {
        if ($event->isMainRequest()) {
            $event->getResponse()->headers->set('X-Correlation-Id', $this->holder->get());
        }
    }
}
