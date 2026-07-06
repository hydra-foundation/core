<?php

declare(strict_types=1);

namespace Hydra\Core\Tests\Unit;

use Hydra\Core\Environment;
use PHPUnit\Framework\TestCase;

final class EnvironmentTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/hydra-env-' . uniqid('', true);
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        $envFile = $this->dir . '/.env';
        if (file_exists($envFile)) {
            unlink($envFile);
        }
        rmdir($this->dir);
    }

    private function writeEnv(string $contents): Environment
    {
        file_put_contents($this->dir . '/.env', $contents);
        return new Environment($this->dir);
    }

    public function testReadsPlainValue(): void
    {
        $env = $this->writeEnv("APP_NAME=hydra\n");
        $this->assertSame('hydra', $env->get('APP_NAME'));
    }

    public function testFalsyZeroIsNotTreatedAsMissing(): void
    {
        // Regression: the old `?:` chain returned the default for "0".
        $env = $this->writeEnv("DEBUG=0\n");
        $this->assertSame('0', $env->get('DEBUG', 'DEFAULT'));
    }

    public function testStripsSurroundingQuotes(): void
    {
        $env = $this->writeEnv("NAME=\"hydra framework\"\nALT='single'\n");
        $this->assertSame('hydra framework', $env->get('NAME'));
        $this->assertSame('single', $env->get('ALT'));
    }

    public function testStripsInlineCommentFromUnquotedValue(): void
    {
        // Regression: the shipped .env.example uses inline comments; keeping
        // them in the value silently corrupted APP_DEBUG, APP_KEY and DB_HOST.
        $env = $this->writeEnv("APP_DEBUG=true  # Set to false in production\n");
        $this->assertSame('true', $env->get('APP_DEBUG'));
        $this->assertTrue($env->bool('APP_DEBUG'));
    }

    public function testStripsInlineCommentWithSingleSpace(): void
    {
        $env = $this->writeEnv("DB_HOST=localhost # or 127.0.0.1\n");
        $this->assertSame('localhost', $env->get('DB_HOST'));
    }

    public function testHashInsideQuotedValueIsPreserved(): void
    {
        // Inside quotes, # is data — not the start of a comment.
        $env = $this->writeEnv("SECRET=\"abc#123 # not a comment\"\nALT='x # y'\n");
        $this->assertSame('abc#123 # not a comment', $env->get('SECRET'));
        $this->assertSame('x # y', $env->get('ALT'));
    }

    public function testUrlWithFragmentInsideQuotesSurvives(): void
    {
        $env = $this->writeEnv("DOCS_URL=\"https://example.com/page#section\"\n");
        $this->assertSame('https://example.com/page#section', $env->get('DOCS_URL'));
    }

    public function testMismatchedQuotesAreNotStripped(): void
    {
        // Regression: trim($value, "\"'") stripped mismatched quotes from
        // either end ("foo' became foo), mangling values that legitimately
        // begin or end with a quote character.
        $env = $this->writeEnv("MIXED=\"foo'\nMIXED2='bar\"\nOPEN=\"unterminated\nTRAIL=trailing'\n");
        $this->assertSame("\"foo'", $env->get('MIXED'));
        $this->assertSame("'bar\"", $env->get('MIXED2'));
        $this->assertSame('"unterminated', $env->get('OPEN'));
        $this->assertSame("trailing'", $env->get('TRAIL'));
    }

    public function testValueThatIsOnlyACommentBecomesEmptyString(): void
    {
        $env = $this->writeEnv("APP_KEY= # generate me\nBARE=#no space before hash\n");
        $this->assertSame('', $env->get('APP_KEY'));
        $this->assertSame('', $env->get('BARE'));
        $this->assertTrue($env->has('APP_KEY'), 'key is set; its value is just empty');
    }

    public function testSkipsCommentsBlankAndMalformedLinesWithoutWarning(): void
    {
        // failOnWarning="true" in phpunit.xml makes a PHP warning fail this test,
        // so this asserts the malformed line ("GARBAGE") is skipped cleanly.
        $env = $this->writeEnv("# a comment\n\nGARBAGE\nAPP_ENV=production\n");
        $this->assertSame('production', $env->get('APP_ENV'));
        $this->assertFalse($env->has('GARBAGE'));
    }

    public function testReturnsDefaultForMissingKey(): void
    {
        $env = $this->writeEnv("APP_NAME=hydra\n");
        $this->assertSame('fallback', $env->get('NOPE', 'fallback'));
        $this->assertNull($env->get('NOPE'));
    }

    public function testHasReflectsPresence(): void
    {
        $env = $this->writeEnv("PRESENT=1\n");
        $this->assertTrue($env->has('PRESENT'));
        $this->assertFalse($env->has('ABSENT'));
    }

    public function testBoolCoercion(): void
    {
        $env = $this->writeEnv("A=true\nB=1\nC=yes\nD=on\nE=false\nF=0\nG=anything\n");
        $this->assertTrue($env->bool('A'));
        $this->assertTrue($env->bool('B'));
        $this->assertTrue($env->bool('C'));
        $this->assertTrue($env->bool('D'));
        $this->assertFalse($env->bool('E'));
        $this->assertFalse($env->bool('F'));
        $this->assertFalse($env->bool('G'));
        $this->assertTrue($env->bool('MISSING', true));
    }

    public function testIntCoercion(): void
    {
        $env = $this->writeEnv("PORT=8080\nZERO=0\nNEG=-5\n");
        $this->assertSame(8080, $env->int('PORT'));
        $this->assertSame(0, $env->int('ZERO'), 'a literal 0 is a valid int, not "missing"');
        $this->assertSame(-5, $env->int('NEG'));
        $this->assertSame(42, $env->int('MISSING', 42));
    }

    public function testIntRejectsNonNumericValue(): void
    {
        // A present-but-non-integer value is a config error, not a silent 0.
        $env = $this->writeEnv("PORT=abc\n");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Environment value for "PORT" must be an integer');

        $env->int('PORT');
    }

    public function testMissingEnvFileDoesNotError(): void
    {
        // No .env written — load() must no-op silently.
        $env = new Environment($this->dir);
        $this->assertSame('d', $env->get('ANYTHING', 'd'));
    }
}
