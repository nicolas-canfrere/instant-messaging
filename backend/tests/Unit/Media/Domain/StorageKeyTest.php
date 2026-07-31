<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Domain;

use App\Media\Domain\MediaMimeType;
use App\Media\Domain\StorageKey;
use App\Shared\Domain\Identifier\MediaId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StorageKey::class)]
final class StorageKeyTest extends TestCase
{
    private const string ULID = '01JQZ0000000000000000000AB';

    public function testOriginalKeyCarriesThePrefixAndTheExtension(): void
    {
        $key = StorageKey::forOriginal(MediaId::fromString(self::ULID), MediaMimeType::Jpeg);

        self::assertSame('media/01JQZ0000000000000000000AB.jpg', $key->toString());
    }

    public function testThumbnailKeyIsDerivedFromTheSameIdentifier(): void
    {
        $key = StorageKey::forThumbnail(MediaId::fromString(self::ULID));

        // La miniature est toujours du JPEG, quel que soit l'original : c'est
        // le worker qui la produit, il choisit donc son format.
        self::assertSame('media/01JQZ0000000000000000000AB-thumb.jpg', $key->toString());
    }

    public function testAKeyOutsideThePrefixIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // Une cle relue depuis la base doit rester dans le prefixe : sans cette
        // garde, une ligne corrompue ferait signer un acces a n'importe quel
        // objet du bucket.
        StorageKey::fromString('../../etc/passwd');
    }
}
