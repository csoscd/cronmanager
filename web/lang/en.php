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
    'cron_schedule'           => 'Schedule',
    'cron_command'            => 'Command',
    'cron_description'        => 'Description',
    'cron_linux_user'         => 'Linux User',
    'cron_tags'               => 'Tags',
    'cron_active'             => 'Active',
    'cron_inactive'           => 'Inactive',
    'cron_notify_on_failure'  => 'Notify on Failure',
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
    'filter_search'           => 'Search',
    'filter_search_placeholder' => 'Search description or command…',
    'filter_all_users'        => 'All users',
    'filter_all_tags'         => 'All tags',
    'filter_all_hosts'        => 'All hosts',
    'filter_all_targets'      => 'All targets',
    'filter_status'           => 'Status',
    'filter_status_all'       => 'All',
    'filter_status_success'   => 'Success',
    'filter_status_failed'    => 'Failed',
    'filter_status_running'   => 'Running',
    'filter_apply'            => 'Apply',
    'filter_from'             => 'From',
    'filter_to'               => 'To',

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
    'save'                    => 'Save',
    'cancel'                  => 'Cancel',
    'back'                    => 'Back',
    'no_results'              => 'No results found.',
];
