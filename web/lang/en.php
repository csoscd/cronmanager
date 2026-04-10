<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – English Language Strings
 *
 * Return value must be a plain PHP array mapping translation keys to strings.
 * Placeholder syntax: {name} is replaced at runtime by the Translator.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

return [
    // -------------------------------------------------------------------------
    // General
    // -------------------------------------------------------------------------
    'app_name'                => 'Cronmanager',

    // -------------------------------------------------------------------------
    // Login page
    // -------------------------------------------------------------------------
    'login_title'             => 'Sign in to Cronmanager',
    'login_username'          => 'Username',
    'login_password'          => 'Password',
    'login_submit'            => 'Sign in',
    'login_sso'               => 'Sign in with SSO',
    'login_or'                => 'or',
    'login_error_credentials' => 'Invalid username or password.',
    'login_error_required'    => 'Username and password are required.',
    'login_error_locked'      => 'Too many failed login attempts. Please try again in {minutes} minute(s).',

    // -------------------------------------------------------------------------
    // Navigation
    // -------------------------------------------------------------------------
    'logout'                  => 'Logout',
    'dark_mode_toggle'        => 'Toggle dark mode',
    'lang_switch'             => 'DE',
    'nav_dashboard'           => 'Dashboard',
    'nav_crons'               => 'Cron Jobs',
    'nav_timeline'            => 'Timeline',
    'nav_swimlane'            => 'Swimlane',
    'nav_export'              => 'Export',
    'nav_users'               => 'Users',
    'nav_maintenance'         => 'Maintenance',

    // -------------------------------------------------------------------------
    // Roles
    // -------------------------------------------------------------------------
    'role_admin'              => 'Admin',
    'role_view'               => 'Viewer',

    // -------------------------------------------------------------------------
    // User management
    // -------------------------------------------------------------------------
    'user_username'           => 'Username',
    'user_role'               => 'Role',
    'user_type'               => 'Type',
    'user_type_local'         => 'Local',
    'user_type_sso'           => 'SSO',
    'user_you'                => 'you',
    'user_make_admin'         => 'Make Admin',
    'user_make_viewer'        => 'Make Viewer',
    'user_delete_confirm'     => 'Are you sure you want to delete this user?',

    // -------------------------------------------------------------------------
    // Error pages
    // -------------------------------------------------------------------------
    'error_403'               => 'Access denied. You do not have permission to view this page.',
    'error_404'               => 'Page not found.',
    'error_500'               => 'An internal error occurred.',
    'error_agent_unavailable' => 'The host agent is currently unavailable. Please try again later.',

    // -------------------------------------------------------------------------
    // Dashboard
    // -------------------------------------------------------------------------
    'dashboard_title'           => 'Dashboard',
    'dashboard_total_jobs'      => 'Total Jobs',
    'dashboard_active'          => 'Active',
    'dashboard_inactive'        => 'Inactive',
    'dashboard_recent_failures' => 'Recent Failures',
    'dashboard_jobs_by_user'    => 'Jobs by User',

    // -------------------------------------------------------------------------
    // Cron jobs
    // -------------------------------------------------------------------------
    'crons_title'             => 'Cron Jobs',
    'cron_add'                => 'Add Job',
    'cron_edit'               => 'Edit',
    'cron_delete'             => 'Delete',
    'cron_copy'               => 'Copy',
    'cron_copy_title'         => 'Copy of: {name}',
    'cron_copy_notice'        => 'Pre-filled from an existing job. Adjust as needed and save to create a new independent job.',
    'cron_delete_confirm'     => 'Are you sure you want to delete this job?',
    'cron_open'               => 'Open',
    'cron_run_now'                => 'Run Now',
    'cron_run_confirm'            => 'Are you sure you want to execute this job now? It will be scheduled for the next minute.',
    'cron_run_select_targets'     => 'Select the targets on which this job should be executed immediately.',
    'cron_run_select_at_least_one' => 'Please select at least one target.',
    'cron_schedule'           => 'Schedule',
    'cron_command'            => 'Command',
    'cron_description'        => 'Description',
    'cron_linux_user'         => 'Linux User',
    'cron_tags'               => 'Tags',
    'cron_active'             => 'Active',
    'cron_inactive'           => 'Inactive',
    'cron_notify_on_failure'        => 'Notify on failure / limit exceeded',
    'cron_execution_limit'          => 'Execution Limit',
    'cron_execution_limit_hint'     => 'Maximum runtime in seconds. Leave empty for no limit.',
    'cron_execution_limit_seconds'  => 'seconds',
    'cron_auto_kill'                => 'Auto-kill on limit exceeded',
    'cron_retention_days'           => 'Log Retention',
    'cron_retention_days_unit'      => 'days',
    'cron_retention_days_hint'      => 'Keep execution logs for this many days. Leave empty to keep forever.',
    'cron_retry'                    => 'Auto-retry on failure',
    'cron_retry_count'              => 'Max retries:',
    'cron_retry_delay'              => 'Delay:',
    'cron_retry_delay_unit'         => 'minutes',
    'cron_retry_hint'               => 'Automatically re-run the job after failure. Notification is sent only after all retries are exhausted. Set retries to 0 to disable.',
    'cron_retry_badge'              => 'Retry {attempt}/{total}',
    'cron_singleton'                => 'Singleton',
    'cron_singleton_hint'           => 'Skip new executions while a previous instance is still running.',
    'cron_kill_running'             => 'Kill Job',
    'cron_kill_confirm'             => 'Are you sure you want to kill this running execution?',
    'cron_kill_success'             => 'Kill signal sent.',
    'cron_kill_no_pid'              => 'Cannot kill automatically: this execution has no PID recorded (it was started before kill support was added). Kill the process manually on the host.',
    'cron_kill_already_finished'    => 'This execution has already finished.',
    'cron_killed_badge'             => 'Killed',
    'cron_limit_exceeded_badge'     => 'Limit exceeded',
    'cron_limit_badge'              => 'Limit: {n}s',
    'cron_created_at'         => 'Created',
    'cron_last_run'           => 'Last Run',
    'cron_never_run'          => 'Never',
    'cron_history'            => 'Execution History',
    'cron_no_jobs'            => 'No cron jobs found.',
    'cron_execution_mode'     => 'Execution Mode',
    'cron_exec_local'         => 'Local (this host)',
    'cron_exec_remote'        => 'Remote (via SSH)',
    'cron_ssh_host'           => 'SSH Host',
    'cron_ssh_host_select'    => '— select a host —',
    'cron_ssh_host_hint'      => 'Host alias from ~/.ssh/config of the Linux user.',
    'cron_remote_badge'       => 'Remote',
    'cron_local_badge'        => 'Local',
    'cron_host'               => 'Host',
    'cron_targets'            => 'Execution Targets',
    'cron_targets_hint'       => 'Select where this job should run. At least one target is required.',
    'cron_crontab_missing'    => 'No crontab entry',
    'cron_crontab_missing_hint' => 'This job is marked active in the database but has no matching crontab entry – it will never run. Edit and re-save the job to restore the entry.',

    // -------------------------------------------------------------------------
    // Timeline
    // -------------------------------------------------------------------------
    'timeline_title'          => 'Timeline',
    'timeline_no_results'     => 'No executions found.',
    'timeline_showing'        => 'Showing {from}–{to} of {total} executions',

    // -------------------------------------------------------------------------
    // Swimlane
    // -------------------------------------------------------------------------
    'swimlane_title'          => 'Schedule Swimlane',
    'swimlane_from'           => 'From',
    'swimlane_to'             => 'To',
    'swimlane_day'            => 'Day',
    'swimlane_tag'            => 'Tag',
    'swimlane_target'         => 'Target',
    'swimlane_all_days'       => 'All days',
    'swimlane_no_results'     => 'No jobs scheduled in this time range.',
    'swimlane_no_results_hint'=> 'Try expanding the hours or switching to "All days".',
    // Info bar / tooltip JS strings
    'swimlane_time_prefix'    => 'Time:',
    'swimlane_day_prefix'     => 'Day:',
    'swimlane_job_singular'   => 'job',
    'swimlane_job_plural'     => 'jobs',
    'swimlane_shown'          => 'shown',
    'swimlane_next_prefix'    => 'Next:',
    'swimlane_at'             => 'at',
    'swimlane_in_prefix'      => 'in',
    'swimlane_min'            => 'min',
    'swimlane_hour_abbr'      => 'h',
    'swimlane_inactive_warning' => '⚠ Job is inactive',
    'sw_schedule'             => 'Schedule',
    'sw_meaning'              => 'Meaning',
    'sw_fires_at'             => 'Fires at',
    'sw_target'               => 'Target',
    'sw_user'                 => 'User',
    'sw_tags'                 => 'Tags',
    'day_monday'              => 'Monday',
    'day_tuesday'             => 'Tuesday',
    'day_wednesday'           => 'Wednesday',
    'day_thursday'            => 'Thursday',
    'day_friday'              => 'Friday',
    'day_saturday'            => 'Saturday',
    'day_sunday'              => 'Sunday',
    // Month names (used for locale-aware date formatting)
    'month_1'                 => 'January',
    'month_2'                 => 'February',
    'month_3'                 => 'March',
    'month_4'                 => 'April',
    'month_5'                 => 'May',
    'month_6'                 => 'June',
    'month_7'                 => 'July',
    'month_8'                 => 'August',
    'month_9'                 => 'September',
    'month_10'                => 'October',
    'month_11'                => 'November',
    'month_12'                => 'December',

    // -------------------------------------------------------------------------
    // Export
    // -------------------------------------------------------------------------
    'export_title'            => 'Export',
    'export_download'         => 'Download Export',
    'export_format'           => 'Format',
    'export_format_crontab'   => 'Crontab (plain text)',
    'export_format_json'      => 'JSON',
    'export_info'             => 'Exports jobs in standard crontab format without wrapper scripts.',

    // -------------------------------------------------------------------------
    // Filters
    // -------------------------------------------------------------------------
    'filter_search'             => 'Search',
    'filter_search_placeholder' => 'Search description or command…',
    'filter_all_users'          => 'All users',
    'filter_all_tags'           => 'All tags',
    'filter_all_hosts'          => 'All hosts',
    'filter_all_targets'        => 'All targets',
    'filter_status'             => 'Status',
    'filter_status_all'         => 'All',
    'filter_status_success'     => 'Success',
    'filter_status_failed'      => 'Failed',
    'filter_status_running'     => 'Running',
    'filter_result'             => 'Last Result',
    'filter_result_all'         => 'All',
    'filter_result_ok'          => 'Ok',
    'filter_result_failed'      => 'Failed',
    'filter_result_not_run'     => 'Not run',
    'filter_apply'              => 'Apply',
    'filter_from'               => 'From',
    'filter_to'                 => 'To',

    // -------------------------------------------------------------------------
    // Pagination
    // -------------------------------------------------------------------------
    'pagination_previous'     => 'Previous',
    'pagination_next'         => 'Next',
    'pagination_page_size'    => 'Per page',
    'pagination_all'          => 'All',
    'pagination_showing'      => 'Showing {from}–{to} of {total} jobs',

    // -------------------------------------------------------------------------
    // Status labels
    // -------------------------------------------------------------------------
    'status_success'          => 'Success',
    'status_failed'           => 'Failed',
    'status_running'          => 'Running',

    // -------------------------------------------------------------------------
    // Initial Setup page
    // -------------------------------------------------------------------------
    'setup_title'            => 'Initial Setup',
    'setup_subtitle'         => 'Create Admin Account',
    'setup_info'             => 'No user accounts exist yet. Create the first administrator account to get started.',
    'setup_username'         => 'Username',
    'setup_password'         => 'Password',
    'setup_password_confirm' => 'Confirm Password',
    'setup_submit'           => 'Create Admin Account',
    'setup_error_username'   => 'Username must be 3–128 characters (letters, numbers, _ - . only).',
    'setup_error_password'   => 'Password must be at least 8 characters.',
    'setup_error_mismatch'   => 'Passwords do not match.',
    'setup_success'          => 'Admin account created. Please sign in.',

    // -------------------------------------------------------------------------
    // Import from Crontab
    // -------------------------------------------------------------------------
    'import_title'           => 'Import from Crontab',
    'import_select_target'   => 'Target',
    'import_select_user'     => 'Select Linux User',
    'import_load'            => 'Load Crontab',
    'import_none_found'      => 'No unmanaged crontab entries found for this user.',
    'import_schedule'        => 'Schedule',
    'import_command'         => 'Command',
    'import_description'     => 'Description (optional)',
    'import_tags'            => 'Tags (comma-separated)',
    'import_submit'          => 'Import Selected',
    'import_select_all'      => 'Select All',
    'import_back'            => 'Back to Cron Jobs',
    'import_success'         => '{count} job(s) imported successfully.',

    // -------------------------------------------------------------------------
    // Monitor page (per-job statistics)
    // -------------------------------------------------------------------------
    'monitor_title'          => 'Monitor',
    'monitor_period'         => 'Period',
    'monitor_success_rate'   => 'Success Rate',
    'monitor_avg_duration'   => 'Avg Duration',
    'monitor_executions'     => 'Executions',
    'monitor_alerts'         => 'Alerts',
    'monitor_duration_chart' => 'Execution Duration',
    'monitor_activity_chart' => 'Executions per Bucket',
    'monitor_recent_title'   => 'Recent Executions',
    'monitor_no_data'        => 'No executions in this period.',
    'monitor_min'            => 'Min',
    'monitor_max'            => 'Max',
    'monitor_seconds'        => 's',
    'monitor_success_label'  => 'Success',
    'monitor_failed_label'   => 'Failed',
    'monitor_notify_enabled' => 'Notifications on',
    'monitor_notify_disabled'=> 'Notifications off',
    'monitor_link'           => 'Monitor',
    'monitor_target'         => 'Target',
    'monitor_all_targets'    => 'All',
    // Period labels shown on the selector buttons
    'monitor_period_1h'      => '1h',
    'monitor_period_6h'      => '6h',
    'monitor_period_12h'     => '12h',
    'monitor_period_24h'     => '24h',
    'monitor_period_7d'      => '7 days',
    'monitor_period_30d'     => '30 days',
    'monitor_period_3m'      => '3 months',
    'monitor_period_6m'      => '6 months',
    'monitor_period_1y'      => '1 year',

    // -------------------------------------------------------------------------
    // Generic / shared
    // -------------------------------------------------------------------------
    'exit_code'               => 'Exit Code',
    'duration'                => 'Duration',
    'output'                  => 'Output',
    'started_at'              => 'Started',
    'finished_at'             => 'Finished',
    'actions'                 => 'Actions',
    'select_all'              => 'Select all',
    'save'                    => 'Save',
    'cancel'                  => 'Cancel',
    'form_tab_basic'          => 'Basic',
    'form_advanced_settings'  => 'Advanced',
    'filter_reset'            => 'Reset',
    'back'                    => 'Back',
    'no_results'              => 'No results found.',

    // -------------------------------------------------------------------------
    // Maintenance page
    // -------------------------------------------------------------------------
    'maintenance_title'                  => 'Maintenance',

    // Crontab resync
    'maintenance_resync_title'           => 'Crontab Sync',
    'maintenance_resync_desc'            => 'Re-writes all crontab entries from the database. Active jobs are synced (entries added / updated); inactive jobs have their entries removed. Use this after a migration or if crontab entries are out of sync.',
    'maintenance_resync_btn'             => 'Sync Now',
    'maintenance_resync_confirm'         => 'Re-sync all crontab entries from the database? This will overwrite any manual crontab edits.',
    'maintenance_resync_success'         => 'Crontab sync complete: {synced} active job(s) synced.',
    'maintenance_resync_error'           => 'Crontab sync failed. Check the agent log for details.',

    // Stuck executions
    'maintenance_stuck_title'            => 'Stuck Executions',
    'maintenance_stuck_desc'             => 'Executions that have been running longer than the configured threshold. These may indicate crashed jobs whose finish event was never received.',
    'maintenance_stuck_hours'            => 'Running for more than',
    'maintenance_stuck_hours_unit'       => 'hours',
    'maintenance_stuck_refresh'          => 'Refresh',
    'maintenance_stuck_none'             => 'No stuck executions found for this threshold.',
    'maintenance_stuck_resolve'          => 'Mark Finished',
    'maintenance_stuck_delete'           => 'Delete',
    'maintenance_stuck_resolve_confirm'  => 'Mark this execution as finished (exit code −1)? It will remain visible in the history.',
    'maintenance_stuck_delete_confirm'   => 'Permanently delete this execution record? This cannot be undone.',
    'maintenance_stuck_resolved'         => 'Execution marked as finished.',
    'maintenance_stuck_deleted'          => 'Execution record deleted.',
    'maintenance_stuck_bulk_resolve'         => 'Mark Finished',
    'maintenance_stuck_bulk_delete'          => 'Delete Selected',
    'maintenance_stuck_selected'             => '{count} selected',
    'maintenance_stuck_bulk_resolve_confirm' => 'Mark {count} execution(s) as finished (exit code −1)?',
    'maintenance_stuck_bulk_delete_confirm'  => 'Permanently delete {count} execution record(s)? This cannot be undone.',
    'maintenance_stuck_bulk_resolved'        => '{count} execution(s) marked as finished.',
    'maintenance_stuck_bulk_deleted'         => '{count} execution record(s) deleted.',

    // History cleanup
    'maintenance_cleanup_title'          => 'History Cleanup',
    'maintenance_cleanup_desc'           => 'Permanently delete finished execution history records older than the specified number of days. Running executions are never deleted.',
    'maintenance_cleanup_older_than'     => 'Delete records older than',
    'maintenance_cleanup_days'           => 'days',
    'maintenance_cleanup_btn'            => 'Clean Up',
    'maintenance_cleanup_confirm'        => 'Delete all finished execution history older than {days} days? This cannot be undone.',
    'maintenance_cleanup_success'        => 'History cleanup complete: {count} record(s) deleted.',

    'maintenance_once_title'             => 'Run Now Cleanup',
    'maintenance_once_desc'              => 'Remove stale once-only crontab entries left behind by Run Now jobs. These entries are normally self-removing after execution, but can remain if the agent was unreachable at cleanup time.',
    'maintenance_once_btn'               => 'Remove Stale Entries',
    'maintenance_once_confirm'           => 'Remove all stale Run Now crontab entries? This only removes temporary schedule lines — no jobs or history records are affected.',
    'maintenance_prune_title'            => 'Log Retention Prune',
    'maintenance_prune_desc'             => 'Immediately apply retention policies: delete finished execution logs older than each job\'s configured retention period, and remove stale retry-state entries. This also runs automatically every night.',
    'maintenance_prune_btn'             => 'Prune logs now',
    'maintenance_prune_confirm'          => 'Delete expired execution logs now? This action cannot be undone.',
    'maintenance_prune_success'          => 'Pruned {logs} log record(s) and {retry_state} stale retry-state entry/entries.',
    'maintenance_prune_error'            => 'Log pruning failed. Check agent logs for details.',
    'maintenance_once_success'           => 'Run Now cleanup complete: {count} stale entry(s) removed.',
    'maintenance_once_none'              => 'No stale Run Now entries found.',

    'maintenance_notify_title'           => 'Notification Test',
    'maintenance_notify_desc'            => 'Send a test message through the configured notification channel to verify that email and/or Telegram alerts are working correctly.',
    'maintenance_notify_mail_btn'        => 'Send Test E-Mail',
    'maintenance_notify_telegram_btn'    => 'Send Test Telegram Message',
    'maintenance_notify_ok'              => 'Test {channel} notification sent successfully.',
    'maintenance_notify_disabled'        => 'The {channel} notification channel is disabled in the agent configuration.',
    'maintenance_notify_error'           => 'The {channel} notification channel is enabled but the test message could not be sent. Check the agent log for details.',
    'maintenance_notify_agent_err'       => 'Could not reach the agent to send the test notification.',

    // -------------------------------------------------------------------------
    // Targets / Maintenance Windows
    // -------------------------------------------------------------------------
    'nav_targets'                    => 'Targets',
    'target_window_new'              => 'New Maintenance Window',
    'target_window_edit'             => 'Edit Maintenance Window',
    'targets_title'                  => 'Targets & Maintenance Windows',
    'targets_desc'                   => 'Define scheduled maintenance windows per target. During an active window, jobs that do not have "Run in maintenance" enabled are skipped (recorded with exit code −4). Jobs with "Run in maintenance" will execute normally but failure notifications are suppressed.',
    'targets_no_windows'             => 'No maintenance windows defined for this target.',
    'targets_add_window'             => 'Add Window',
    'targets_window_schedule'        => 'Schedule',
    'targets_window_duration'        => 'Duration',
    'targets_window_duration_min'    => 'min',
    'targets_window_description'     => 'Description',
    'targets_window_active'          => 'Active',
    'targets_window_edit'            => 'Edit',
    'targets_window_delete'          => 'Delete',
    'targets_window_delete_confirm'  => 'Delete this maintenance window? Jobs for this target will no longer be skipped during this period.',
    'targets_window_form_target'     => 'Target',
    'targets_window_form_schedule'   => 'Cron Schedule (window start)',
    'targets_window_form_duration'   => 'Duration (minutes)',
    'targets_window_form_desc'       => 'Description (optional)',
    'targets_window_form_active'     => 'Active',
    'targets_window_form_save'       => 'Save Window',
    'targets_window_form_cancel'     => 'Cancel',
    'targets_conflict_warning_some'  => 'Some upcoming runs of this job fall within a maintenance window for its target and will be skipped. Runs outside the window execute normally.',
    'targets_conflict_warning_all'   => 'All checked upcoming runs of this job fall within a maintenance window for its target. The job will not execute at all unless the window is adjusted or "Run in maintenance" is enabled.',
    'targets_conflict_warning'       => 'Some upcoming runs of this job may be skipped during a maintenance window for its target. Edit the job to see details.',
    'targets_conflict_badge_all'     => 'This job will not execute – all upcoming runs fall within a maintenance window. Enable "Run in maintenance" or adjust the window.',
    'cron_run_in_maintenance'        => 'Run in maintenance window',
    'cron_run_in_maintenance_hint'   => 'When enabled, the job runs during maintenance windows but failure notifications are suppressed.',
    'cron_maintenance_skipped_badge' => 'Skipped (maintenance)',
    'cron_during_maintenance_badge'  => 'Maintenance',
];
