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

    // -------------------------------------------------------------------------
    // Timeline
    // -------------------------------------------------------------------------
    'timeline_title'          => 'Timeline',
    'timeline_no_results'     => 'No executions found.',

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
    'day_monday'              => 'Monday',
    'day_tuesday'             => 'Tuesday',
    'day_wednesday'           => 'Wednesday',
    'day_thursday'            => 'Thursday',
    'day_friday'              => 'Friday',
    'day_saturday'            => 'Saturday',
    'day_sunday'              => 'Sunday',

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
