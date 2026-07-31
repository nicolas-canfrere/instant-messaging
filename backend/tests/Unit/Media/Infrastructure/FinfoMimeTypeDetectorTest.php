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
        yield 'pdf' => ['minimal.pdf', MediaMimeType::Pdf];
        yield 'markdown' => ['notes.md', MediaMimeType::Text];
        yield 'csv' => ['donnees.csv', MediaMimeType::Text];
        // La garde UTF-8 le sauve : finfo le classe text/x-shellscript, mais ses
        // octets sont du texte valide. Sans elle, un .txt banal serait rejete.
        yield 'texte a shebang' => ['script-comme-texte.txt', MediaMimeType::Text];
        // Rejete AVANT d'atteindre la garde NUL : libmagic classe deja ces
        // octets `application/octet-stream`, donc c'est la garde `text/`
        // (ligne « detected ne commence pas par text/ ») qui tranche ici, pas
        // `readAsText()`. La garde NUL est ecrite pour le jour ou libmagic
        // classerait ces memes octets autrement — elle reste sans temoin
        // direct dans cette suite, faute d'un fixture qui l'atteigne
        // reellement (voir rapport tache 4, revue).
        yield 'texte avec octet nul' => ['avec-nul.txt', MediaRejectionReason::UnsupportedType];
        // LE test de securite : du HTML servi en same-origin s'executerait.
        yield 'html deguise en txt' => ['deguise.txt', MediaRejectionReason::UnsupportedType];
        // Un CSV exporte par Excel en cp1252 : finfo le classe text/plain (il
        // ATTEINT bien readAsText()), mais ses octets ne sont pas de l'UTF-8
        // valide. Ordinaire, pas un deguisement — motif distinct de
        // UnsupportedType pour ne pas declencher un warning non actionnable.
        yield 'csv cp1252' => ['cp1252.csv', MediaRejectionReason::UnsupportedEncoding];
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
