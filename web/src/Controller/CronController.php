<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – Cron Job Controller
 *
 * Handles all CRUD operations for cron jobs, proxying requests through the
 * host agent.  Admin-only actions (create, edit, delete) are enforced at the
 * router level before this controller is invoked.
 *
 * Routes handled:
 *   GET  /crons                – list all jobs (with optional ?tag= / ?user= filters)
 *   GET  /crons/import         – show unmanaged crontab entries for import [admin]
 *   POST /crons/import         – bulk-import selected unmanaged entries [admin]
 *   GET  /crons/new            – show create form  [admin]
 *   POST /crons                – process create form [admin]
 *   GET  /crons/{id}/monitor   – per-job statistics and chart page
 *   GET  /crons/{id}           – show job detail + execution history
 *   GET  /crons/{id}/edit      – show edit form  [admin]
 *   POST /crons/{id}/edit      – process edit form [admin]
 *   POST /crons/{id}/delete    – delete job  [admin]
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

namespace Cronmanager\Web\Controller;

use Cronmanager\Web\Agent\AgentHttpException;
use Cronmanager\Web\Database\Connection;
use Cronmanager\Web\Http\Response;
use Cronmanager\Web\Repository\UserPreferenceRepository;
use Cronmanager\Web\Session\SessionManager;
use Lorisleiva\CronTranslator\CronTranslator;

/**
 * Class CronController
 *
 * All action methods receive the route path parameters as an associative
 * array and return void – output is produced via $this->render().
 */
class CronController extends BaseController
{
    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    /**
     * List all cron jobs, with optional tag and user filters.
     *
     * Query parameters forwarded to the agent:
     *   ?tag=  – filter by tag name
     *   ?user= – filter by linux_user
     *
     * @param array<string,string> $params Path parameters (unused).
     *
     * @return void
     */
    public function index(array $params): void
    {
        $agent = $this->agentClient();

        // filterParam() reads from GET first, falls back to a persistent cookie,
        // and saves the resolved value back to the cookie for next time.
        $filterTag    = $this->filterParam('tag',    'cronmgr_crons_tag');
        $filterUser   = $this->filterParam('user',   'cronmgr_crons_user');
        $filterTarget = $this->filterParam('target', 'cronmgr_crons_target');
        $filterSearch = $this->filterParam('search', 'cronmgr_crons_search');
        $filterResult = $this->filterParam('result', 'cronmgr_crons_result');
        $filterActive = $this->filterParam('active', 'cronmgr_crons_active');

        // ------------------------------------------------------------------
        // Resolve page-size preference
        // ------------------------------------------------------------------

        $userId = SessionManager::getUserId();
        $prefs  = $userId !== null
            ? new UserPreferenceRepository(Connection::getInstance()->getPdo())
            : null;

        // If the request carries an explicit ?limit= value, persist it.
        $rawLimit = $_GET['limit'] ?? null;
        if ($rawLimit !== null && $prefs !== null && $userId !== null) {
            $requestedLimit = (int) $rawLimit;
            $prefs->setCronListPageSize($userId, $requestedLimit);
        }

        // Read effective page size (0 = show all).
        $pageSize = ($prefs !== null && $userId !== null)
            ? $prefs->getCronListPageSize($userId)
            : UserPreferenceRepository::DEFAULT_PAGE_SIZE;

        // Current page (1-based).
        $currentPage = max(1, (int) ($_GET['page'] ?? 1));

        // ------------------------------------------------------------------
        // Fetch all page data from the agent in one parallel batch
        // ------------------------------------------------------------------

        try {
            // One getMultiple() batch instead of up to four sequential
            // roundtrips. The full job list already contains linux_user, tags
            // and targets per job, so the tag/user/target filters are applied
            // locally below instead of re-fetching /crons with query params.
            $results = $agent->getMultiple([
                'crons'   => ['path' => '/crons'],
                'tags'    => ['path' => '/tags'],
                'windows' => ['path' => '/maintenance/windows'],
            ]);
            $allJobs = $results['crons']['data'] ?? [];
            $allTags = $results['tags']['data']  ?? [];
            // Only show tags that are actually in use in the filter dropdown
            $tags = array_values(array_filter($allTags, static fn(array $t): bool => ($t['job_count'] ?? 0) > 0));
        } catch (\RuntimeException $e) {
            $this->logger->error('CronController::index: agent request failed', [
                'message' => $e->getMessage(),
            ]);
            $this->renderError(503, 'error_agent_unavailable', '/crons');
            return;
        }

        // Apply tag / user / target filters locally (same semantics as the
        // agent-side ?tag=&user=&target= query parameters).
        $jobs = $allJobs;
        if ($filterTag !== '') {
            $jobs = array_values(array_filter(
                $jobs,
                static fn(array $job): bool => in_array($filterTag, (array) ($job['tags'] ?? []), strict: true)
            ));
        }
        if ($filterUser !== '') {
            $jobs = array_values(array_filter(
                $jobs,
                static fn(array $job): bool => (string) ($job['linux_user'] ?? '') === $filterUser
            ));
        }
        if ($filterTarget !== '') {
            $jobs = array_values(array_filter(
                $jobs,
                static fn(array $job): bool => in_array($filterTarget, (array) ($job['targets'] ?? []), strict: true)
            ));
        }

        // Build a set of target names that have at least one active maintenance
        // window.  Used in the list template to warn about jobs that will always
        // be skipped (active, run_in_maintenance=false, target in maintenance).
        // The windows result comes from the getMultiple() batch above; failures
        // there would have thrown already, so this is best-effort parsing only.
        $targetsInMaintenance = [];
        foreach ((array) ($results['windows'] ?? []) as $window) {
            if (is_array($window) && !empty($window['active'])) {
                $t = (string) ($window['target'] ?? '');
                if ($t !== '') {
                    $targetsInMaintenance[$t] = true;
                }
            }
        }

        // Apply free-text search filter (description + command, case-insensitive substring)
        if ($filterSearch !== '') {
            $needle = mb_strtolower($filterSearch);
            $jobs   = array_values(array_filter($jobs, static function (array $job) use ($needle): bool {
                $desc    = mb_strtolower((string) ($job['description'] ?? ''));
                $command = mb_strtolower((string) ($job['command']     ?? ''));
                return str_contains($desc, $needle) || str_contains($command, $needle);
            }));
        }

        // Apply active/inactive filter
        if ($filterActive !== '') {
            $wantActive = $filterActive === 'active';
            $jobs       = array_values(array_filter(
                $jobs,
                static fn(array $job): bool => (bool) ($job['active'] ?? false) === $wantActive
            ));
        }

        // Apply last-result filter (ok = exit_code 0, failed = exit_code != 0, not_run = never started)
        if ($filterResult !== '') {
            $jobs = array_values(array_filter($jobs, static function (array $job) use ($filterResult): bool {
                $lastExitCode = isset($job['last_exit_code']) ? (int) $job['last_exit_code'] : null;
                $lastRun      = isset($job['last_run'])       ? $job['last_run']              : null;
                return match ($filterResult) {
                    'ok'      => $lastExitCode !== null && $lastExitCode === 0,
                    'failed'  => $lastExitCode !== null && $lastExitCode !== 0,
                    'not_run' => $lastRun === null,
                    default   => true,
                };
            }));
        }

        // Collect unique users and all unique targets from the full unfiltered list
        $users          = [];
        $allTargets     = [];  // All distinct target values (for filter dropdown)
        foreach ($allJobs as $job) {
            $u = (string) ($job['linux_user'] ?? '');
            if ($u !== '' && !in_array($u, $users, strict: true)) {
                $users[] = $u;
            }
            foreach ((array) ($job['targets'] ?? []) as $t) {
                $t = (string) $t;
                if ($t !== '' && !in_array($t, $allTargets, strict: true)) {
                    $allTargets[] = $t;
                }
            }
        }
        sort($users);
        sort($allTargets);

        // ------------------------------------------------------------------
        // Paginate the filtered jobs array
        // ------------------------------------------------------------------

        $totalJobs  = count($jobs);
        $totalPages = ($pageSize > 0) ? (int) ceil($totalJobs / $pageSize) : 1;
        $totalPages = max(1, $totalPages);

        // Clamp current page to valid range.
        $currentPage = min($currentPage, $totalPages);

        if ($pageSize > 0) {
            $offset    = ($currentPage - 1) * $pageSize;
            $pagedJobs = array_slice($jobs, $offset, $pageSize);
        } else {
            // Show all
            $pagedJobs   = $jobs;
            $currentPage = 1;
        }

        // Annotate each visible job with its human-readable schedule translation
        foreach ($pagedJobs as &$job) {
            $job['schedule_human'] = $this->translateCron((string) ($job['schedule'] ?? ''));
        }
        unset($job);

        $this->render('cron/list.php', $this->translator()->t('crons_title'), [
            'jobs'                  => $pagedJobs,
            'tags'                  => $tags,
            'filterTag'             => $filterTag,
            'filterUser'            => $filterUser,
            'filterTarget'          => $filterTarget,
            'filterSearch'          => $filterSearch,
            'filterResult'          => $filterResult,
            'filterActive'          => $filterActive,
            'users'                 => $users,
            'multiUser'             => count($users) > 1,
            'allTargets'            => $allTargets,
            'isAdmin'               => SessionManager::hasRole('admin'),
            'pageSize'              => $pageSize,
            'currentPage'           => $currentPage,
            'totalJobs'             => $totalJobs,
            'totalPages'            => $totalPages,
            'targetsInMaintenance'  => $targetsInMaintenance,
        ], '/crons');
    }

    /**
     * Show the create-new-job form.
     *
     * When the optional ?copy_from={id} query parameter is present the form is
     * pre-filled with all field values of the referenced job so the user can
     * quickly create a similar job without re-typing everything.
     *
     * @param array<string,string> $params Path parameters (unused).
     *
     * @return void
     */
    public function create(array $params): void
    {
        $agent = $this->agentClient();

        try {
            $tags = $agent->get('/tags')['data'] ?? [];
        } catch (\RuntimeException $e) {
            $this->logger->error('CronController::create: agent request failed', [
                'message' => $e->getMessage(),
            ]);
            $this->renderError(503, 'error_agent_unavailable', '/crons');
            return;
        }

        $returnUrl = trim((string) ($_GET['_return'] ?? ''));
        if ($returnUrl !== '' && !str_starts_with($returnUrl, '/crons')) {
            $returnUrl = '';
        }

        // ------------------------------------------------------------------
        // Load linux users and global SSH hosts from the agent
        // ------------------------------------------------------------------
        [$linuxUsers, $dockerMode, $sshHosts] = $this->fetchLinuxUsersAndSshHosts();

        // ------------------------------------------------------------------
        // Copy mode: pre-fill form from an existing job when ?copy_from is set
        // ------------------------------------------------------------------
        $copyFromId = trim((string) ($_GET['copy_from'] ?? ''));
        $sourceJob  = null;

        if ($copyFromId !== '') {
            try {
                $fetched = $agent->get('/crons/' . rawurlencode($copyFromId));
                if (!empty($fetched)) {
                    $sourceJob = $fetched;
                }
            } catch (\RuntimeException $e) {
                $this->logger->warning('CronController::create: could not fetch source job for copy', [
                    'copy_from' => $copyFromId,
                    'message'   => $e->getMessage(),
                ]);
                // Non-fatal: fall through to a blank form
            }
        }

        // Selected targets come from the source job when copying; default to ['local']
        $selectedTargets = ($sourceJob !== null && isset($sourceJob['targets']) && is_array($sourceJob['targets']) && $sourceJob['targets'] !== [])
            ? $sourceJob['targets']
            : ['local'];

        $isCopy    = $sourceJob !== null;
        $pageTitle = $isCopy
            ? $this->translator()->t('cron_copy_title', ['name' => (string) ($sourceJob['description'] ?? "Job #{$copyFromId}")])
            : $this->translator()->t('cron_add');

        $this->render('cron/form.php', $pageTitle, [
            'job'            => $sourceJob,   // null on blank form, array when copying
            'tags'           => $tags,
            'sshHosts'       => $sshHosts,
            'selectedTargets'=> $selectedTargets,
            'linuxUsers'     => $linuxUsers,
            'dockerMode'     => $dockerMode,
            'error'          => null,
            'isEdit'         => false,        // always POST to /crons (new job)
            'isCopy'         => $isCopy,
            'returnUrl'      => $returnUrl,
        ], '/crons');
    }

    /**
     * Process the create-job form submission.
     *
     * On success: redirects to /crons.
     * On error:   re-renders the form with an error message.
     *
     * @param array<string,string> $params Path parameters (unused).
     *
     * @return void
     */
    public function store(array $params): void
    {
        $data = $this->buildJobPayload($_POST);

        try {
            $this->agentClient()->post('/crons', $data);
        } catch (\RuntimeException $e) {
            $this->logger->warning('CronController::store: failed to create job', [
                'message' => $e->getMessage(),
            ]);

            // Re-render form with error
            try {
                $tags = $this->agentClient()->get('/tags')['data'] ?? [];
            } catch (\RuntimeException) {
                $tags = [];
            }

            [$linuxUsers, $dockerMode, $sshHosts] = $this->fetchLinuxUsersAndSshHosts();

            $postReturn = trim((string) ($_POST['_return'] ?? ''));
            if ($postReturn !== '' && !str_starts_with($postReturn, '/crons')) {
                $postReturn = '';
            }

            $this->render('cron/form.php', $this->translator()->t('cron_add'), [
                'job'        => $_POST,
                'tags'       => $tags,
                'sshHosts'   => $sshHosts,
                'linuxUsers' => $linuxUsers,
                'dockerMode' => $dockerMode,
                'error'      => $e->getMessage(),
                'isEdit'     => false,
                'returnUrl'  => $postReturn,
            ], '/crons');
            return;
        }

        $this->logger->info('CronController::store: job created', [
            'linux_user' => $data['linux_user'] ?? '',
            'schedule'   => $data['schedule']   ?? '',
        ]);

        $returnUrl = trim((string) ($_POST['_return'] ?? ''));
        $safe      = ($returnUrl !== '' && str_starts_with($returnUrl, '/crons')) ? $returnUrl : $this->agentPath('/crons');

        (new Response())->redirect($safe);
    }

    /**
     * Show the detail page for a single job, including execution history.
     *
     * @param array<string,string> $params Path parameters: ['id' => string].
     *
     * @return void
     */
    public function show(array $params): void
    {
        $id    = (string) ($params['id'] ?? '');
        $agent = $this->agentClient();

        // JSON mode: AJAX history refresh from the detail page
        if ($this->isJsonRequest()) {
            $csrfToken = SessionManager::getCsrfToken();
            $isAdmin   = SessionManager::hasRole('admin');
            SessionManager::writeClose();

            try {
                $results = $agent->getMultiple([
                    'job'     => ['path' => '/crons/' . rawurlencode($id)],
                    'history' => ['path' => '/history', 'query' => ['job_id' => $id, 'limit' => 20]],
                ]);
                $job     = $results['job'];
                $history = $results['history']['data'] ?? [];
            } catch (\RuntimeException $e) {
                $this->logger->error('CronController::show (json): agent request failed', [
                    'id'      => $id,
                    'message' => $e->getMessage(),
                ]);
                $this->jsonResponse(['error' => 'agent_unavailable'], 503);
                return;
            }

            $hasRunning = false;
            foreach ($history as $_e) {
                $ec = $_e['exit_code'] ?? null;
                $fa = (string) ($_e['finished_at'] ?? '');
                if ($ec === null && $fa === '') {
                    $hasRunning = true;
                    break;
                }
            }

            $translator = $this->translator();
            $t          = fn(string $k, array $r = []): string => $translator->t($k, $r);
            $csrf_token = $csrfToken;
            $jobId      = $id;

            ob_start();
            include __DIR__ . '/../../templates/cron/_detail_history_rows.php';
            $rowsHtml = (string) ob_get_clean();

            $this->jsonResponse([
                'has_running' => $hasRunning,
                'rows_html'   => $rowsHtml,
            ]);
            return;
        }

        try {
            // One parallel batch instead of two sequential roundtrips
            $results = $agent->getMultiple([
                'job'     => ['path' => '/crons/' . rawurlencode($id)],
                'history' => ['path' => '/history', 'query' => ['job_id' => $id, 'limit' => 20]],
            ]);
            $job     = $results['job'];
            $history = $results['history']['data'] ?? [];
        } catch (\RuntimeException $e) {
            $this->logger->error('CronController::show: agent request failed', [
                'id'      => $id,
                'message' => $e->getMessage(),
            ]);
            $this->renderError(503, 'error_agent_unavailable', '/crons');
            return;
        }

        if (empty($job)) {
            $this->renderError(404, 'error_404', '/crons');
            return;
        }

        $this->render('cron/detail.php', (string) ($job['description'] ?? "Job #{$id}"), [
            'job'           => $job,
            'scheduleHuman' => $this->translateCron((string) ($job['schedule'] ?? '')),
            'history'       => $history,
            'isAdmin'       => SessionManager::hasRole('admin'),
        ], '/crons');
    }

    /**
     * Show the edit form for an existing job.
     *
     * @param array<string,string> $params Path parameters: ['id' => string].
     *
     * @return void
     */
    public function edit(array $params): void
    {
        $id    = (string) ($params['id'] ?? '');
        $agent = $this->agentClient();

        try {
            // One parallel batch instead of two sequential roundtrips
            $results = $agent->getMultiple([
                'job'  => ['path' => '/crons/' . rawurlencode($id)],
                'tags' => ['path' => '/tags'],
            ]);
            $job  = $results['job'];
            $tags = $results['tags']['data'] ?? [];
        } catch (\RuntimeException $e) {
            $this->logger->error('CronController::edit: agent request failed', [
                'id'      => $id,
                'message' => $e->getMessage(),
            ]);
            $this->renderError(503, 'error_agent_unavailable', '/crons');
            return;
        }

        if (empty($job)) {
            $this->renderError(404, 'error_404', '/crons');
            return;
        }

        // Fetch global SSH hosts and linux users from the agent
        [$linuxUsers, $dockerMode, $sshHosts] = $this->fetchLinuxUsersAndSshHosts();

        // Selected targets from job data; fall back to ['local'] for old jobs
        $selectedTargets = isset($job['targets']) && is_array($job['targets']) && $job['targets'] !== []
            ? $job['targets']
            : ['local'];

        // Capture the return URL (e.g. the cron list with active filters) so the
        // form can redirect back to it after a successful save instead of to /crons/{id}.
        $returnUrl = trim((string) ($_GET['_return'] ?? ''));
        // Validate: must be a relative /crons URL to prevent open redirects
        if ($returnUrl !== '' && !str_starts_with($returnUrl, '/crons')) {
            $returnUrl = '';
        }

        $this->render('cron/form.php', $this->translator()->t('cron_edit'), [
            'job'             => $job,
            'tags'            => $tags,
            'sshHosts'        => $sshHosts,
            'selectedTargets' => $selectedTargets,
            'linuxUsers'      => $linuxUsers,
            'dockerMode'      => $dockerMode,
            'error'           => null,
            'isEdit'          => true,
            'returnUrl'       => $returnUrl,
        ], '/crons');
    }

    /**
     * Process the edit-job form submission.
     *
     * On success: redirects to /crons/{id}.
     * On error:   re-renders the form with an error message.
     *
     * @param array<string,string> $params Path parameters: ['id' => string].
     *
     * @return void
     */
    public function update(array $params): void
    {
        $id   = (string) ($params['id'] ?? '');
        $data = $this->buildJobPayload($_POST);

        try {
            $this->agentClient()->put('/crons/' . rawurlencode($id), $data);
        } catch (\RuntimeException $e) {
            $this->logger->warning('CronController::update: failed to update job', [
                'id'      => $id,
                'message' => $e->getMessage(),
            ]);

            try {
                $job  = $this->agentClient()->get('/crons/' . rawurlencode($id));
                $tags = $this->agentClient()->get('/tags')['data'] ?? [];
            } catch (\RuntimeException) {
                $job  = $_POST;
                $tags = [];
            }

            $mergedJob = array_merge((array) $job, $_POST);
            [$linuxUsers, $dockerMode, $sshHosts] = $this->fetchLinuxUsersAndSshHosts();

            $postReturn = trim((string) ($_POST['_return'] ?? ''));
            if ($postReturn !== '' && !str_starts_with($postReturn, '/crons')) {
                $postReturn = '';
            }

            $this->render('cron/form.php', $this->translator()->t('cron_edit'), [
                'job'        => $mergedJob,
                'tags'       => $tags,
                'sshHosts'   => $sshHosts,
                'linuxUsers' => $linuxUsers,
                'dockerMode' => $dockerMode,
                'error'      => $e->getMessage(),
                'isEdit'     => true,
                'returnUrl'  => $postReturn,
            ], '/crons');
            return;
        }

        $this->logger->info('CronController::update: job updated', ['id' => $id]);

        // Redirect back to the list page (preserving any active filters) if a
        // validated return URL was passed through the form, otherwise show detail.
        $returnUrl = trim((string) ($_POST['_return'] ?? ''));
        $safe      = ($returnUrl !== '' && str_starts_with($returnUrl, '/crons')) ? $returnUrl : $this->agentPath('/crons/' . rawurlencode($id));

        (new Response())->redirect($safe);
    }

    /**
     * GET /crons/import — show list of unmanaged crontab entries for a user
     * to allow converting them to managed jobs.
     *
     * Query parameters:
     *   ?user= – Linux user name to inspect (optional; shows user selector when absent)
     *
     * @param array<string,string> $params Path parameters (unused).
     *
     * @return void
     */
    public function importList(array $params): void
    {
        $agent          = $this->agentClient();
        $selectedUser   = trim((string) ($_GET['user']   ?? ''));
        $selectedTarget = trim((string) ($_GET['target'] ?? 'local'));

        if ($selectedUser === '') {
            $selectedUser = null;
        }
        if ($selectedTarget === '') {
            $selectedTarget = 'local';
        }

        // Fetch available SSH targets for the target selector
        $sshTargets = [];
        try {
            $sshTargets = $agent->get('/import/ssh-targets')['data'] ?? [];
        } catch (\RuntimeException $e) {
            $this->logger->error('CronController::importList: failed to fetch SSH targets', [
                'message' => $e->getMessage(),
            ]);
            // Non-fatal: target selector will only show "local"
        }

        // Fetch all Linux users that have any crontab entries on the selected target
        $users = [];
        try {
            $usersParams = $selectedTarget !== 'local' ? ['target' => $selectedTarget] : [];
            $response    = $agent->get('/crons/users', $usersParams);
            $users       = $response['data'] ?? [];
        } catch (\RuntimeException $e) {
            $this->logger->error('CronController::importList: failed to fetch crontab users', [
                'target'  => $selectedTarget,
                'message' => $e->getMessage(),
            ]);
            // Non-fatal: continue with an empty users list
        }

        // Fetch tags for the import form
        $tags = [];
        try {
            $tags = $agent->get('/tags')['data'] ?? [];
        } catch (\RuntimeException $e) {
            $this->logger->error('CronController::importList: failed to fetch tags', [
                'message' => $e->getMessage(),
            ]);
        }

        // If a user is selected, fetch their unmanaged crontab entries
        $unmanagedEntries = [];
        if ($selectedUser !== null) {
            try {
                $unmanagedParams = ['user' => $selectedUser];
                if ($selectedTarget !== 'local') {
                    $unmanagedParams['target'] = $selectedTarget;
                }
                $response         = $agent->get('/crons/unmanaged', $unmanagedParams);
                $unmanagedEntries = $response['data'] ?? [];
            } catch (\RuntimeException $e) {
                $this->logger->error('CronController::importList: failed to fetch unmanaged entries', [
                    'user'    => $selectedUser,
                    'target'  => $selectedTarget,
                    'message' => $e->getMessage(),
                ]);
                $this->renderError(503, 'error_agent_unavailable', '/crons');
                return;
            }
        }

        $this->render('cron/import.php', $this->translator()->t('import_title'), [
            'users'            => $users,
            'selectedUser'     => $selectedUser,
            'selectedTarget'   => $selectedTarget,
            'sshTargets'       => $sshTargets,
            'unmanagedEntries' => $unmanagedEntries,
            'tags'             => $tags,
            'isAdmin'          => SessionManager::hasRole('admin'),
        ], '/crons');
    }

    /**
     * POST /crons/import — bulk-import selected unmanaged entries as managed jobs.
     *
     * Expected POST fields:
     *   user              – Linux user name the entries belong to
     *   schedule[i]       – Cron schedule expression for entry i
     *   command[i]        – Command for entry i
     *   description[i]    – Optional description for entry i
     *   tags[i]           – Comma-separated tag string for entry i
     *   selected[]        – Array of selected indices (as strings)
     *
     * On success: redirects to /crons with a flash message.
     *
     * @param array<string,string> $params Path parameters (unused).
     *
     * @return void
     */
    public function importStore(array $params): void
    {
        $user     = trim((string) ($_POST['user']   ?? ''));
        $target   = trim((string) ($_POST['target'] ?? 'local'));
        $selected = (array) ($_POST['selected'] ?? []);

        // Sanitise target: only allow 'local' or safe SSH alias characters
        if ($target === '' || !preg_match('/^[a-zA-Z0-9._-]+$/', $target)) {
            $target = 'local';
        }

        if ($user === '' || empty($selected)) {
            (new Response())->redirect($this->agentPath('/crons/import'));
            return;
        }

        $schedules    = (array) ($_POST['schedule']    ?? []);
        $commands     = (array) ($_POST['command']     ?? []);
        $descriptions = (array) ($_POST['description'] ?? []);
        $tagsMap      = (array) ($_POST['tags']        ?? []);

        $agent       = $this->agentClient();
        $imported    = 0;
        $errorOccurred = false;

        foreach ($selected as $idx) {
            $idx = (string) $idx;

            // Guard: ensure the index exists in all source arrays
            if (!isset($schedules[$idx], $commands[$idx])) {
                $this->logger->warning('CronController::importStore: missing data for index', [
                    'index' => $idx,
                ]);
                continue;
            }

            $rawTags = (string) ($tagsMap[$idx] ?? '');
            $tags    = array_values(
                array_filter(
                    array_map('trim', explode(',', $rawTags)),
                    static fn(string $t): bool => $t !== '',
                )
            );

            $originalSchedule = trim((string) $schedules[$idx]);
            $originalCommand  = trim((string) $commands[$idx]);

            $payload = [
                'linux_user'        => $user,
                'schedule'          => $originalSchedule,
                'command'           => $originalCommand,
                'description'       => trim((string) ($descriptions[$idx] ?? '')),
                'tags'              => $tags,
                'active'            => true,
                'notify_on_failure'  => false,
                'notify_on_recovery' => false,
                'targets'            => [$target],
                // Tell the agent to comment out the original unmanaged crontab line
                'original_schedule' => $originalSchedule,
                'original_command'  => $originalCommand,
            ];

            try {
                $agent->post('/crons', $payload);
                $imported++;
            } catch (\RuntimeException $e) {
                $this->logger->error('CronController::importStore: failed to create job', [
                    'user'    => $user,
                    'command' => $payload['command'],
                    'message' => $e->getMessage(),
                ]);
                $errorOccurred = true;
            }
        }

        $this->logger->info('CronController::importStore: import completed', [
            'user'     => $user,
            'imported' => $imported,
            'errors'   => $errorOccurred,
        ]);

        // Store a flash message with the import result count
        SessionManager::set('_flash_success', str_replace(
            '{count}',
            (string) $imported,
            $this->translator()->t('import_success')
        ));

        // Redirect back to the import page for the same user/target so the success
        // message is visible immediately and additional entries can be imported.
        $redirect = '/crons/import?user=' . rawurlencode($user);
        if ($target !== 'local') {
            $redirect .= '&target=' . rawurlencode($target);
        }
        (new Response())->redirect($this->agentPath($redirect));
    }

    /**
     * Show the statistics and performance monitor page for a single job.
     *
     * Fetches pre-aggregated stats, chart data and recent execution records
     * from the agent's GET /crons/{id}/monitor endpoint and renders them in
     * the monitor template with Chart.js visualisations.
     *
     * Query parameters:
     *   ?period=  One of: 1h, 6h, 12h, 24h, 7d, 30d (default), 3m, 6m, 1y
     *   ?target=  Optional target filter (e.g. "local" or SSH alias). Only shown
     *             in the UI when the job has more than one configured target.
     *
     * @param array<string,string> $params Path parameters: ['id' => string].
     *
     * @return void
     */
    public function monitor(array $params): void
    {
        $id           = (string) ($params['id'] ?? '');
        $validPeriods = ['1h', '6h', '12h', '24h', '7d', '30d', '3m', '6m', '1y'];
        $period       = trim((string) ($_GET['period'] ?? '30d'));
        if (!in_array($period, $validPeriods, true)) {
            $period = '30d';
        }

        $targetFilter = trim((string) ($_GET['target'] ?? ''));

        $agent = $this->agentClient();

        // JSON polls never write to the session after this point – release the
        // session file lock before the agent I/O so a poll in flight cannot
        // block a concurrently clicked page navigation in the same session.
        if ($this->isJsonRequest()) {
            SessionManager::writeClose();
        }

        $queryParams = ['period' => $period];
        if ($targetFilter !== '') {
            $queryParams['target'] = $targetFilter;
        }

        try {
            $data = $agent->get('/crons/' . rawurlencode($id) . '/monitor', $queryParams);
        } catch (\RuntimeException $e) {
            $this->logger->error('CronController::monitor: agent request failed', [
                'id'      => $id,
                'message' => $e->getMessage(),
            ]);
            $this->renderError(503, 'error_agent_unavailable', '/crons');
            return;
        }

        if (empty($data) || empty($data['job'])) {
            $this->renderError(404, 'error_404', '/crons');
            return;
        }

        $job   = $data['job'];
        $stats = $data['stats'] ?? [];
        $desc  = (string) ($job['description'] ?? "Job #{$id}");

        // JSON mode: return raw data for AJAX period/target switching and auto-refresh
        if ($this->isJsonRequest()) {
            $this->jsonResponse([
                'stats'           => $stats,
                'duration_series' => $data['duration_series'] ?? [],
                'bar_buckets'     => $data['bar_buckets']     ?? [],
                'recent'          => $data['recent']          ?? [],
                'from'            => $data['from']            ?? '',
                'to'              => $data['to']              ?? '',
                'targets'         => $data['targets']         ?? [],
                'selected_target' => $data['selected_target'] ?? null,
            ]);
            return;
        }

        $this->render('cron/monitor.php', $desc . ' – ' . $this->translator()->t('monitor_title'), [
            'job'            => $job,
            'stats'          => $stats,
            'durationSeries' => json_encode($data['duration_series'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'barBuckets'     => json_encode($data['bar_buckets']     ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'recent'         => $data['recent'] ?? [],
            'period'         => $period,
            'validPeriods'   => $validPeriods,
            'fromStr'        => $data['from'] ?? '',
            'toStr'          => $data['to']   ?? '',
            'targets'        => $data['targets']         ?? [],
            'selectedTarget' => $data['selected_target'] ?? null,
        ], '/crons');
    }

    /**
     * Delete a job and redirect to the job list.
     *
     * @param array<string,string> $params Path parameters: ['id' => string].
     *
     * @return void
     */
    /**
     * Handle bulk actions on multiple cron jobs (admin only).
     *
     * Accepted POST fields:
     *   ids[]    – array of job IDs
     *   action   – 'activate' | 'deactivate' | 'delete' | 'tag_add' | 'tag_remove'
     *   tag      – tag name (required for tag_add / tag_remove)
     *
     * @param array<string,string> $params Path parameters (unused).
     *
     * @return void
     */
    public function bulkAction(array $params): void
    {
        $rawIds = isset($_POST['ids']) && is_array($_POST['ids']) ? $_POST['ids'] : [];
        $ids    = array_values(array_filter(array_map('intval', $rawIds), static fn(int $v): bool => $v > 0));
        $action = trim((string) ($_POST['action'] ?? ''));
        $tag    = trim((string) ($_POST['tag']    ?? ''));

        $t = $this->translator();

        if ($ids === []) {
            SessionManager::set('_flash_error', $t->t('bulk_error_agent'));
            (new Response())->redirect($this->agentPath('/crons'));
            return;
        }

        $agent = $this->agentClient();

        try {
            switch ($action) {
                case 'activate':
                    $result = $agent->post('/crons/bulk/status', ['ids' => $ids, 'active' => true]);
                    $msg    = str_replace('{n}', (string) ($result['updated'] ?? count($ids)), $t->t('bulk_flash_activated'));
                    SessionManager::set('_flash_success', $msg);
                    break;

                case 'deactivate':
                    $result = $agent->post('/crons/bulk/status', ['ids' => $ids, 'active' => false]);
                    $msg    = str_replace('{n}', (string) ($result['updated'] ?? count($ids)), $t->t('bulk_flash_deactivated'));
                    SessionManager::set('_flash_success', $msg);
                    break;

                case 'delete':
                    $result = $agent->post('/crons/bulk/delete', ['ids' => $ids]);
                    $msg    = str_replace('{n}', (string) ($result['deleted'] ?? count($ids)), $t->t('bulk_flash_deleted'));
                    SessionManager::set('_flash_success', $msg);
                    break;

                case 'tag_add':
                case 'tag_remove':
                    if ($tag === '') {
                        SessionManager::set('_flash_error', $t->t('bulk_error_no_tag'));
                        (new Response())->redirect($this->agentPath('/crons'));
                        return;
                    }
                    $tagAction = $action === 'tag_add' ? 'add' : 'remove';
                    $result    = $agent->post('/crons/bulk/tag', ['ids' => $ids, 'tag' => $tag, 'action' => $tagAction]);
                    $msg       = str_replace('{n}', (string) ($result['updated'] ?? count($ids)), $t->t('bulk_flash_tagged'));
                    SessionManager::set('_flash_success', $msg);
                    break;

                default:
                    SessionManager::set('_flash_error', $t->t('bulk_error_agent'));
            }
        } catch (AgentHttpException $e) {
            if ($e->getStatusCode() === 409) {
                SessionManager::set('_flash_error', $t->t('bulk_error_running'));
            } else {
                $this->logger->error('CronController::bulkAction: agent error', [
                    'action'  => $action,
                    'status'  => $e->getStatusCode(),
                    'message' => $e->getMessage(),
                ]);
                SessionManager::set('_flash_error', $t->t('bulk_error_agent'));
            }
        } catch (\RuntimeException $e) {
            $this->logger->error('CronController::bulkAction: agent unavailable', ['message' => $e->getMessage()]);
            SessionManager::set('_flash_error', $t->t('bulk_error_agent'));
        }

        (new Response())->redirect($this->agentPath('/crons'));
    }

    public function destroy(array $params): void
    {
        $id = (string) ($params['id'] ?? '');

        try {
            $this->agentClient()->delete('/crons/' . rawurlencode($id));
        } catch (\RuntimeException $e) {
            $this->logger->error('CronController::destroy: failed to delete job', [
                'id'      => $id,
                'message' => $e->getMessage(),
            ]);
            $this->renderError(503, 'error_agent_unavailable', '/crons');
            return;
        }

        $this->logger->info('CronController::destroy: job deleted', ['id' => $id]);

        // Redirect back to the list page preserving any active filters.
        // Validate the return URL to prevent open-redirect: must start with /crons.
        $returnUrl = trim((string) ($_POST['_return'] ?? ''));
        $safe      = ($returnUrl !== '' && str_starts_with($returnUrl, '/crons')) ? $returnUrl : $this->agentPath('/crons');

        (new Response())->redirect($safe);
    }

    /**
     * Schedule a job for immediate (next-minute) execution via the Run Now button.
     *
     * Calls POST /crons/{id}/execute on the agent, which adds a temporary
     * once-only crontab entry with a full-date schedule.  After execution the
     * wrapper script calls the cleanup endpoint to remove the entry.
     *
     * @param array<string,string> $params Path parameters. Expected key: 'id'.
     *
     * @return void
     */
    public function executeNow(array $params): void
    {
        $id = isset($params['id']) ? (int) $params['id'] : 0;

        $this->logger->info('CronController::executeNow: scheduling immediate execution', ['id' => $id]);

        // Optional target subset selected via the multi-target modal.
        $selectedTargets = isset($_POST['targets']) && is_array($_POST['targets'])
            ? array_values(array_filter(array_map('strval', $_POST['targets'])))
            : [];

        $payload = $selectedTargets !== [] ? ['targets' => $selectedTargets] : [];

        try {
            $this->agentClient()->post("/crons/{$id}/execute", $payload);
        } catch (AgentHttpException $e) {
            if ($e->getStatusCode() === 409) {
                // A once-entry is already pending – tell the user instead of showing a generic error.
                $t = $this->translator();
                SessionManager::set('_flash_error', $t->t('execute_now_already_pending'));
                $returnUrl = trim((string) ($_POST['_return'] ?? ''));
                $safe      = ($returnUrl !== '' && str_starts_with($returnUrl, '/crons')) ? $returnUrl : $this->agentPath('/crons');
                (new Response())->redirect($safe);
                return;
            }
            $this->logger->error('CronController::executeNow: agent error', [
                'id'     => $id,
                'status' => $e->getStatusCode(),
                'error'  => $e->getMessage(),
            ]);
            $this->renderError(503, 'error_agent_unavailable', '/crons');
            return;
        } catch (\RuntimeException $e) {
            $this->logger->error('CronController::executeNow: agent error', [
                'id'    => $id,
                'error' => $e->getMessage(),
            ]);
            $this->renderError(503, 'error_agent_unavailable', '/crons');
            return;
        }

        // Redirect back to the referring page (list or detail), validated to
        // prevent open-redirect.
        $returnUrl = trim((string) ($_POST['_return'] ?? ''));
        $safe      = ($returnUrl !== '' && str_starts_with($returnUrl, '/crons')) ? $returnUrl : $this->agentPath('/crons');

        (new Response())->redirect($safe);
    }

    /**
     * Kill a currently-running execution.
     *
     * Calls POST /execution/{id}/kill on the agent, which sends SIGTERM to the
     * running process (local) or SSHes to the remote host to kill it.
     *
     * @param array<string,string> $params Path parameters: ['id' => string] (execution_log ID).
     *
     * @return void
     */
    public function killExecution(array $params): void
    {
        $executionId = isset($params['id']) ? (int) $params['id'] : 0;
        $returnUrl   = trim((string) ($_POST['_return'] ?? ''));
        $safe        = ($returnUrl !== '' && str_starts_with($returnUrl, '/crons')) ? $returnUrl : '/crons';

        $this->logger->info('CronController::killExecution: kill requested', [
            'execution_id' => $executionId,
        ]);

        try {
            $this->agentClient()->post("/execution/{$executionId}/kill", []);
            SessionManager::set('_flash_kill_notice', 'cron_kill_success');
        } catch (AgentHttpException $e) {
            $this->logger->warning('CronController::killExecution: agent returned error', [
                'execution_id' => $executionId,
                'status'       => $e->getStatusCode(),
                'error'        => $e->getMessage(),
            ]);
            $flashKey = match ($e->getStatusCode()) {
                422     => 'cron_kill_no_pid',
                404     => 'cron_kill_already_finished',
                default => 'error_agent_unavailable',
            };
            SessionManager::set('_flash_kill_error', $flashKey);
        } catch (\RuntimeException $e) {
            $this->logger->error('CronController::killExecution: agent unreachable', [
                'execution_id' => $executionId,
                'error'        => $e->getMessage(),
            ]);
            SessionManager::set('_flash_kill_error', 'error_agent_unavailable');
        }

        (new Response())->redirect($safe);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Fetch the linux user list, docker mode flag, and global SSH hosts from the agent.
     *
     * SSH hosts are loaded from /import/ssh-targets (agent-wide, not per user)
     * because SSH connectivity is a property of the agent, not of a Linux user.
     *
     * @return array{0: string[], 1: bool, 2: string[]}
     *         [linuxUsers, dockerMode, sshHosts]
     */
    private function fetchLinuxUsersAndSshHosts(): array
    {
        $linuxUsers = [];
        $dockerMode = false;
        $sshHosts   = [];

        try {
            $response   = $this->agentClient()->getLinuxUsers();
            $linuxUsers = $response['data']        ?? [];
            $dockerMode = (bool) ($response['docker_mode'] ?? false);
        } catch (\RuntimeException) {
            // Non-fatal: fall back to empty list / assume non-Docker
        }

        try {
            $sshHosts = $this->agentClient()->get('/import/ssh-targets')['data'] ?? [];
        } catch (\RuntimeException) {
            // Non-fatal: SSH host list will be empty
        }

        return [$linuxUsers, $dockerMode, $sshHosts];
    }

    /**
     * GET /crons/translate – return the human-readable description of a cron expression.
     *
     * Used by the form's live preview via fetch().
     * Query parameters:
     *   ?expr= – the cron expression to translate
     *
     * Response: application/json  {"human": "Every 5 minutes"}
     *
     * @param array<string,string> $params Path parameters (unused).
     *
     * @return void
     */
    public function translateExpr(array $params): void
    {
        $expr  = trim((string) ($_GET['expr'] ?? ''));
        $human = $expr !== '' ? $this->translateCron($expr) : '';

        header('Content-Type: application/json');
        echo json_encode(['human' => $human], JSON_UNESCAPED_UNICODE); // nosemgrep: php.lang.security.injection.echoed-request.echoed-request
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Translate a cron expression to a human-readable string in the current UI
     * language.  Returns the raw expression unchanged if translation fails
     * (e.g. for @reboot or other non-standard expressions).
     *
     * @param string $expr Cron expression (e.g. "0 3 * * *").
     *
     * @return string Human-readable string, or $expr on failure.
     */
    private function translateCron(string $expr): string
    {
        if ($expr === '') {
            return '';
        }

        // Use the current session language; fall back to English if CronTranslator
        // does not support it (it covers en, fr, de, pt, ru, ar, zh, ja, …).
        $lang = (string) (SessionManager::get('lang') ?? 'en');

        try {
            return CronTranslator::translate($expr, $lang);
        } catch (\Throwable) {
            // Language not supported or invalid expression – retry in English
            try {
                return CronTranslator::translate($expr, 'en');
            } catch (\Throwable) {
                return $expr;
            }
        }
    }

    /**
     * Build a normalised job payload array from raw POST data.
     *
     * Tags are accepted as a comma-separated string and split into an array.
     * Boolean fields (active, notify_on_failure) are treated as checkbox values.
     *
     * @param array<string,mixed> $post Raw POST data (typically $_POST).
     *
     * @return array<string,mixed> Normalised payload ready to be sent to the agent.
     */
    private function buildJobPayload(array $post): array
    {
        // Parse comma-separated tags into a trimmed, filtered array
        $rawTags = (string) ($post['tags'] ?? '');
        $tags    = array_values(
            array_filter(
                array_map('trim', explode(',', $rawTags)),
                static fn(string $t): bool => $t !== '',
            )
        );

        // Build targets array from checkboxes; default to ['local'] if nothing selected
        $rawTargets = isset($post['targets']) && is_array($post['targets']) ? $post['targets'] : [];
        $targets    = array_values(array_filter(
            array_map('trim', $rawTargets),
            static fn(string $t): bool => $t !== '',
        ));
        if ($targets === []) {
            $targets = ['local'];
        }

        // execution_limit_seconds: positive integer or null (no limit)
        $rawLimit = trim((string) ($post['execution_limit_seconds'] ?? ''));
        $executionLimitSeconds = ($rawLimit !== '' && ctype_digit($rawLimit) && (int) $rawLimit > 0)
            ? (int) $rawLimit
            : null;

        // retention_days: positive integer or null (keep forever)
        $rawRetention = trim((string) ($post['retention_days'] ?? ''));
        $retentionDays = ($rawRetention !== '' && ctype_digit($rawRetention) && (int) $rawRetention > 0)
            ? (int) $rawRetention
            : null;

        // retry_count: non-negative integer, default 0
        $rawRetryCount = trim((string) ($post['retry_count'] ?? ''));
        $retryCount = ($rawRetryCount !== '' && ctype_digit($rawRetryCount))
            ? (int) $rawRetryCount
            : 0;

        // retry_delay_minutes: positive integer, default 1
        $rawRetryDelay = trim((string) ($post['retry_delay_minutes'] ?? ''));
        $retryDelayMinutes = ($rawRetryDelay !== '' && ctype_digit($rawRetryDelay) && (int) $rawRetryDelay >= 1)
            ? (int) $rawRetryDelay
            : 1;

        // restart_on_exitcodes: optional expression string; empty → null (any non-zero)
        $rawRestartOnExitcodes = trim((string) ($post['restart_on_exitcodes'] ?? ''));
        $restartOnExitcodes    = $rawRestartOnExitcodes !== '' ? $rawRestartOnExitcodes : null;

        // notify_after_failures: positive integer, default 1
        $rawNotifyAfter = trim((string) ($post['notify_after_failures'] ?? ''));
        $notifyAfterFailures = ($rawNotifyAfter !== '' && ctype_digit($rawNotifyAfter) && (int) $rawNotifyAfter >= 1)
            ? (int) $rawNotifyAfter
            : 1;

        // notify_after_limit_exceeded: positive integer, default 1
        $rawNotifyAfterLimit = trim((string) ($post['notify_after_limit_exceeded'] ?? ''));
        $notifyAfterLimitExceeded = ($rawNotifyAfterLimit !== '' && ctype_digit($rawNotifyAfterLimit) && (int) $rawNotifyAfterLimit >= 1)
            ? (int) $rawNotifyAfterLimit
            : 1;

        // silence_grace_minutes: positive integer or null (use global default)
        $rawSilenceGrace = trim((string) ($post['silence_grace_minutes'] ?? ''));
        $silenceGraceMinutes = ($rawSilenceGrace !== '' && ctype_digit($rawSilenceGrace) && (int) $rawSilenceGrace > 0)
            ? (int) $rawSilenceGrace
            : null;

        return [
            'linux_user'               => trim((string) ($post['linux_user']   ?? '')),
            'schedule'                 => trim((string) ($post['schedule']     ?? '')),
            'command'                  => trim((string) ($post['command']      ?? '')),
            'description'              => trim((string) ($post['description']  ?? '')),
            'tags'                     => $tags,
            'active'                   => isset($post['active']),
            'notify_on_failure'        => isset($post['notify_on_failure']),
            'notify_on_recovery'       => isset($post['notify_on_recovery']),
            'notify_on_silence'        => isset($post['notify_on_silence']),
            'silence_grace_minutes'    => $silenceGraceMinutes,
            'execution_limit_seconds'  => $executionLimitSeconds,
            'auto_kill_on_limit'       => isset($post['auto_kill_on_limit']),
            'singleton'                => isset($post['singleton']),
            'run_in_maintenance'       => isset($post['run_in_maintenance']),
            'retention_days'           => $retentionDays,
            'retry_count'              => $retryCount,
            'retry_delay_minutes'      => $retryDelayMinutes,
            'restart_on_exitcodes'     => $restartOnExitcodes,
            'notify_after_failures'       => $notifyAfterFailures,
            'notify_after_limit_exceeded' => $notifyAfterLimitExceeded,
            'targets'                     => $targets,
        ];
    }
}
