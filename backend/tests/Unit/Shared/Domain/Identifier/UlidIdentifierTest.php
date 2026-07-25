<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Identifier;

use App\Shared\Domain\Identifier\AbstractUlidIdentifier;
use App\Shared\Domain\Identifier\InvalidIdentifierException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UlidIdentifierTest extends TestCase
{
    private const string VALID = '01J9ZQ7X8K3M4N5P6Q7R8S9TAB';

    public function testAcceptsAValidUlid(): void
    {
        self::assertSame(self::VALID, TestIdentifier::fromString(self::VALID)->toString());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidValues(): iterable
    {
        yield 'trop court' => ['01J9ZQ7X8K3M4N5P6Q7R8S9TA'];
        yield 'trop long' => ['01J9ZQ7X8K3M4N5P6Q7R8S9TABC'];
        yield 'lettre I exclue' => ['01J9ZQ7X8K3M4N5P6Q7R8S9TAI'];
        yield 'lettre L exclue' => ['01J9ZQ7X8K3M4N5P6Q7R8S9TAL'];
        yield 'lettre O exclue' => ['01J9ZQ7X8K3M4N5P6Q7R8S9TAO'];
        yield 'lettre U exclue' => ['01J9ZQ7X8K3M4N5P6Q7R8S9TAU'];
        yield 'minuscules' => ['01j9zq7x8k3m4n5p6q7r8s9tab'];
        yield 'premier caractere > 7' => ['81J9ZQ7X8K3M4N5P6Q7R8S9TAB'];
        yield 'vide' => [''];
    }

    #[DataProvider('invalidValues')]
    public function testRejectsAnInvalidUlid(string $value): void
    {
        $this->expectException(InvalidIdentifierException::class);
        TestIdentifier::fromString($value);
    }

    public function testTwoIdentifiersWithTheSameValueAreEqual(): void
    {
        self::assertTrue(
            TestIdentifier::fromString(self::VALID)->equals(TestIdentifier::fromString(self::VALID)),
        );
    }

    public function testIdentifiersOfDifferentTypesAreNeverEqual(): void
    {
        self::assertFalse(
            TestIdentifier::fromString(self::VALID)->equals(OtherIdentifier::fromString(self::VALID)),
        );
    }

    public function testUlidsSortChronologicallyAsStrings(): void
    {
        $older = '01J9ZQ7X8K3M4N5P6Q7R8S9TAB';
        $newer = '01J9ZQ7X8K3M4N5P6Q7R8S9TAC';

        self::assertLessThan(0, strcmp($older, $newer));
    }
}

final class TestIdentifier extends AbstractUlidIdentifier
{
}

final class OtherIdentifier extends AbstractUlidIdentifier
{
}
