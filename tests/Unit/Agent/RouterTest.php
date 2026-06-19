<?php

declare(strict_types=1);

/**
 * Cronmanager – Unit Tests: Router
 *
 * Tests the agent Router's pattern matching, parameter extraction,
 * 404 / 405 error responses, and the critical registration-order rule
 * (bulk endpoints must be registered before parameterised {id} routes).
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Tests\Unit\Agent;

use Cronmanager\Agent\Router;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
    }

    // -------------------------------------------------------------------------
    // Happy-path dispatch
    // -------------------------------------------------------------------------

    #[Test]
    public function dispatchCallsHandlerForMatchingRoute(): void
    {
        $called = false;
        $this->router->addRoute('GET', '/health', function () use (&$called): void {
            $called = true;
        });

        $this->router->dispatch('GET', '/health');

        $this->assertTrue($called, 'Handler must have been called');
    }

    #[Test]
    public function dispatchExtractsPathParameter(): void
    {
        $capturedParams = [];
        $this->router->addRoute('GET', '/crons/{id}', function (array $p) use (&$capturedParams): void {
            $capturedParams = $p;
        });

        $this->router->dispatch('GET', '/crons/42');

        $this->assertSame(['id' => '42'], $capturedParams);
    }

    #[Test]
    public function dispatchExtractsMultiplePathParameters(): void
    {
        $captured = [];
        $this->router->addRoute(
            'PUT',
            '/maintenance/windows/{windowId}/targets/{target}',
            function (array $p) use (&$captured): void {
                $captured = $p;
            }
        );

        $this->router->dispatch('PUT', '/maintenance/windows/7/targets/local');

        $this->assertSame(['windowId' => '7', 'target' => 'local'], $captured);
    }

    #[Test]
    public function dispatchIsMethodCaseInsensitive(): void
    {
        $called = false;
        $this->router->addRoute('POST', '/crons', function () use (&$called): void {
            $called = true;
        });

        $this->router->dispatch('post', '/crons');

        $this->assertTrue($called);
    }

    #[Test]
    public function dispatchAllowsTrailingSlash(): void
    {
        $called = false;
        $this->router->addRoute('GET', '/crons', function () use (&$called): void {
            $called = true;
        });

        $this->router->dispatch('GET', '/crons/');

        $this->assertTrue($called, 'Trailing slash should match the same route');
    }

    #[Test]
    public function dispatchCallsFirstMatchingRoute(): void
    {
        $firstCalled  = false;
        $secondCalled = false;

        $this->router->addRoute('GET', '/crons', function () use (&$firstCalled): void {
            $firstCalled = true;
        });
        $this->router->addRoute('GET', '/crons', function () use (&$secondCalled): void {
            $secondCalled = true;
        });

        $this->router->dispatch('GET', '/crons');

        $this->assertTrue($firstCalled,   'First registered handler must be called');
        $this->assertFalse($secondCalled, 'Second handler must NOT be called');
    }

    // -------------------------------------------------------------------------
    // 404 – path not found
    // -------------------------------------------------------------------------

    #[Test]
    public function dispatchReturns404WhenNoRouteMatchesPath(): void
    {
        ob_start();
        $this->router->dispatch('GET', '/nonexistent');
        $output = ob_get_clean();

        $this->assertSame(404, http_response_code());

        $decoded = json_decode((string) $output, true);
        $this->assertIsArray($decoded);
        $this->assertSame(404, $decoded['code']);
    }

    // -------------------------------------------------------------------------
    // 405 – path matched but wrong method
    // -------------------------------------------------------------------------

    #[Test]
    public function dispatchReturns405WhenPathMatchesButMethodDiffers(): void
    {
        $this->router->addRoute('GET', '/crons', function (): void {});

        ob_start();
        $this->router->dispatch('POST', '/crons');
        $output = ob_get_clean();

        $this->assertSame(405, http_response_code());

        $decoded = json_decode((string) $output, true);
        $this->assertIsArray($decoded);
        $this->assertSame(405, $decoded['code']);
    }

    // -------------------------------------------------------------------------
    // Critical: bulk endpoints must be registered before {id} routes
    // (CLAUDE.md known gotcha – "bulk" would otherwise match as an ID)
    // -------------------------------------------------------------------------

    #[Test]
    public function bulkRouteRegisteredBeforeParamRouteIsDispatchedCorrectly(): void
    {
        $bulkCalled  = false;
        $paramCalled = false;

        // Correct order: specific static segment first, then wildcard
        $this->router->addRoute('POST', '/crons/bulk/status', function () use (&$bulkCalled): void {
            $bulkCalled = true;
        });
        $this->router->addRoute('GET', '/crons/{id}', function () use (&$paramCalled): void {
            $paramCalled = true;
        });

        $this->router->dispatch('POST', '/crons/bulk/status');

        $this->assertTrue($bulkCalled,   '"bulk" route must match the static path, not the {id} wildcard');
        $this->assertFalse($paramCalled, '{id} handler must NOT be called for the bulk path');
    }

    #[Test]
    public function paramRouteRegisteredBeforeBulkCatchesBulkAsId(): void
    {
        $bulkCalled     = false;
        $capturedParams = [];

        // Wrong order: wildcard first → "bulk" is captured as {id}
        $this->router->addRoute('POST', '/crons/{id}', function (array $p) use (&$capturedParams): void {
            $capturedParams = $p;
        });
        $this->router->addRoute('POST', '/crons/bulk/status', function () use (&$bulkCalled): void {
            $bulkCalled = true;
        });

        // /crons/bulk/status has three segments, so it won't match /crons/{id} (two segments)
        // but /crons/bulk would be caught. Test /crons/bulk to demonstrate the danger.
        $this->router->dispatch('POST', '/crons/bulk');

        $this->assertFalse($bulkCalled, 'Bulk handler not reached – wrong registration order');
        $this->assertSame(['id' => 'bulk'], $capturedParams, '"bulk" was mistakenly captured as {id}');
    }

    // -------------------------------------------------------------------------
    // Multiple methods on same path
    // -------------------------------------------------------------------------

    #[Test]
    public function differentMethodsOnSamePathDispatchToCorrectHandler(): void
    {
        $getResult  = null;
        $postResult = null;

        $this->router->addRoute('GET',  '/crons/{id}', function () use (&$getResult): void {
            $getResult = 'GET';
        });
        $this->router->addRoute('POST', '/crons/{id}', function () use (&$postResult): void {
            $postResult = 'POST';
        });

        $this->router->dispatch('GET',  '/crons/1');
        $this->router->dispatch('POST', '/crons/1');

        $this->assertSame('GET',  $getResult);
        $this->assertSame('POST', $postResult);
    }

    // -------------------------------------------------------------------------
    // DELETE method
    // -------------------------------------------------------------------------

    #[Test]
    public function dispatchHandlesDeleteMethod(): void
    {
        $captured = [];
        $this->router->addRoute('DELETE', '/crons/{id}', function (array $p) use (&$captured): void {
            $captured = $p;
        });

        $this->router->dispatch('DELETE', '/crons/99');

        $this->assertSame(['id' => '99'], $captured);
    }

    // -------------------------------------------------------------------------
    // Non-numeric path parameters
    // -------------------------------------------------------------------------

    #[Test]
    public function dispatchExtractsNonNumericPathParameter(): void
    {
        $captured = [];
        $this->router->addRoute('GET', '/export/{format}', function (array $p) use (&$captured): void {
            $captured = $p;
        });

        $this->router->dispatch('GET', '/export/json');

        $this->assertSame(['format' => 'json'], $captured);
    }
}
