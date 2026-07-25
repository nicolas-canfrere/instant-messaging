<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Bus;

use App\Shared\Domain\Event\DomainEventInterface;
use App\Shared\Infrastructure\Bus\TransactionalMiddleware;
use App\Shared\Infrastructure\Event\InMemoryDomainEventCollector;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

final class TransactionalMiddlewareTest extends TestCase
{
    public function testEventsAreDispatchedAfterTheTransactionCommits(): void
    {
        $order = [];
        $event = new class implements DomainEventInterface {};
        $collector = new InMemoryDomainEventCollector();

        $connection = $this->createStub(Connection::class);
        $connection->method('transactional')->willReturnCallback(
            static function (callable $callback) use (&$order): mixed {
                $result = $callback();
                $order[] = 'commit';

                return $result;
            },
        );

        $eventBus = $this->createMock(MessageBusInterface::class);
        $eventBus->expects(self::once())->method('dispatch')->willReturnCallback(
            static function (object $message) use (&$order): Envelope {
                $order[] = 'dispatch';

                return new Envelope($message);
            },
        );

        $middleware = new TransactionalMiddleware($connection, $collector, $eventBus, new NullLogger());

        $middleware->handle(
            new Envelope(new \stdClass()),
            $this->stackThatRuns(static function () use ($collector, $event, &$order): void {
                $collector->collect($event);
                $order[] = 'handler';
            }),
        );

        self::assertSame(['handler', 'commit', 'dispatch'], $order);
    }

    public function testNoEventIsDispatchedWhenTheTransactionFails(): void
    {
        $collector = new InMemoryDomainEventCollector();
        $collector->collect(new class implements DomainEventInterface {});

        $connection = $this->createStub(Connection::class);
        $connection->method('transactional')->willThrowException(new \RuntimeException('rollback'));

        $eventBus = $this->createMock(MessageBusInterface::class);
        $eventBus->expects(self::never())->method('dispatch');

        $middleware = new TransactionalMiddleware($connection, $collector, $eventBus, new NullLogger());

        try {
            $middleware->handle(new Envelope(new \stdClass()), $this->stackThatRuns(static fn() => null));
            self::fail('L\'exception aurait du etre relancee.');
        } catch (\RuntimeException) {
            // attendu
        }

        self::assertSame([], $collector->release(), 'Le collecteur doit etre vide apres un echec.');
    }

    private function stackThatRuns(callable $body): StackInterface
    {
        $middleware = new class($body) implements MiddlewareInterface {
            /** @param callable(): mixed $body */
            public function __construct(private $body)
            {
            }

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                ($this->body)();

                return $envelope;
            }
        };

        $stack = $this->createStub(StackInterface::class);
        $stack->method('next')->willReturn($middleware);

        return $stack;
    }
}
