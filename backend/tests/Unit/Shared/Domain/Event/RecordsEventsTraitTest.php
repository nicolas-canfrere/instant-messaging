<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Event;

use App\Shared\Domain\Event\DomainEventInterface;
use App\Shared\Domain\Event\RecordsEventsTrait;
use PHPUnit\Framework\TestCase;

final class RecordsEventsTraitTest extends TestCase
{
    public function testRecordedEventsAreReturnedInOrder(): void
    {
        $aggregate = new AggregateUsingTheTrait();
        $first = new \stdClass();
        $second = new \stdClass();

        $aggregate->doSomething($first);
        $aggregate->doSomething($second);

        $events = $aggregate->releaseEvents();

        self::assertCount(2, $events);
        // Le trait ne promet qu'une liste de DomainEventInterface : on restreint
        // avant de lire la charge utile, sinon PHPStan a raison de rouspeter.
        self::assertInstanceOf(SomethingHappened::class, $events[0]);
        self::assertInstanceOf(SomethingHappened::class, $events[1]);
        self::assertSame($first, $events[0]->payload);
        self::assertSame($second, $events[1]->payload);
    }

    /**
     * La liberation vide l'agregat : c'est ce qui empeche un meme evenement
     * d'etre publie deux fois si l'agregat est reutilise dans la meme requete.
     */
    public function testReleasingTwiceReturnsNothingTheSecondTime(): void
    {
        $aggregate = new AggregateUsingTheTrait();
        $aggregate->doSomething(new \stdClass());

        self::assertCount(1, $aggregate->releaseEvents());
        self::assertSame([], $aggregate->releaseEvents());
    }

    public function testAnAggregateThatDidNothingReleasesNothing(): void
    {
        self::assertSame([], (new AggregateUsingTheTrait())->releaseEvents());
    }
}

final class SomethingHappened implements DomainEventInterface
{
    public function __construct(public readonly \stdClass $payload)
    {
    }
}

final class AggregateUsingTheTrait
{
    use RecordsEventsTrait;

    public function doSomething(\stdClass $payload): void
    {
        $this->recordEvent(new SomethingHappened($payload));
    }
}
