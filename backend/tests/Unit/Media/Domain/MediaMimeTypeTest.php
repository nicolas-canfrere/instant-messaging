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
    public function testTheAllowlistIsFourImageTypesAndOneDocumentType(): void
    {
        self::assertSame(
            ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'text/plain'],
            MediaMimeType::values(),
        );
    }

    public function testAnythingOutsideTheAllowlistIsUnknown(): void
    {
        // La valeur passe par une variable typee `string` au sens large : sur
        // un litteral, PHPStan resout `tryFrom()` statiquement contre les cas
        // de l'enum et l'assertion ne verifierait plus rien.
        /** @var list<string> $outsideTheAllowlist */
        $outsideTheAllowlist = ['application/pdf', 'image/svg+xml'];

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
    }

    public function testImageTypesBelongToTheImageFamily(): void
    {
        self::assertSame(MediaFamily::Image, MediaMimeType::Jpeg->family());
        self::assertSame(MediaFamily::Image, MediaMimeType::Png->family());
        self::assertSame(MediaFamily::Image, MediaMimeType::Webp->family());
        self::assertSame(MediaFamily::Image, MediaMimeType::Gif->family());
    }

    public function testTextBelongsToTheDocumentFamily(): void
    {
        self::assertSame(MediaFamily::Document, MediaMimeType::Text->family());
    }
}
