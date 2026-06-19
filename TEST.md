# Cronmanager – Test Documentation

This document describes the automated test strategy, test infrastructure, and
instructions for running and extending the test suite.

---

## Contents

1. [Strategy Overview](#1-strategy-overview)
2. [Quick Start](#2-quick-start)
3. [Directory Structure](#3-directory-structure)
4. [Test Suites](#4-test-suites)
   - [Unit Tests](#41-unit-tests)
   - [Integration Tests](#42-integration-tests)
   - [E2E Tests](#43-e2e-tests-placeholder)
5. [Integration Test Environment](#5-integration-test-environment)
6. [CI Integration (GitHub Actions)](#6-ci-integration-github-actions)
7. [Writing New Tests](#7-writing-new-tests)
8. [Coverage Goals](#8-coverage-goals)

---

## 1. Strategy Overview

The test suite follows a three-layer strategy that prioritises high-ROI
coverage of critical business logic without the overhead of a full-stack
container-based setup for every test run.

| Layer | Tool | Scope | DB | Network |
|---|---|---|---|---|
| **Unit** | PHPUnit 11 | Pure logic, no I/O | No | No |
| **Integration** | PHPUnit 11 + MariaDB | Endpoints + repositories against a real DB | Yes (test DB) | No |
| **E2E** | PHPUnit 11 (planned) | Full Docker stack over HTTP | Yes | Yes |

**Critical paths covered first:** The highest-complexity classes
(`ExecutionFinishEndpoint` at ~1400 lines, `ExecutionStartEndpoint`, HMAC
signing, retry scheduling, maintenance windows) are fully covered by the
integration suite before any UI or shell-level tests are considered.

---

## 2. Quick Start

### Prerequisites

- PHP 8.4 CLI with extensions: `pdo`, `pdo_mysql`, `mbstring`
- Docker (for integration tests)
- Composer (dev dependencies are in the project root `composer.json`)

### Install dependencies

```bash
composer install
```

### Run unit tests only (no Docker required)

```bash
./vendor/bin/phpunit --testsuite unit
# or
./run-tests.sh unit
```

### Run unit + integration tests

```bash
# Start the test database
docker compose -f tests/docker-compose.test.yml up -d

# Wait for MariaDB to become ready
until docker compose -f tests/docker-compose.test.yml exec cronmanager-test-db \
    mariadb-admin ping -h 127.0.0.1 -u root -proot_test --silent 2>/dev/null
do sleep 1; done

# Run tests
./vendor/bin/phpunit --testsuite unit,integration
# or
./run-tests.sh

# Tear down when done
docker compose -f tests/docker-compose.test.yml down
```

### Run a specific test file

```bash
./vendor/bin/phpunit tests/Integration/Endpoints/ExecutionFinishEndpointTest.php
```

### Run a specific test by name pattern

```bash
./vendor/bin/phpunit --filter testRetryScheduledOnMatchingExitCode
```

---

## 3. Directory Structure

```
tests/
├── bootstrap.php                          # Loads /opt/phplib/vendor + project autoloader
├── docker-compose.test.yml                # MariaDB (port 13306) + Mailpit (port 1025/8025)
│
├── Unit/
│   ├── Agent/
│   │   └── RouterTest.php                 # Route pattern matching, bulk-before-id ordering
│   ├── Security/
│   │   └── HmacValidatorTest.php          # HMAC signature validation
│   ├── Util/
│   │   └── ExitCodeMatcherTest.php        # Exit-code filter expression parsing
│   └── Web/
│       └── HostAgentClientTest.php        # HTTP client HMAC signing + error handling
│
├── Integration/
│   ├── Base/
│   │   ├── IntegrationTestCase.php        # DB connection, schema bootstrap, TX rollback
│   │   └── AgentEndpointTestCase.php      # php://input injection, response capture
│   ├── Endpoints/
│   │   ├── ExecutionStartEndpointTest.php
│   │   └── ExecutionFinishEndpointTest.php
│   ├── Repository/
│   │   └── MaintenanceWindowRepositoryTest.php
│   ├── Fixtures/                          # (reserved for shared fixture factories)
│   └── Support/
│       ├── AgentResponse.php              # Captures http_response_code() + json output
│       └── PhpInputStream.php             # Stream wrapper for php://input in tests
│
└── E2E/                                   # Placeholder for future API-level E2E tests
```

---

## 4. Test Suites

### 4.1 Unit Tests

**Location:** `tests/Unit/`  
**Run:** `./vendor/bin/phpunit --testsuite unit`  
**DB required:** No  
**Current count:** 85 tests, ~127 assertions

Unit tests cover pure logic classes with no external dependencies. All tests
execute in under 100 ms.

#### `Security/HmacValidatorTest` (16 tests)

Tests the `HmacValidator` class which guards every agent endpoint.

| Group | Tests |
|---|---|
| Valid signatures | Correct method + path + body |
| Body manipulation | Modified body → invalid |
| Path manipulation | Modified path → invalid |
| Method manipulation | Method case differences |
| Constant-time comparison | Timing-safe validation |
| Empty secret | Exception thrown |
| Prefix stripping | `sha256=…` prefix handling |

#### `Util/ExitCodeMatcherTest` (29 tests)

Tests the `ExitCodeMatcher` utility class which parses `restart_on_exitcodes`
expressions (e.g. `1-5,10,255`).

| Group | Tests |
|---|---|
| null / empty (match-all) | Any non-zero exit code matches |
| Individual codes | `"1"`, `"255"` |
| Hyphenated ranges | `"1-5"`, `"100-110"` |
| Mixed expressions | `"1-3,10,20-22"` |
| Boundary values | 0, 255, edge of range |
| Invalid input | Non-numeric, out-of-range |

#### `Agent/RouterTest` (14 tests)

Tests `agent/src/Router.php` route dispatching.

| Group | Tests |
|---|---|
| Static routes | `GET /health`, `POST /auth` |
| Parameterised routes | `/crons/{id}` extracts ID |
| Bulk-before-id ordering | `/crons/bulk/…` must be registered before `/crons/{id}` |
| 404 on unknown path | Unregistered path |
| 405 on wrong method | Registered path, wrong HTTP method |

> **Why the ordering test matters:** the router matches routes in registration
> order. `GET /crons/bulk/status` must be registered before `GET /crons/{id}`
> or `"bulk"` is silently interpreted as a job ID. This is a known pitfall
> documented in `CLAUDE.md`.

#### `Web/HostAgentClientTest` (12 tests)

Tests `web/src/Agent/HostAgentClient.php` without making real HTTP calls.
A Guzzle `MockHandler` backed `HandlerStack` is injected into the private
`$guzzle` property via `ReflectionProperty`, and `Middleware::history()`
captures outgoing requests for header/URL inspection.

| Group | Tests |
|---|---|
| GET signing | HMAC over path only (no query string) |
| GET URL | Query string present in actual request URL |
| GET headers | `Accept: application/json` |
| POST signing | HMAC over path + JSON body |
| POST headers | `Content-Type: application/json` |
| PUT signing | HMAC over path + JSON body |
| DELETE signing | HMAC over path, empty body |
| 4xx/5xx errors | `AgentHttpException` with correct `getStatusCode()` |
| `ConnectException` | Wrapped in `RuntimeException("Agent unreachable: …")` |
| Non-JSON body | Returns `[]` instead of throwing |

**HMAC formula verified:**
```
X-Agent-Signature = hmac_sha256(SECRET, UPPER(method) + path + rawBody)
```
For GET requests `rawBody = ""` and `path` excludes any `?query=string`.

---

### 4.2 Integration Tests

**Location:** `tests/Integration/`  
**Run:** `./vendor/bin/phpunit --testsuite integration`  
**DB required:** Yes (see §5)  
**Current count:** 68 tests, ~157 assertions

Integration tests use a real MariaDB connection. The full schema and all
migrations are applied once per PHP process. Each individual test runs inside a
database transaction that is rolled back in `tearDown()`, so tests are fully
isolated and leave no residual data.

#### Base classes

**`IntegrationTestCase`** provides:
- `$this->pdo` – PDO connection to the test database
- `seedJob(array $overrides = []): int` – minimal `cronjobs` row
- `seedJobTarget(int $jobId, string $target = 'local'): int`
- `seedRunningExecution(int $jobId, array $overrides = []): int`
- `seedFinishedExecution(int $jobId, array $overrides = []): int`
- `seedRetryState(int $jobId, int $rootExecutionId, array $overrides = [])`
- `seedMaintenanceWindow(array $overrides = []): int`
- `seedActiveMaintenanceWindow(string $target): int` – `* * * * *` + 1440 min (always active)
- `fetchExecution(int $id): array|false`
- `countExecutions(int $jobId): int`
- `hasRetryState(int $jobId, string $target = 'local'): bool`

**`AgentEndpointTestCase`** extends `IntegrationTestCase` and adds:
- `callHandle(object $endpoint, array $body): void` – injects JSON into `php://input` and invokes `$endpoint->handle([])`
- `assertStatus(int $expected): void`
- `assertBodyHas(string $key, mixed $expected): void`
- `assertBodyHasKey(string $key): void`
- `createNullLogger(): Logger`

**`AgentResponse`** (stream wrapper + static capture) intercepts
`http_response_code()` and `json_encode()` output from endpoint `handle()`
calls and stores them in static properties for assertion.

#### `Endpoints/ExecutionStartEndpointTest` (20 tests)

| Group | Tests |
|---|---|
| Happy path (4) | Execution log row created, `execution_id` returned, `started_at` set, `target` stored |
| Input validation (4) | Missing `job_id`, missing `target`, unknown `job_id` → 404, inactive job → 404 |
| Singleton guard (3) | Running execution blocks start → 409, finished execution allows start, different target allows start |
| Retry consumption (4) | `is_retry_invocation=true` consumes `job_retry_state` row, sets `retry_attempt`, regular tick blocked by pending retry |
| Maintenance window (3) | Target in maintenance + `run_in_maintenance=0` → 423 + skipped log, `run_in_maintenance=1` → 201 + `during_maintenance` flag, agent-level `_agent_` window → 423 for all jobs |
| Concurrent safety (2) | Back-to-back starts for same job |

#### `Endpoints/ExecutionFinishEndpointTest` (28 tests)

| Group | Tests |
|---|---|
| Happy path (4) | `finished_at`/`exit_code`/`output` written, UTC→system-time conversion, empty output defaults to `""`, retry state cleaned up on success |
| Input validation (5) | Invalid JSON → 400, missing `execution_id`/`job_id`/`exit_code`/`finished_at` → 422 each |
| Not found (1) | Unknown `execution_id` → 404 |
| Auto-kill guard (2) | Already-finished row → 200 without overwriting, `exit_code` preserved |
| Retry scheduling (5) | Matching exit code creates `job_retry_state` row, correct `next_retry_attempt`/`root_execution_id`, `retry_count=0` creates no row, non-matching exit code creates no row, exhausted retries create no row |
| Failure threshold (6) | `threshold=1` notifies on 1st failure, `threshold=3` does not notify at 2nd, notifies at 3rd, does not re-notify at 4th, success never notifies, `notify_on_failure=false` never notifies |
| Maintenance suppression (1) | `during_maintenance=1` suppresses failure notification |
| Recovery notification (3) | Streak ≥ threshold + `notify_on_recovery=1` → 200 (no exception), no opt-in → 200, streak below threshold → 200 |

> **Mocking strategy for notifiers:** `MailNotifier` and `TelegramNotifier`
> are `final` classes; they cannot be mocked by PHPUnit. Real instances are
> constructed with a `createStub(ConfigInterface::class)` that returns
> `null`/`false` for all config lookups, disabling real SMTP/Telegram sending.
> Notification dispatch goes through a background `exec()` call to
> `send-notification.php`; tests verify the `notified` field in the response
> body rather than intercepting the background process.

#### `Repository/MaintenanceWindowRepositoryTest` (20 tests)

| Group | Tests |
|---|---|
| CRUD (7) | `create` + `findById` round-trip, `findAll` (all + target-filtered + empty), `update` modifies row, `update` returns false for unknown ID, `delete` removes row, `delete` returns false for unknown ID |
| `isTargetInMaintenance()` (6) | Always-active window (`* * * * *` + 1440 min) → true, inactive window → false, no windows for target → false, window for different target → false, `_agent_` window → `isAgentInMaintenance()` true, no `_agent_` window → false |
| `detectConflicts()` (5) | Frequent job (`* * * * *`) inside always-active window → `lookAhead` conflicts returned, no windows → `[]`, inactive window → `[]`, result contains required keys (`run_time`, `window_id`, `window_start`, `window_end`), `window_id` matches seeded row ID |

---

### 4.3 E2E Tests (placeholder)

**Location:** `tests/E2E/`  
**Status:** Not yet implemented

Future E2E tests would spin up the complete Docker stack (agent + web +
MariaDB), make real HTTP requests with valid HMAC headers, and verify DB state
and HTTP responses end-to-end. This layer primarily catches routing
configuration bugs and container startup issues that unit and integration tests
cannot detect.

Planned scenarios:
- Full execution flow: create job → `POST /execution/start` → `POST /execution/finish` → verify DB
- Retry chain: failing execution → `job_retry_state` row → retry fires → verify chain
- HMAC rejection: wrong secret → 401/403

---

## 5. Integration Test Environment

The test database is provided by `tests/docker-compose.test.yml`:

```yaml
services:
  cronmanager-test-db:
    image: mariadb:lts
    environment:
      MYSQL_ROOT_PASSWORD: root_test
      MYSQL_DATABASE:      cronmanager_test
      MYSQL_USER:          cronmanager_test
      MYSQL_PASSWORD:      cronmanager_test
    ports:
      - "127.0.0.1:13306:3306"

  mailpit:
    image: axllent/mailpit:latest
    ports:
      - "127.0.0.1:1025:1025"   # SMTP
      - "127.0.0.1:8025:8025"   # Web UI
```

Connection parameters are read from `phpunit.xml` `<env>` entries:

| Variable | Default | Description |
|---|---|---|
| `DB_HOST` | `127.0.0.1` | MariaDB host |
| `DB_PORT` | `13306` | MariaDB port |
| `DB_NAME` | `cronmanager_test` | Database name |
| `DB_USER` | `cronmanager_test` | DB user |
| `DB_PASSWORD` | `cronmanager_test` | DB password |

### Schema bootstrap

`IntegrationTestCase::applySchema()` runs once per PHP process (tracked via
`static bool $schemaInitialised`):

1. Applies `agent/sql/schema.sql` (`CREATE TABLE IF NOT EXISTS`)
2. Applies all `agent/sql/migrations/*.sql` files in alphabetical order

Migration errors are silently swallowed (`PDOException` caught) to handle
already-applied migrations idempotently.

### Transaction isolation

Each test's `setUp()` calls `$this->pdo->beginTransaction()`.  
Each test's `tearDown()` calls `$this->pdo->rollBack()`.

This means every test starts with a clean database state (from the perspective
of that test's data) without truncating tables, and tests can run in any order
without interfering with each other.

---

## 6. CI Integration (GitHub Actions)

All three workflows run the test suite before building Docker images:

```
php-tests job (runs first)
  ├── MariaDB LTS service container (port 13306)
  ├── PHP 8.4 setup (shivammathur/setup-php)
  ├── composer install
  └── ./vendor/bin/phpunit --testsuite unit,integration

build-and-push / build-and-push-dev (depends on php-tests)
  └── Only runs when php-tests passes
```

**Workflow files:**
- `.github/workflows/php-tests.yml` – runs on `push` to `main` (tests only, no image build)
- `.github/workflows/docker-release.yml` – runs on published GitHub release
- `.github/workflows/docker-dev.yml` – runs on push to any non-`main` branch

**MariaDB health check** in CI uses:
```yaml
--health-cmd="mariadb-admin ping -h 127.0.0.1 -u root -proot_test --silent"
--health-interval=5s
--health-timeout=5s
--health-retries=10
--health-start-period=20s
```

The tests skip automatically if the DB is unreachable
(`$this->markTestSkipped(...)` in `IntegrationTestCase::createConnection()`),
so a missing DB never causes false test failures — it surfaces as a "skipped"
count instead.

---

## 7. Writing New Tests

### Unit test

Extend `PHPUnit\Framework\TestCase` directly. No base class needed.

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\YourNamespace;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class YourClassTest extends TestCase
{
    #[Test]
    public function itDoesWhatItSays(): void
    {
        // arrange
        // act
        // assert
    }
}
```

Place the file under `tests/Unit/` in the namespace that mirrors the class
under test (`agent/src` → `Tests\Unit\Agent`, `web/src` → `Tests\Unit\Web`).

### Integration test (endpoint)

Extend `Tests\Integration\Base\AgentEndpointTestCase` and use `callHandle()`:

```php
<?php
declare(strict_types=1);

namespace Tests\Integration\Endpoints;

use Cronmanager\Agent\Endpoints\YourEndpoint;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Base\AgentEndpointTestCase;

final class YourEndpointTest extends AgentEndpointTestCase
{
    private function makeEndpoint(): YourEndpoint
    {
        return new YourEndpoint(
            pdo:    $this->pdo,
            logger: $this->createNullLogger(),
        );
    }

    #[Test]
    public function happyPathReturns200(): void
    {
        $jobId = $this->seedJob();

        $this->callHandle($this->makeEndpoint(), ['job_id' => $jobId]);

        $this->assertStatus(200);
        $this->assertBodyHas('success', true);
    }
}
```

### Integration test (repository)

Extend `Tests\Integration\Base\IntegrationTestCase` directly. Use `$this->pdo`
for assertions that bypass the repository layer.

### Adding seed helpers

If your test needs a new fixture that multiple test classes will use, add it as
a `protected` method on `IntegrationTestCase`. If it's specific to one test
class, keep it as a `private` method there.

### Mocking `final` classes

`MailNotifier`, `TelegramNotifier`, and `CrontabManager` are all `final` and
cannot be mocked by PHPUnit. Use real instances with stubbed dependencies:

```php
// ConfigInterface is an interface → can be stubbed
$configStub = $this->createStub(ConfigInterface::class);
// All config->get() calls return null → disables real sending
$notifier = new MailNotifier($this->createNullLogger(), $configStub);
```

---

## 8. Coverage Goals

Coverage is not enforced on every PR but can be generated on demand:

```bash
# Requires Xdebug or PCOV
./vendor/bin/phpunit --testsuite unit,integration \
    --coverage-html coverage/ \
    --coverage-text
```

| Class / area | Target |
|---|---|
| `HmacValidator` | 100 % |
| `ExitCodeMatcher` | 100 % |
| `Router` | 100 % |
| `ExecutionStartEndpoint` | ≥ 85 % |
| `ExecutionFinishEndpoint` | ≥ 80 % |
| `HostAgentClient` | ≥ 90 % |
| `MaintenanceWindowRepository` | ≥ 85 % |
| Overall (all covered classes) | ≥ 70 % |
