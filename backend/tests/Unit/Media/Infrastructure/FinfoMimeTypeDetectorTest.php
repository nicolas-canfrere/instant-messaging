<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Infrastructure;

use App\Media\Domain\MediaMimeType;
use App\Media\Domain\MediaRejectionReason;
use App\Media\Infrastructure\File\FinfoMimeTypeDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(FinfoMimeTypeDetector::class)]
final class FinfoMimeTypeDetectorTest extends TestCase
{
    /** @return iterable<string, array{string, MediaMimeType|MediaRejectionReason}> */
    public static function fixtures(): iterable
    {
        yield 'png valide' => ['valide.png', MediaMimeType::Png];
        yield 'gif tronque garde son type' => ['tronque.gif', MediaMimeType::Gif];
        // Un SVG renomme .png : c'est le deguisement que la tranche 4 attrape
        // deja, et qui doit continuer d'etre attrape.
        yield 'svg deguise en png' => ['deguise.png', MediaRejectionReason::UnsupportedType];
    }

    #[DataProvider('fixtures')]
    public function testItReadsWhatTheBytesReallyAre(string $fixture, MediaMimeType|MediaRejectionReason $expected): void
    {
        self::assertSame($expected, (new FinfoMimeTypeDetector())->detect(self::path($fixture)));
    }

    public function testAMissingFileIsUnsupported(): void
    {
        self::assertSame(
            MediaRejectionReason::UnsupportedType,
            (new FinfoMimeTypeDetector())->detect('/tmp/ce-fichier-n-existe-pas'),
        );
    }

    private static function path(string $fixture): string
    {
        return sprintf('%s/../../../Fixtures/Media/%s', __DIR__, $fixture);
    }
}
