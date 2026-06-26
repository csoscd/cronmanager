<?php

declare(strict_types=1);

/**
 * Cronmanager – Unit Tests: HmacValidator
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Tests\Unit\Security;

use Cronmanager\Agent\Security\HmacValidator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HmacValidatorTest extends TestCase
{
    private const SECRET = 'test-shared-secret-32-chars-long!';

    private HmacValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new HmacValidator(self::SECRET);
    }

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    #[Test]
    public function constructorThrowsOnEmptySecret(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('HMAC secret must not be empty');

        new HmacValidator('');
    }

    // -------------------------------------------------------------------------
    // compute()
    // -------------------------------------------------------------------------

    #[Test]
    public function computeReturnsSixtyFourCharHexString(): void
    {
        $hash = $this->validator->compute('GET', '/crons', '');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    #[Test]
    public function computeIsDeterministic(): void
    {
        $a = $this->validator->compute('POST', '/execution/finish', '{"exit_code":0}');
        $b = $this->validator->compute('POST', '/execution/finish', '{"exit_code":0}');

        $this->assertSame($a, $b);
    }

    #[Test]
    public function computeUppercasesMethod(): void
    {
        $lower = $this->validator->compute('post', '/crons', '');
        $upper = $this->validator->compute('POST', '/crons', '');

        $this->assertSame($upper, $lower, 'Method case must not affect the signature');
    }

    // -------------------------------------------------------------------------
    // validate() – happy paths
    // -------------------------------------------------------------------------

    #[Test]
    public function validateReturnsTrueForCorrectSignatureWithoutPrefix(): void
    {
        $sig = $this->validator->compute('POST', '/crons', '{"command":"echo ok"}');

        $this->assertTrue($this->validator->validate('POST', '/crons', '{"command":"echo ok"}', $sig));
    }

    #[Test]
    public function validateReturnsTrueForSignatureWithSha256Prefix(): void
    {
        $sig = 'sha256=' . $this->validator->compute('GET', '/health', '');

        $this->assertTrue($this->validator->validate('GET', '/health', '', $sig));
    }

    #[Test]
    public function validateHandlesEmptyBodyCorrectly(): void
    {
        $sig = $this->validator->compute('GET', '/crons', '');

        $this->assertTrue($this->validator->validate('GET', '/crons', '', $sig));
    }

    #[Test]
    public function validateIsMethodCaseInsensitive(): void
    {
        $sig = $this->validator->compute('DELETE', '/crons/42', '');

        $this->assertTrue($this->validator->validate('delete', '/crons/42', '', $sig));
    }

    // -------------------------------------------------------------------------
    // validate() – rejection paths
    // -------------------------------------------------------------------------

    #[Test]
    public function validateReturnsFalseForEmptySignatureHeader(): void
    {
        $this->assertFalse($this->validator->validate('GET', '/crons', '', ''));
    }

    #[Test]
    public function validateReturnsFalseForPrefixOnlySignature(): void
    {
        $this->assertFalse($this->validator->validate('GET', '/crons', '', 'sha256='));
    }

    #[Test]
    public function validateReturnsFalseForWrongSecret(): void
    {
        $otherValidator = new HmacValidator('different-secret-value');
        $sig = $otherValidator->compute('POST', '/crons', '{}');

        $this->assertFalse($this->validator->validate('POST', '/crons', '{}', $sig));
    }

    #[Test]
    public function validateReturnsFalseWhenBodyTampered(): void
    {
        $sig = $this->validator->compute('POST', '/execution/finish', '{"exit_code":0}');

        $this->assertFalse(
            $this->validator->validate('POST', '/execution/finish', '{"exit_code":1}', $sig)
        );
    }

    #[Test]
    public function validateReturnsFalseWhenPathTampered(): void
    {
        $sig = $this->validator->compute('GET', '/crons', '');

        $this->assertFalse($this->validator->validate('GET', '/crons/42', '', $sig));
    }

    #[Test]
    public function validateReturnsFalseWhenMethodTampered(): void
    {
        $sig = $this->validator->compute('GET', '/crons', '');

        $this->assertFalse($this->validator->validate('POST', '/crons', '', $sig));
    }

    #[Test]
    public function validateReturnsFalseForGarbageSignature(): void
    {
        $this->assertFalse($this->validator->validate('GET', '/crons', '', 'not-a-valid-hmac'));
    }

    // -------------------------------------------------------------------------
    // validate() – different secrets produce different hashes
    // -------------------------------------------------------------------------

    #[Test]
    public function differentSecretsProduceDifferentHashes(): void
    {
        $v1 = new HmacValidator('secret-one');
        $v2 = new HmacValidator('secret-two');

        $h1 = $v1->compute('GET', '/crons', '');
        $h2 = $v2->compute('GET', '/crons', '');

        $this->assertNotSame($h1, $h2);
    }

    // -------------------------------------------------------------------------
    // validate() – user-context fields
    // -------------------------------------------------------------------------

    #[Test]
    public function validateReturnsTrueWhenUserContextMatches(): void
    {
        $sig = $this->validator->compute('POST', '/crons', '{}', 5, 'alice');

        $this->assertTrue($this->validator->validate('POST', '/crons', '{}', $sig, 5, 'alice'));
    }

    #[Test]
    public function validateReturnsFalseWhenUserIdTampered(): void
    {
        $sig = $this->validator->compute('POST', '/crons', '{}', 5, 'alice');

        $this->assertFalse($this->validator->validate('POST', '/crons', '{}', $sig, 99, 'alice'));
    }

    #[Test]
    public function validateReturnsFalseWhenUsernameTampered(): void
    {
        $sig = $this->validator->compute('POST', '/crons', '{}', 5, 'alice');

        $this->assertFalse($this->validator->validate('POST', '/crons', '{}', $sig, 5, 'mallory'));
    }

    #[Test]
    public function defaultUserContextProducesStableSignature(): void
    {
        $sig = $this->validator->compute('GET', '/crons', '');

        // validate() without user params uses same defaults → should match
        $this->assertTrue($this->validator->validate('GET', '/crons', '', $sig));
    }

    // -------------------------------------------------------------------------
    // Data providers
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function pathBodyCombinations(): array
    {
        return [
            'root path, empty body'       => ['GET', '/', ''],
            'nested path, empty body'     => ['GET', '/maintenance/windows/conflict', ''],
            'POST with JSON body'         => ['POST', '/execution/finish', '{"id":1,"exit_code":0,"output":"ok"}'],
            'PUT with JSON body'          => ['PUT', '/crons/42', '{"active":0}'],
            'path with numeric segment'   => ['DELETE', '/crons/99', ''],
        ];
    }

    #[Test]
    #[DataProvider('pathBodyCombinations')]
    public function validateRoundtripForVariousPathsAndBodies(
        string $method,
        string $path,
        string $body
    ): void {
        $sig = $this->validator->compute($method, $path, $body);

        $this->assertTrue(
            $this->validator->validate($method, $path, $body, $sig),
            "Round-trip validation failed for {$method} {$path}"
        );
    }
}
