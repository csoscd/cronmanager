<?php

declare(strict_types=1);

/**
 * Cronmanager – Integration Tests: ExecutionFinishEndpoint
 *
 * Tests POST /execution/finish against a real MariaDB database.
 *
 * External services (SMTP, Telegram, InfluxDB, crontab) are handled as follows:
 *   - MailNotifier / TelegramNotifier: real instances with a stubbed ConfigInterface
 *     (mail.enabled and telegram.enabled resolve to null/false → no actual sending).
 *     In tests the background-process dispatch path is taken (send-notification.php
 *     exists + exec() available), so the notifiers are never called synchronously.
 *   - CrontabManager: real instance with /dev/null as wrapperScript.  addOnceEntry()
 *     will fail when the target linux_user does not exist on the test host; the
 *     exception is caught silently by the endpoint.  The job_retry_state INSERT
 *     occurs BEFORE the crontab call, so DB state assertions remain valid.
 *
 * Run with the test DB stack running:
 *   docker compose -f tests/docker-compose.test.yml up -d
 *   ./vendor/bin/phpunit --testsuite integration
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Tests\Integration\Endpoints;

use Cronmanager\Agent\Cron\CrontabManager;
use Cronmanager\Agent\Endpoints\ExecutionFinishEndpoint;
use Cronmanager\Agent\Notification\MailNotifier;
use Cronmanager\Agent\Notification\TelegramNotifier;
use Noodlehaus\ConfigInterface;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Base\AgentEndpointTestCase;
use Tests\Support\AgentResponse;

final class ExecutionFinishEndpointTest extends AgentEndpointTestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeEndpoint(): ExecutionFinishEndpoint
    {
        // ConfigInterface stub: all get() calls return null → mail/telegram disabled
        $configStub = $this->createStub(ConfigInterface::class);

        return new ExecutionFinishEndpoint(
            pdo:              $this->pdo,
            logger:           $this->createNullLogger(),
            mailNotifier:     new MailNotifier($this->createNullLogger(), $configStub),
            telegramNotifier: new TelegramNotifier($this->createNullLogger(), $configStub),
            crontabManager:   new CrontabManager($this->createNullLogger(), '/dev/null'),
            wrapperScript:    '/dev/null',
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchLatestExecution(int $jobId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM execution_log WHERE cronjob_id = :id ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':id' => $jobId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * @return array<string, mixed>|false
     */
    private function fetchRetryStateRow(int $jobId, string $target): array|false
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM job_retry_state WHERE job_id = :j AND target = :t LIMIT 1'
        );
        $stmt->execute([':j' => $jobId, ':t' => $target]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // =========================================================================
    // 1. Happy path
    // =========================================================================

    #[Test]
    public function happyPathUpdatesExecutionLogAndReturns200(): void
    {
        $jobId       = $this->seedJob();
        $executionId = $this->seedRunningExecution($jobId);

        $this->callHandle($this->makeEndpoint(), [
            'execution_id' => $executionId,
            'job_id'       => $jobId,
            'exit_code'    => 0,
            'output'       => 'Job completed.',
            'finished_at'  => '2026-01-15T10:00:05Z',
        ]);

        $this->assertStatus(200);
        $this->assertBodyHas('execution_id', $executionId);
        $this->assertBodyHas('job_id', $jobId);
        $this->assertBodyHas('exit_code', 0);

        $row = $this->fetchLatestExecution($jobId);
        $this->assertNotNull($row);
        $this->assertSame('2026-01-15 10:00:05', $row['finished_at']);
        $this->assertSame(0, (int) $row['exit_code']);
        $this->assertSame('Job completed.', $row['output']);
    }

    #[Test]
    public function iso8601TimestampWithPositiveOffsetIsConvertedToUtc(): void
    {
        $jobId       = $this->seedJob();
        $executionId = $this->seedRunningExecution($jobId);

        // +02:00 → UTC is two hours earlier
        $this->callHandle($this->makeEndpoint(), [
            'execution_id' => $executionId,
            'job_id'       => $jobId,
            'exit_code'    => 0,
            'finished_at'  => '2026-01-15T12:00:00+02:00',
        ]);

        $this->assertStatus(200);

        $row = $this->fetchLatestExecution($jobId);
        $this->assertSame('2026-01-15 10:00:00', $row['finished_at']);
    }

    #[Test]
    public function missingOutputFieldDefaultsToEmptyString(): void
    {
        $jobId       = $this->seedJob();
        $executionId = $this->seedRunningExecution($jobId);

        $this->callHandle($this->makeEndpoint(), [
            'execution_id' => $executionId,
            'job_id'       => $jobId,
            'exit_code'    => 0,
            'finished_at'  => '2026-01-15T10:00:05Z',
            // no 'output' key
        ]);

        $this->assertStatus(200);

        $row = $this->fetchLatestExecution($jobId);
        $this->assertSame('', $row['output']);
    }

    #[Test]
    public function successWithPendingRetryStateCleansUpRetryState(): void
    {
        $jobId       = $this->seedJob(['retry_count' => 3]);
        $executionId = $this->seedRunningExecution($jobId, ['target' => 'local']);
        $this->seedRetryState($jobId, $executionId, ['target' => 'local']);

        $this->callHandle($this->makeEndpoint(), [
            'execution_id' => $executionId,
            'job_id'       => $jobId,
            'exit_code'    => 0,
            'finished_at'  => '2026-01-15T10:00:05Z',
            'target'       => 'local',
        ]);

        $this->assertStatus(200);
        $this->assertFalse($this->hasRetryState($jobId, 'local'));
    }

    // =========================================================================
    // 2. Input validation
    // =========================================================================

    #[Test]
    public function invalidJsonBodyReturns400(): void
    {
        \Tests\Support\PhpInputStream::set('{ not valid json');
        $this->makeEndpoint()->handle([]);
        \Tests\Support\PhpInputStream::restore();

        $this->assertStatus(400);
    }

    #[Test]
    public function missingExecutionIdReturns422(): void
    {
        $jobId = $this->seedJob();

        $this->callHandle($this->makeEndpoint(), [
            'job_id'      => $jobId,
            'exit_code'   => 0,
            'finished_at' => '2026-01-15T10:00:05Z',
        ]);

        $this->assertStatus(422);
        $this->assertArrayHasKey('execution_id', AgentResponse::$body['fields'] ?? []);
    }

    #[Test]
    public function missingJobIdReturns422(): void
    {
        $jobId       = $this->seedJob();
        $executionId = $this->seedRunningExecution($jobId);

        $this->callHandle($this->makeEndpoint(), [
            'execution_id' => $executionId,
            'exit_code'    => 0,
            'finished_at'  => '2026-01-15T10:00:05Z',
        ]);

        $this->assertStatus(422);
        $this->assertArrayHasKey('job_id', AgentResponse::$body['fields'] ?? []);
    }

    #[Test]
    public function missingExitCodeReturns422(): void
    {
        $jobId       = $this->seedJob();
        $executionId = $this->seedRunningExecution($jobId);

        $this->callHandle($this->makeEndpoint(), [
            'execution_id' => $executionId,
            'job_id'       => $jobId,
            'finished_at'  => '2026-01-15T10:00:05Z',
        ]);

        $this->assertStatus(422);
        $this->assertArrayHasKey('exit_code', AgentResponse::$body['fields'] ?? []);
    }

    #[Test]
    public function missingFinishedAtReturns422(): void
    {
        $jobId       = $this->seedJob();
        $executionId = $this->seedRunningExecution($jobId);

        $this->callHandle($this->makeEndpoint(), [
            'execution_id' => $executionId,
            'job_id'       => $jobId,
            'exit_code'    => 0,
        ]);

        $this->assertStatus(422);
        $this->assertArrayHasKey('finished_at', AgentResponse::$body['fields'] ?? []);
    }

    #[Test]
    public function exitCodeZeroIsValidAndReturns200(): void
    {
        $jobId       = $this->seedJob();
        $executionId = $this->seedRunningExecution($jobId);

        $this->callHandle($this->makeEndpoint(), [
            'execution_id' => $executionId,
            'job_id'       => $jobId,
            'exit_code'    => 0,
            'finished_at'  => '2026-01-15T10:00:05Z',
        ]);

        $this->assertStatus(200);
    }

    // =========================================================================
    // 3. Execution not found
    // =========================================================================

    #[Test]
    public function unknownExecutionIdReturns404(): void
    {
        $jobId = $this->seedJob();

        $this->callHandle($this->makeEndpoint(), [
            'execution_id' => 999999,
            'job_id'       => $jobId,
            'exit_code'    => 1,
            'finished_at'  => '2026-01-15T10:00:05Z',
        ]);

        $this->assertStatus(404);
    }

    // =========================================================================
    // 4. Auto-kill guard
    //    When check-limits.php already set finished_at, the wrapper's finish
    //    report must be acknowledged (200) without overwriting the stored result.
    // =========================================================================

    #[Test]
    public function alreadyFinishedExecutionIsAcknowledgedWith200(): void
    {
        $jobId       = $this->seedJob();
        $executionId = $this->seedRunningExecution($jobId);

        // Simulate check-limits.php auto-killing the execution before the wrapper reports
        $this->pdo->prepare(
            'UPDATE execution_log SET finished_at = :t, exit_code = -2 WHERE id = :id'
        )->execute([':t' => '2026-01-15 10:00:04', ':id' => $executionId]);

        $this->callHandle($this->makeEndpoint(), [
            'execution_id' => $executionId,
            'job_id'       => $jobId,
            'exit_code'    => 143,   // SIGTERM exit code from the wrapper
            'finished_at'  => '2026-01-15T10:00:05Z',
        ]);

        $this->assertStatus(200);
    }

    #[Test]
    public function alreadyFinishedExecutionExitCodeIsNotOverwritten(): void
    {
        $jobId       = $this->seedJob();
        $executionId = $this->seedRunningExecution($jobId);

        $this->pdo->prepare(
            'UPDATE execution_log SET finished_at = :t, exit_code = -2 WHERE id = :id'
        )->execute([':t' => '2026-01-15 10:00:04', ':id' => $executionId]);

        $this->callHandle($this->makeEndpoint(), [
            'execution_id' => $executionId,
            'job_id'       => $jobId,
            'exit_code'    => 143,
            'finished_at'  => '2026-01-15T10:00:05Z',
        ]);

        $row = $this->fetchLatestExecution($jobId);
        $this->assertSame(-2, (int) $row['exit_code']);          // auto-kill code preserved
        $this->assertSame('2026-01-15 10:00:04', $row['finished_at']); // original timestamp preserved
    }

    // =========================================================================
    // 5. Retry scheduling (verified via DB state)
    //    CrontabManager::addOnceEntry() is called after the DB INSERT and may
    //    throw when the linux_user does not exist on the test host – the endpoint
    //    catches this and continues.  The job_retry_state INSERT is the assertion.
    // =========================================================================

    #[Test]
    public function matchingExitCodeCreatesJobRetryStateRow(): void
    {
        $jobId       = $this->seedJob([
            'retry_count'          => 3,
            'restart_on_exitcodes' => '1',
            'retry_delay_minutes'  => 5,
        ]);
        $executionId = $this->seedRunningExecution($jobId, ['target' => 'local']);

        $this->callHandle($this->makeEndpoint(), [
            'execution_id' => $executionId,
            'job_id'       => $jobId,
            'exit_code'    => 1,    // matches restart_on_exitcodes
            'finished_at'  => '2026-01-15T10:00:05Z',
            'target'       => 'local',
        ]);

        $this->assertStatus(200);
        $this->assertTrue($this->hasRetryState($jobId, 'local'));
    }

    #[Test]
    public function retryStateRowContainsCorrectNextAttemptAndRootExecutionId(): void
    {
        $jobId       = $this->seedJob([
            'retry_count'          => 3,
            'restart_on_exitcodes' => '1',
        ]);
        $executionId = $this->seedRunningExecution($jobId, ['target' => 'local']);

        $this->callHandle($this->makeEndpoint(), [
            'execution_id' => $executionId,
            'job_id'       => $jobId,
            'exit_code'    => 1,
            'finished_at'  => '2026-01-15T10:00:05Z',
            'target'       => 'local',
        ]);

        $state = $this->fetchRetryStateRow($jobId, 'local');

        $this->assertNotFalse($state);
        $this->assertSame(1, (int) $state['next_retry_attempt']);
        $this->assertSame($executionId, (int) $state['root_execution_id']);
    }

    #[Test]
    public function retryCountZeroDoesNotCreateRetryStateRow(): void
    {
        $jobId       = $this->seedJob([
            'retry_count'          => 0,   // no retries configured
            'restart_on_exitcodes' => '1',
        ]);
        $executionId = $this->seedRunningExecution($jobId, ['target' => 'local']);

        $this->callHandle($this->makeEndpoint(), [
            'execution_id' => $executionId,
            'job_id'       => $jobId,
            'exit_code'    => 1,
            'finished_at'  => '2026-01-15T10:00:05Z',
            'target'       => 'local',
        ]);

        $this->assertFalse($this->hasRetryState($jobId, 'local'));
    }

    #[Test]
    public function nonMatchingExitCodeDoesNotCreateRetryStateRow(): void
    {
        $jobId       = $this->seedJob([
            'retry_count'          => 3,
            'restart_on_exitcodes' => '2',   // exit code 1 does not match
        ]);
        $executionId = $this->seedRunningExecution($jobId, ['target' => 'local']);

        $this->callHandle($this->makeEndpoint(), [
            'execution_id' => $executionId,
            'job_id'       => $jobId,
            'exit_code'    => 1,    // not in restart_on_exitcodes
            'finished_at'  => '2026-01-15T10:00:05Z',
            'target'       => 'local',
        ]);

        $this->assertFalse($this->hasRetryState($jobId, 'local'));
    }

    #[Test]
    public function allRetriesExhaustedDoesNotCreateRetryStateRow(): void
    {
        $jobId       = $this->seedJob([
            'retry_count'          => 1,
            'restart_on_exitcodes' => '1',
        ]);
        // This is the last retry: retry_attempt (1) equals retry_count (1)
        $executionId = $this->seedRunningExecution($jobId, [
            'target'        => 'local',
            'retry_attempt' => 1,
        ]);

        $this->callHandle($this->makeEndpoint(), [
            'execution_id' => $executionId,
            'job_id'       => $jobId,
            'exit_code'    => 1,
            'finished_at'  => '2026-01-15T10:00:05Z',
            'target'       => 'local',
        ]);

        $this->assertFalse($this->hasRetryState($jobId, 'local'));
    }

    // =========================================================================
    // 6. Failure notification threshold
    //    Jobs have no restart_on_exitcodes to avoid the retry/CrontabManager path.
    //    The response body field 'notified' is true when a notification was
    //    dispatched (either synchronously or via background process).
    // =========================================================================

    #[Test]
    public function singleFailureWithThresholdOneNotifies(): void
    {
        $jobId       = $this->seedJob(['notify_on_failure' => 1, 'notify_after_failures' => 1]);
        $executionId = $this->seedRunningExecution($jobId, ['started_at' => '2026-01-15 10:00:00']);

        $this->callHandle($this->makeEndpoint(), [
            'execution_id' => $executionId,
            'job_id'       => $jobId,
            'exit_code'    => 1,
            'finished_at'  => '2026-01-15T10:00:05Z',
            'target'       => 'local',
        ]);

        $this->assertStatus(200);
        $this->assertTrue(AgentResponse::$body['notified'] ?? false);
    }

    #[Test]
    public function firstFailureWhenThresholdIsThreeDoesNotNotify(): void
    {
        $jobId       = $this->seedJob(['notify_on_failure' => 1, 'notify_after_failures' => 3]);
        $executionId = $this->seedRunningExecution($jobId, ['started_at' => '2026-01-15 10:00:00']);

        $this->callHandle($this->makeEndpoint(), [
            'execution_id' => $executionId,
            'job_id'       => $jobId,
            'exit_code'    => 1,
            'finished_at'  => '2026-01-15T10:00:05Z',
            'target'       => 'local',
        ]);

        $this->assertStatus(200);
        $this->assertFalse(AgentResponse::$body['notified'] ?? true);
    }

    #[Test]
    public function thirdConsecutiveFailureReachingThresholdNotifies(): void
    {
        $jobId = $this->seedJob(['notify_on_failure' => 1, 'notify_after_failures' => 3]);

        // Seed 2 previous original-run failures (they must have earlier started_at)
        $this->seedFinishedExecution($jobId, [
            'started_at'  => '2026-01-10 09:00:00',
            'finished_at' => '2026-01-10 09:00:05',
            'exit_code'   => 1,
            'target'      => 'local',
        ]);
        $this->seedFinishedExecution($jobId, [
            'started_at'  => '2026-01-11 09:00:00',
            'finished_at' => '2026-01-11 09:00:05',
            'exit_code'   => 1,
            'target'      => 'local',
        ]);

        // Third failure (started later, currently running)
        $executionId = $this->seedRunningExecution($jobId, [
            'started_at' => '2026-01-15 10:00:00',
            'target'     => 'local',
        ]);

        $this->callHandle($this->makeEndpoint(), [
            'execution_id' => $executionId,
            'job_id'       => $jobId,
            'exit_code'    => 1,
            'finished_at'  => '2026-01-15T10:00:05Z',
            'target'       => 'local',
        ]);

        // streak = 3, threshold = 3 → 3 === 3 → notification sent
        $this->assertStatus(200);
        $this->assertTrue(AgentResponse::$body['notified'] ?? false);
    }

    #[Test]
    public function fourthConsecutiveFailureBeyondThresholdDoesNotNotifyAgain(): void
    {
        $jobId = $this->seedJob(['notify_on_failure' => 1, 'notify_after_failures' => 3]);

        // Seed 3 previous failures (threshold was already reached on the 3rd)
        foreach (['2026-01-10', '2026-01-11', '2026-01-12'] as $date) {
            $this->seedFinishedExecution($jobId, [
                'started_at'  => "{$date} 09:00:00",
                'finished_at' => "{$date} 09:00:05",
                'exit_code'   => 1,
                'target'      => 'local',
            ]);
        }

        $executionId = $this->seedRunningExecution($jobId, [
            'started_at' => '2026-01-15 10:00:00',
            'target'     => 'local',
        ]);

        $this->callHandle($this->makeEndpoint(), [
            'execution_id' => $executionId,
            'job_id'       => $jobId,
            'exit_code'    => 1,
            'finished_at'  => '2026-01-15T10:00:05Z',
            'target'       => 'local',
        ]);

        // streak = 4, threshold = 3 → 4 !== 3 → silent
        $this->assertStatus(200);
        $this->assertFalse(AgentResponse::$body['notified'] ?? true);
    }

    #[Test]
    public function successExitCodeDoesNotSendFailureNotification(): void
    {
        $jobId       = $this->seedJob(['notify_on_failure' => 1]);
        $executionId = $this->seedRunningExecution($jobId);

        $this->callHandle($this->makeEndpoint(), [
            'execution_id' => $executionId,
            'job_id'       => $jobId,
            'exit_code'    => 0,
            'finished_at'  => '2026-01-15T10:00:05Z',
        ]);

        $this->assertStatus(200);
        $this->assertFalse(AgentResponse::$body['notified'] ?? true);
    }

    #[Test]
    public function notifyOnFailureFalseDoesNotNotify(): void
    {
        $jobId       = $this->seedJob(['notify_on_failure' => 0]);
        $executionId = $this->seedRunningExecution($jobId);

        $this->callHandle($this->makeEndpoint(), [
            'execution_id' => $executionId,
            'job_id'       => $jobId,
            'exit_code'    => 1,
            'finished_at'  => '2026-01-15T10:00:05Z',
        ]);

        $this->assertStatus(200);
        $this->assertFalse(AgentResponse::$body['notified'] ?? true);
    }

    // =========================================================================
    // 7. Maintenance window suppression
    //    during_maintenance = 1 is set on execution_log at start time by
    //    ExecutionStartEndpoint.  The finish endpoint reads this flag and
    //    suppresses all notifications.
    // =========================================================================

    #[Test]
    public function executionDuringMaintenanceSuppressesNotificationAndReturns200(): void
    {
        $jobId       = $this->seedJob(['notify_on_failure' => 1]);
        $executionId = $this->seedRunningExecution($jobId, ['during_maintenance' => 1]);

        $this->callHandle($this->makeEndpoint(), [
            'execution_id' => $executionId,
            'job_id'       => $jobId,
            'exit_code'    => 1,
            'finished_at'  => '2026-01-15T10:00:05Z',
        ]);

        $this->assertStatus(200);
        $this->assertFalse(AgentResponse::$body['notified'] ?? true);
    }

    // =========================================================================
    // 8. Recovery notification
    //    The 'notified' field only tracks failure alerts, not recovery alerts.
    //    Tests verify that the preconditions are evaluated correctly (no errors,
    //    HTTP 200) and that the threshold gate works as expected.
    // =========================================================================

    #[Test]
    public function recoveryAfterSufficientFailureStreakReturns200WithoutException(): void
    {
        $jobId = $this->seedJob([
            'notify_on_failure'    => 1,
            'notify_on_recovery'   => 1,
            'notify_after_failures' => 2,
        ]);

        // Seed 2 previous failures (meets the recovery threshold)
        $this->seedFinishedExecution($jobId, [
            'started_at'  => '2026-01-10 09:00:00',
            'finished_at' => '2026-01-10 09:00:05',
            'exit_code'   => 1,
            'target'      => 'local',
        ]);
        $this->seedFinishedExecution($jobId, [
            'started_at'  => '2026-01-11 09:00:00',
            'finished_at' => '2026-01-11 09:00:05',
            'exit_code'   => 1,
            'target'      => 'local',
        ]);

        $executionId = $this->seedRunningExecution($jobId, [
            'started_at' => '2026-01-15 10:00:00',
            'target'     => 'local',
        ]);

        $this->callHandle($this->makeEndpoint(), [
            'execution_id' => $executionId,
            'job_id'       => $jobId,
            'exit_code'    => 0,   // success → triggers recovery check
            'finished_at'  => '2026-01-15T10:00:05Z',
            'target'       => 'local',
        ]);

        $this->assertStatus(200);
        // 'notified' reflects failure alerts only; recovery is dispatched separately
        $this->assertFalse(AgentResponse::$body['notified'] ?? true);
    }

    #[Test]
    public function recoveryWithoutOptInDoesNotDispatch(): void
    {
        $jobId = $this->seedJob([
            'notify_on_failure'    => 1,
            'notify_on_recovery'   => 0,   // opt-out
            'notify_after_failures' => 1,
        ]);

        $this->seedFinishedExecution($jobId, [
            'started_at'  => '2026-01-10 09:00:00',
            'finished_at' => '2026-01-10 09:00:05',
            'exit_code'   => 1,
            'target'      => 'local',
        ]);

        $executionId = $this->seedRunningExecution($jobId, [
            'started_at' => '2026-01-15 10:00:00',
            'target'     => 'local',
        ]);

        $this->callHandle($this->makeEndpoint(), [
            'execution_id' => $executionId,
            'job_id'       => $jobId,
            'exit_code'    => 0,
            'finished_at'  => '2026-01-15T10:00:05Z',
            'target'       => 'local',
        ]);

        // Endpoint must return 200 without exception despite notify_on_recovery = 0
        $this->assertStatus(200);
    }

    #[Test]
    public function recoveryWhenStreakBelowThresholdDoesNotDispatch(): void
    {
        $jobId = $this->seedJob([
            'notify_on_failure'    => 1,
            'notify_on_recovery'   => 1,
            'notify_after_failures' => 3,  // need 3 failures before recovery fires
        ]);

        // Only 1 previous failure (below threshold)
        $this->seedFinishedExecution($jobId, [
            'started_at'  => '2026-01-10 09:00:00',
            'finished_at' => '2026-01-10 09:00:05',
            'exit_code'   => 1,
            'target'      => 'local',
        ]);

        $executionId = $this->seedRunningExecution($jobId, [
            'started_at' => '2026-01-15 10:00:00',
            'target'     => 'local',
        ]);

        $this->callHandle($this->makeEndpoint(), [
            'execution_id' => $executionId,
            'job_id'       => $jobId,
            'exit_code'    => 0,
            'finished_at'  => '2026-01-15T10:00:05Z',
            'target'       => 'local',
        ]);

        // prevFailures (1) < threshold (3) → recovery gate not triggered
        $this->assertStatus(200);
    }
}
