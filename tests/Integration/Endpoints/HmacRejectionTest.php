<?php

declare(strict_types=1);

/**
 * Cronmanager – Integration Tests: HMAC signature validation
 *
 * Tests the HMAC-SHA256 request-signature mechanism used by agent.php to
 * authenticate every incoming request from the web container.
 *
 * Regression focus: v4.4.4 incident
 * -----------------------------------
 * v4.3.0 extended the HMAC message with userId + username.  agent.php used
 * 'system' as the default for a missing X-User-Name header; the cron-wrapper
 * signs with username="" (empty string).  This mismatch silently rejected all
 * 59 cron-wrapper requests for 11 days (HTTP 401, no execution_log rows).
 *
 * Fixed in v4.4.4: agent.php default changed to '' (empty string).
 *
 * Scenario 5 is the regression guard: it explicitly replicates the agent.php
 * header-extraction logic (reading $_SERVER with a '' default for the missing
 * X-User-Name header) and asserts that a cron-wrapper-signed request is accepted.
 *
 * Covered scenarios
 * -----------------
 * 1. Missing X-Agent-Signature → false (no signature at all)
 * 2. Correct, fully-signed request → true (baseline)
 * 3. Body tampered after signing → false (tamper detection)
 * 4. Request path tampered after signing → false (tamper detection)
 * 5. Regression v4.4.4: no X-User-Name header → agent.php extraction gives ''
 *    → cron-wrapper request (userId=0, username='') is accepted, not rejected
 * 6. Wrong shared secret → false
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Tests\Integration\Endpoints;

use Cronmanager\Agent\Security\AuditIdentityExtractor;
use Cronmanager\Agent\Security\HmacValidator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HmacRejectionTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Constants
    // -------------------------------------------------------------------------

    private const TEST_SECRET = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2';

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeValidator(): HmacValidator
    {
        return new HmacValidator(self::TEST_SECRET);
    }

    // =========================================================================
    // 1. Missing X-Agent-Signature → rejected
    // =========================================================================

    #[Test]
    public function missingSigHeaderReturnsFalse(): void
    {
        $v = $this->makeValidator();

        $this->assertFalse(
            $v->validate('POST', '/crons', '{}', '', 0, ''),
            'An empty signature header must always be rejected'
        );
    }

    // =========================================================================
    // 2. Correctly signed request → accepted
    // =========================================================================

    #[Test]
    public function correctlySignedRequestReturnsTrue(): void
    {
        $v      = $this->makeValidator();
        $method = 'POST';
        $path   = '/crons';
        $body   = '{"schedule":"* * * * *"}';
        $sig    = $v->compute($method, $path, $body, 1, 'testuser');

        $this->assertTrue(
            $v->validate($method, $path, $body, $sig, 1, 'testuser'),
            'A correctly signed request must be accepted'
        );
    }

    // =========================================================================
    // 3. Body tampered after signing → rejected
    // =========================================================================

    #[Test]
    public function tamperedBodyReturnsFalse(): void
    {
        $v      = $this->makeValidator();
        $method = 'POST';
        $path   = '/crons';
        $body   = '{"schedule":"* * * * *"}';
        $sig    = $v->compute($method, $path, $body, 0, '');

        // Attacker modifies the body after signing
        $tamperedBody = '{"schedule":"* * * * *","active":0}';

        $this->assertFalse(
            $v->validate($method, $path, $tamperedBody, $sig, 0, ''),
            'A request with a tampered body must be rejected'
        );
    }

    // =========================================================================
    // 4. Path tampered after signing → rejected
    // =========================================================================

    #[Test]
    public function tamperedPathReturnsFalse(): void
    {
        $v      = $this->makeValidator();
        $method = 'GET';
        $path   = '/crons/42';
        $body   = '';
        $sig    = $v->compute($method, $path, $body, 0, '');

        // Attacker changes the target resource
        $tamperedPath = '/crons/1';

        $this->assertFalse(
            $v->validate($method, $tamperedPath, $body, $sig, 0, ''),
            'A request with a tampered path must be rejected'
        );
    }

    // =========================================================================
    // 5. Regression guard (v4.4.4): missing X-User-Name → '' → cron-wrapper accepted
    // =========================================================================

    #[Test]
    public function missingXUserNameHeaderDefaultsToEmptyStringAndCronWrapperRequestIsAccepted(): void
    {
        // The cron-wrapper sends no X-User-Name header (it has no user context)
        // and signs with userId=0, username=''.
        //
        // agent.php delegates header extraction to AuditIdentityExtractor::fromServer().
        // This test calls that SAME method (not a copy) so that any change to the
        // default in AuditIdentityExtractor – e.g. reverting to ?? 'system' (v4.4.4
        // regression) – will immediately break this assertion.

        // Fabricate a $_SERVER-like array with no X-User-Name header present
        $fakeServer = ['HTTP_X_USER_ID' => '0'];
        $identity   = AuditIdentityExtractor::fromServer($fakeServer);

        // Verify the extractor produces the correct defaults
        $this->assertSame(0,  $identity['userId'],   'X-User-Id must default to 0 when absent');
        $this->assertSame('', $identity['username'], 'X-User-Name must default to empty string when absent (v4.4.4 fix)');

        // Verify that a request signed by the cron-wrapper (userId=0, username='')
        // is accepted when agent.php uses the extracted values for HMAC validation
        $v      = $this->makeValidator();
        $method = 'POST';
        $path   = '/execution/start';
        $body   = '{"job_id":1,"target":"local"}';

        // cron-wrapper signs with userId=0, username=''
        $sig = $v->compute($method, $path, $body, 0, '');

        $this->assertTrue(
            $v->validate($method, $path, $body, $sig, $identity['userId'], $identity['username']),
            'Cron-wrapper request (signed with userId=0, username="") must be accepted when X-User-Name header is absent'
        );
    }

    // =========================================================================
    // 6. Wrong shared secret → rejected
    // =========================================================================

    #[Test]
    public function wrongSecretReturnsFalse(): void
    {
        $method = 'GET';
        $path   = '/crons';
        $body   = '';

        // Sign with the correct secret
        $correctValidator = $this->makeValidator();
        $sig              = $correctValidator->compute($method, $path, $body, 0, '');

        // Validate with a different secret (simulates attacker without the shared secret)
        $wrongValidator = new HmacValidator('wrong-secret-that-does-not-match-at-all-0000000000');

        $this->assertFalse(
            $wrongValidator->validate($method, $path, $body, $sig, 0, ''),
            'A signature produced with a different secret must be rejected'
        );
    }
}
