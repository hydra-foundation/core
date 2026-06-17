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
