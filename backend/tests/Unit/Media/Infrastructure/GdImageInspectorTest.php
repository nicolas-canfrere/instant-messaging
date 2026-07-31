<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Infrastructure;

use App\Media\Domain\MediaMimeType;
use App\Media\Domain\MediaRejectionReason;
use App\Media\Infrastructure\Image\GdImageInspector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GdImageInspector::class)]
final class GdImageInspectorTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../../../Fixtures/media/';

    /** @var list<string> chemins temporaires a nettoyer, meme si une assertion echoue. */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }

        $this->temporaryFiles = [];
    }

    public function testAValidJpegIsMeasured(): void
    {
        $inspected = (new GdImageInspector())->inspect(self::FIXTURES . 'valide.jpg');

        self::assertNotInstanceOf(MediaRejectionReason::class, $inspected);
        self::assertSame(MediaMimeType::Jpeg, $inspected->mimeType);
        self::assertSame(1600, $inspected->width);
        self::assertSame(900, $inspected->height);
        self::assertGreaterThan(0, $inspected->byteSize);
    }

    public function testAPhpFileRenamedJpgIsRefusedAsUnsupportedType(): void
    {
        // LE test de la tranche. Le nom et le Content-Type declare disent
        // « image/jpeg » ; les octets disent « text/x-php ». Seuls les octets
        // font foi. Le motif precis compte : un type refuse n'est pas un
        // fichier corrompu.
        self::assertSame(
            MediaRejectionReason::UnsupportedType,
            (new GdImageInspector())->inspect(self::FIXTURES . 'piege.jpg'),
        );
    }

    public function testATruncatedFileIsRefusedAsUndecodableRatherThanUnsupported(): void
    {
        // `tronque.gif` porte une VRAIE signature PNG (les 20 premiers octets
        // d'un PNG valide), tronquee. `finfo` la reconnait donc comme un type
        // accepte : seul le decodage echoue. Confondre ce cas avec
        // UnsupportedType enverrait l'operateur corriger le mauvais probleme
        // — c'est exactement la distinction que ce fichier existe pour
        // verifier.
        self::assertSame(
            MediaRejectionReason::Undecodable,
            (new GdImageInspector())->inspect(self::FIXTURES . 'tronque.gif'),
        );
    }

    public function testTheThumbnailFitsInsideTheMaximumSide(): void
    {
        $target = $this->temporaryFile();

        (new GdImageInspector())->thumbnail(self::FIXTURES . 'valide.jpg', $target);

        $size = getimagesize($target);
        self::assertIsArray($size);
        // 1600x900 tient dans un carre de 400 : le cote long devient 400, et le
        // ratio est preserve — sans quoi l'apercu deforme l'image.
        self::assertSame(400, $size[0]);
        self::assertSame(225, $size[1]);
    }

    public function testTheThumbnailIsAlwaysJpegEvenFromAPngOriginal(): void
    {
        // C'est la raison d'etre de StorageKey::forThumbnail() : le worker
        // produit la miniature, donc il choisit son format — quel que soit
        // celui de l'original.
        $target = $this->temporaryFile();

        (new GdImageInspector())->thumbnail(self::FIXTURES . 'valide.png', $target);

        $size = getimagesize($target);
        self::assertIsArray($size);
        self::assertSame(IMAGETYPE_JPEG, $size[2]);
        self::assertSame(400, $size[0]);
        self::assertSame(225, $size[1]);
    }

    /** Chemin unique par appel : un echec ne doit ni polluer un run parallele, ni laisser de trace si l'assertion suivante echoue. */
    private function temporaryFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'thumb-test-');

        if (false === $path) {
            self::fail('Impossible de creer un fichier temporaire pour le test.');
        }

        $this->temporaryFiles[] = $path;

        return $path;
    }
}
