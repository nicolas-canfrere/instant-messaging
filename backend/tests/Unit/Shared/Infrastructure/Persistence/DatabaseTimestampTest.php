<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Persistence;

use App\Shared\Infrastructure\Persistence\DatabaseTimestamp;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DatabaseTimestamp::class)]
final class DatabaseTimestampTest extends TestCase
{
    public function testItConvertsThePostgresRenderingToAtom(): void
    {
        self::assertSame(
            '2026-07-25T14:25:49+00:00',
            DatabaseTimestamp::toAtom('2026-07-25 14:25:49+00'),
        );
    }

    /**
     * Le decalage rendu par PostgreSQL est celui du fuseau de SESSION, pas
     * celui du stockage. Une base dont `timezone` n'est pas UTC ferait sortir
     * le meme instant en « +02:00 » ici et en « +00:00 » dans la charge utile
     * Mercure, que le front compare par chaine. La normalisation rend la
     * garantie structurelle plutot qu'environnementale.
     */
    public function testItNormalizesTheOffsetToUtc(): void
    {
        self::assertSame(
            '2026-07-25T14:25:49+00:00',
            DatabaseTimestamp::toAtom('2026-07-25 16:25:49+02'),
        );
    }
}
