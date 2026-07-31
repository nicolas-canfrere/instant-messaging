<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Domain;

use App\Media\Domain\MediaFamily;
use App\Media\Domain\MediaMimeType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MediaMimeType::class)]
final class MediaMimeTypeTest extends TestCase
{
    public function testTheAllowlistIsFourImagesAndFourDocuments(): void
    {
        self::assertSame(
            ['image/jpeg', 'image/png', 'image/webp', 'image/gif',
                'text/plain', 'text/csv', 'text/markdown', 'application/pdf'],
            MediaMimeType::values(),
        );
    }

    public function testZipIsNotAcceptable(): void
    {
        // Ecarte explicitement (spec §9) : application/zip n'identifie pas un
        // fichier zip, c'est le conteneur de .docx, .jar et .apk.
        /** @var list<string> $outsideTheAllowlist */
        $outsideTheAllowlist = ['application/zip', 'image/svg+xml', 'text/html'];

        foreach ($outsideTheAllowlist as $mimeType) {
            self::assertNull(MediaMimeType::tryFrom($mimeType));
        }
    }

    public function testEachTypeKnowsItsExtension(): void
    {
        self::assertSame('jpg', MediaMimeType::Jpeg->extension());
        self::assertSame('png', MediaMimeType::Png->extension());
        self::assertSame('webp', MediaMimeType::Webp->extension());
        self::assertSame('gif', MediaMimeType::Gif->extension());
        self::assertSame('txt', MediaMimeType::Text->extension());
        self::assertSame('csv', MediaMimeType::Csv->extension());
        self::assertSame('md', MediaMimeType::Markdown->extension());
        self::assertSame('pdf', MediaMimeType::Pdf->extension());
    }

    public function testEachTypeKnowsItsFamily(): void
    {
        self::assertSame(MediaFamily::Image, MediaMimeType::Jpeg->family());
        self::assertSame(MediaFamily::Image, MediaMimeType::Gif->family());
        self::assertSame(MediaFamily::Document, MediaMimeType::Text->family());
        self::assertSame(MediaFamily::Document, MediaMimeType::Csv->family());
        self::assertSame(MediaFamily::Document, MediaMimeType::Markdown->family());
        self::assertSame(MediaFamily::Document, MediaMimeType::Pdf->family());
    }

    public function testTextCoversItsThreeIndistinguishableSubtypes(): void
    {
        // RFC 4180 decrit une grammaire sans en-tete, RFC 7763 §1.1 pose que la
        // source Markdown reste du texte lisible. Aucun nombre magique n'existe,
        // et il n'en existera pas : c'est la SEULE exception a l'egalite.
        self::assertTrue(MediaMimeType::Text->covers(MediaMimeType::Text));
        self::assertTrue(MediaMimeType::Text->covers(MediaMimeType::Csv));
        self::assertTrue(MediaMimeType::Text->covers(MediaMimeType::Markdown));
    }

    public function testCoveringIsNotSymmetric(): void
    {
        self::assertFalse(MediaMimeType::Csv->covers(MediaMimeType::Text));
        self::assertFalse(MediaMimeType::Markdown->covers(MediaMimeType::Csv));
    }

    public function testEveryOtherPairIsPlainEquality(): void
    {
        foreach (MediaMimeType::cases() as $measured) {
            foreach (MediaMimeType::cases() as $declared) {
                $exception = MediaMimeType::Text === $measured
                    && \in_array($declared, [MediaMimeType::Text, MediaMimeType::Csv, MediaMimeType::Markdown], true);

                self::assertSame(
                    $measured === $declared || $exception,
                    $measured->covers($declared),
                    sprintf('%s couvre %s ?', $measured->value, $declared->value),
                );
            }
        }
    }
}
