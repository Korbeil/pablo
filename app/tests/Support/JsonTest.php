<?php

declare(strict_types=1);

namespace Pablo\Tests\Support;

use Pablo\Support\Json;
use Pablo\Support\PabloError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JsonTest extends TestCase
{
    public function testDecodeArrayReturnsObjectsAndArrays(): void
    {
        $this->assertSame(['a' => 1, 'b' => ['c' => true]], Json::decodeArray('{"a":1,"b":{"c":true}}', 'test'));
        $this->assertSame([1, 2], Json::decodeArray('[1,2]', 'test'));
        $this->assertSame([], Json::decodeArray('{}', 'test'));
    }

    public function testDecodeArrayNamesWhatAndQuotesTheReply(): void
    {
        try {
            Json::decodeArray('<html>502 Bad Gateway</html>', 'gh pr view output');
            $this->fail('expected a PabloError');
        } catch (PabloError $e) {
            $this->assertStringStartsWith('cannot parse gh pr view output: Syntax error, got: ', $e->getMessage());
            $this->assertStringContainsString("'<html>502 Bad Gateway</html>'", $e->getMessage());
            $this->assertInstanceOf(\JsonException::class, $e->getPrevious());
        }
    }

    public function testDecodeArrayOnEmptyOutputSaysSo(): void
    {
        $this->expectException(PabloError::class);
        $this->expectExceptionMessage('cannot parse acli output: Syntax error, got: (empty output)');
        Json::decodeArray("  \n", 'acli output');
    }

    public function testDecodeArrayTruncatesTheExcerptTo200Chars(): void
    {
        try {
            Json::decodeArray(str_repeat('x', 500), 'junk');
            $this->fail('expected a PabloError');
        } catch (PabloError $e) {
            $this->assertStringContainsString("'".str_repeat('x', 200)."'", $e->getMessage());
            $this->assertStringNotContainsString(str_repeat('x', 201), $e->getMessage());
        }
    }

    #[DataProvider('scalars')]
    public function testDecodeArrayRefusesScalars(string $json): void
    {
        $this->expectException(PabloError::class);
        $this->expectExceptionMessage('cannot parse thing: expected a JSON object or array');
        Json::decodeArray($json, 'thing');
    }

    /** @return iterable<string, array{string}> */
    public static function scalars(): iterable
    {
        yield 'null' => ['null'];
        yield 'number' => ['42'];
        yield 'string' => ['"ok"'];
        yield 'bool' => ['true'];
    }

    public function testDecodeListAcceptsListsOnly(): void
    {
        $this->assertSame([['n' => 1]], Json::decodeList('[{"n":1}]', 'gh pr list output'));
        $this->assertSame([], Json::decodeList('[]', 'gh pr list output'));

        $this->expectException(PabloError::class);
        $this->expectExceptionMessage('cannot parse gh pr list output: expected a JSON array');
        Json::decodeList('{"message":"Not Found"}', 'gh pr list output');
    }

    public function testTryDecodeArrayToleratesJunk(): void
    {
        $this->assertSame(['pid' => 12], Json::tryDecodeArray('{"pid":12}'));
        $this->assertSame([1], Json::tryDecodeArray('[1]'));
        $this->assertNull(Json::tryDecodeArray(''));
        $this->assertNull(Json::tryDecodeArray('{"truncated":'));
        $this->assertNull(Json::tryDecodeArray('null'));
        $this->assertNull(Json::tryDecodeArray('42'));
    }
}
