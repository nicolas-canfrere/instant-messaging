<?php

declare(strict_types=1);

namespace App\Tests\Unit\Message\Domain;

use App\Message\Domain\EmptyMessageContentException;
use App\Message\Domain\MessageContent;
use App\Message\Domain\MessageContentTooLongException;
use PHPUnit\Framework\TestCase;

final class MessageContentTest extends TestCase
{
    public function testItKeepsTheTrimmedText(): void
    {
        self::assertSame('bonjour', MessageContent::fromString('  bonjour  ')->toString());
    }

    public function testItRejectsAnEmptyString(): void
    {
        $this->expectException(EmptyMessageContentException::class);

        MessageContent::fromString('');
    }

    public function testItRejectsWhitespaceOnly(): void
    {
        $this->expectException(EmptyMessageContentException::class);

        MessageContent::fromString("   \n\t  ");
    }

    public function testItAcceptsExactlyTheMaximumLength(): void
    {
        $text = str_repeat('a', MessageContent::MAX_LENGTH);

        self::assertSame($text, MessageContent::fromString($text)->toString());
    }

    public function testItRejectsOneCharacterTooMany(): void
    {
        $this->expectException(MessageContentTooLongException::class);

        MessageContent::fromString(str_repeat('a', MessageContent::MAX_LENGTH + 1));
    }

    /** `strlen` compterait « é » pour deux octets et rejetterait un message valide. */
    public function testLengthIsCountedInCharactersNotBytes(): void
    {
        $text = str_repeat('é', MessageContent::MAX_LENGTH);

        self::assertSame(MessageContent::MAX_LENGTH, mb_strlen(MessageContent::fromString($text)->toString()));
    }
}
