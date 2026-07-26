<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Infrastructure;

use App\Media\Domain\MediaMimeType;
use App\Media\Infrastructure\Image\GdImageInspector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GdImageInspector::class)]
final class GdImageInspectorTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../../../Fixtures/media/';

    public function testAValidJpegIsMeasured(): void
    {
        $inspected = (new GdImageInspector())->inspect(self::FIXTURES . 'valide.jpg');

        self::assertNotNull($inspected);
        self::assertSame(MediaMimeType::Jpeg, $inspected->mimeType);
        self::assertSame(1600, $inspected->width);
        self::assertSame(900, $inspected->height);
        self::assertGreaterThan(0, $inspected->byteSize);
    }

    public function testAPhpFileRenamedJpgIsRefused(): void
    {
        // LE test de la tranche. Le nom et le Content-Type declare disent
        // « image/jpeg » ; les octets disent « text/x-php ». Seuls les octets
        // font foi.
        self::assertNull((new GdImageInspector())->inspect(self::FIXTURES . 'piege.jpg'));
    }

    public function testATruncatedFileIsRefused(): void
    {
        self::assertNull((new GdImageInspector())->inspect(self::FIXTURES . 'tronque.gif'));
    }

    public function testTheThumbnailFitsInsideTheMaximumSide(): void
    {
        $target = sys_get_temp_dir() . '/thumb-test.jpg';

        (new GdImageInspector())->thumbnail(self::FIXTURES . 'valide.jpg', $target);

        $size = getimagesize($target);
        self::assertIsArray($size);
        // 1600x900 tient dans un carre de 400 : le cote long devient 400, et le
        // ratio est preserve — sans quoi l'apercu deforme l'image.
        self::assertSame(400, $size[0]);
        self::assertSame(225, $size[1]);

        unlink($target);
    }
}
