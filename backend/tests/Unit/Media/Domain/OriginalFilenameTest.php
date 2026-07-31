<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Domain;

use App\Media\Domain\OriginalFilename;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(OriginalFilename::class)]
final class OriginalFilenameTest extends TestCase
{
    public function testItKeepsTheNameVerbatim(): void
    {
        self::assertSame('rapport ete.pdf', OriginalFilename::fromString('rapport ete.pdf')->toString());
    }

    public function testItAcceptsNonAsciiCharacters(): void
    {
        self::assertSame('rapport été.pdf', OriginalFilename::fromString('rapport été.pdf')->toString());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidNames(): iterable
    {
        // Le cas qui justifie ce VO : un nom qui finit dans un en-tete HTTP.
        yield 'injection d\'en-tete' => ["facture\r\nX-Injection: oui.pdf"];
        yield 'saut de ligne seul' => ["deux\nlignes.txt"];
        yield 'octet NUL' => ["truque\x00.pdf"];
        yield 'caractere de suppression' => ["truque\x7F.pdf"];
        yield 'chaine vide' => [''];
        yield 'espaces seuls' => ['   '];
        yield 'trop long' => [str_repeat('a', 256)];
        yield 'utf-8 invalide' => ["\xC3\x28.pdf"];
    }

    #[DataProvider('invalidNames')]
    public function testItRefusesWhatCannotGoInAHeader(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);

        OriginalFilename::fromString($name);
    }

    public function testItAcceptsExactlyTheMaximumLength(): void
    {
        self::assertSame(255, mb_strlen(OriginalFilename::fromString(str_repeat('a', 255))->toString()));
    }
}
