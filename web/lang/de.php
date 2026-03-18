<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – German Language Strings (Deutsch)
 *
 * Return value must be a plain PHP array mapping translation keys to strings.
 * Placeholder syntax: {name} is replaced at runtime by the Translator.
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

return [
    // -------------------------------------------------------------------------
    // Allgemein
    // -------------------------------------------------------------------------
    'app_name'                => 'Cronmanager',

    // -------------------------------------------------------------------------
    // Login-Seite
    // -------------------------------------------------------------------------
    'login_title'             => 'Bei Cronmanager anmelden',
    'login_username'          => 'Benutzername',
    'login_password'          => 'Passwort',
    'login_submit'            => 'Anmelden',
    'login_sso'               => 'Mit SSO anmelden',
    'login_or'                => 'oder',
    'login_error_credentials' => 'Ungültiger Benutzername oder Passwort.',
    'login_error_required'    => 'Benutzername und Passwort sind erforderlich.',
    'login_error_locked'      => 'Zu viele fehlgeschlagene Anmeldeversuche. Bitte versuchen Sie es in {minutes} Minute(n) erneut.',

    // -------------------------------------------------------------------------
    // Navigation
    // -------------------------------------------------------------------------
    'logout'                  => 'Abmelden',
    'dark_mode_toggle'        => 'Dunkelmodus umschalten',
    'lang_switch'             => 'EN',
    'nav_dashboard'           => 'Dashboard',
    'nav_crons'               => 'Cron-Jobs',
    'nav_timeline'            => 'Zeitachse',
    'nav_export'              => 'Export',
    'nav_users'               => 'Benutzer',

    // -------------------------------------------------------------------------
    // Rollen
    // -------------------------------------------------------------------------
    'role_admin'              => 'Administrator',
    'role_view'               => 'Betrachter',

    // -------------------------------------------------------------------------
    // Benutzerverwaltung
    // -------------------------------------------------------------------------
    'user_username'           => 'Benutzername',
    'user_role'               => 'Rolle',
    'user_type'               => 'Typ',
    'user_type_local'         => 'Lokal',
    'user_type_sso'           => 'SSO',
    'user_you'                => 'du',
    'user_make_admin'         => 'Zum Admin machen',
    'user_make_viewer'        => 'Zum Betrachter machen',
    'user_delete_confirm'     => 'Diesen Benutzer wirklich löschen?',

    // -------------------------------------------------------------------------
    // Fehlerseiten
    // -------------------------------------------------------------------------
    'error_403'               => 'Zugriff verweigert. Sie haben keine Berechtigung, diese Seite aufzurufen.',
    'error_404'               => 'Seite nicht gefunden.',
    'error_500'               => 'Ein interner Fehler ist aufgetreten.',
    'error_agent_unavailable' => 'Der Host-Agent ist derzeit nicht erreichbar. Bitte versuchen Sie es später erneut.',

    // -------------------------------------------------------------------------
    // Dashboard
    // -------------------------------------------------------------------------
    'dashboard_title'           => 'Dashboard',
    'dashboard_total_jobs'      => 'Jobs gesamt',
    'dashboard_active'          => 'Aktiv',
    'dashboard_inactive'        => 'Inaktiv',
    'dashboard_recent_failures' => 'Aktuelle Fehler',
    'dashboard_jobs_by_user'    => 'Jobs nach Benutzer',

    // -------------------------------------------------------------------------
    // Cron-Jobs
    // -------------------------------------------------------------------------
    'crons_title'             => 'Cron-Jobs',
    'cron_add'                => 'Job hinzufügen',
    'cron_edit'               => 'Bearbeiten',
    'cron_delete'             => 'Löschen',
    'cron_delete_confirm'     => 'Möchten Sie diesen Job wirklich löschen?',
    'cron_schedule'           => 'Zeitplan',
    'cron_command'            => 'Befehl',
    'cron_description'        => 'Beschreibung',
    'cron_linux_user'         => 'Linux-Benutzer',
    'cron_tags'               => 'Tags',
    'cron_active'             => 'Aktiv',
    'cron_inactive'           => 'Inaktiv',
    'cron_notify_on_failure'  => 'Bei Fehler benachrichtigen',
    'cron_created_at'         => 'Erstellt',
    'cron_last_run'           => 'Letzter Lauf',
    'cron_never_run'          => 'Nie',
    'cron_history'            => 'Ausführungshistorie',
    'cron_no_jobs'            => 'Keine Cron-Jobs gefunden.',
    'cron_execution_mode'     => 'Ausführungsmodus',
    'cron_exec_local'         => 'Lokal (dieser Host)',
    'cron_exec_remote'        => 'Remote (via SSH)',
    'cron_ssh_host'           => 'SSH-Host',
    'cron_ssh_host_select'    => '— Host auswählen —',
    'cron_ssh_host_hint'      => 'Host-Alias aus ~/.ssh/config des Linux-Benutzers.',
    'cron_remote_badge'       => 'Remote',
    'cron_local_badge'        => 'Lokal',
    'cron_host'               => 'Host',
    'cron_targets'            => 'Ausführungsziele',
    'cron_targets_hint'       => 'Wähle aus, wo dieser Job ausgeführt werden soll. Mindestens ein Ziel ist erforderlich.',

    // -------------------------------------------------------------------------
    // Zeitachse
    // -------------------------------------------------------------------------
    'timeline_title'          => 'Zeitachse',
    'timeline_no_results'     => 'Keine Ausführungen gefunden.',

    // -------------------------------------------------------------------------
    // Export
    // -------------------------------------------------------------------------
    'export_title'            => 'Export',
    'export_download'         => 'Export herunterladen',
    'export_format'           => 'Format',
    'export_format_crontab'   => 'Crontab (Klartext)',
    'export_format_json'      => 'JSON',
    'export_info'             => 'Exportiert Jobs im Standard-Crontab-Format ohne Wrapper-Skripte.',

    // -------------------------------------------------------------------------
    // Filter
    // -------------------------------------------------------------------------
    'filter_search'           => 'Suche',
    'filter_search_placeholder' => 'Beschreibung oder Befehl suchen…',
    'filter_all_users'        => 'Alle Benutzer',
    'filter_all_tags'         => 'Alle Tags',
    'filter_all_hosts'        => 'Alle Hosts',
    'filter_all_targets'      => 'Alle Ziele',
    'filter_status'           => 'Status',
    'filter_status_all'       => 'Alle',
    'filter_status_success'   => 'Erfolgreich',
    'filter_status_failed'    => 'Fehlgeschlagen',
    'filter_status_running'   => 'Läuft',
    'filter_apply'            => 'Anwenden',
    'filter_from'             => 'Von',
    'filter_to'               => 'Bis',

    // -------------------------------------------------------------------------
    // Seitennavigation
    // -------------------------------------------------------------------------
    'pagination_previous'     => 'Zurück',
    'pagination_next'         => 'Weiter',
    'pagination_page_size'    => 'Pro Seite',
    'pagination_all'          => 'Alle',
    'pagination_showing'      => '{from}–{to} von {total} Jobs',

    // -------------------------------------------------------------------------
    // Statusbezeichnungen
    // -------------------------------------------------------------------------
    'status_success'          => 'Erfolgreich',
    'status_failed'           => 'Fehlgeschlagen',
    'status_running'          => 'Läuft',

    // -------------------------------------------------------------------------
    // Ersteinrichtung
    // -------------------------------------------------------------------------
    'setup_title'            => 'Ersteinrichtung',
    'setup_subtitle'         => 'Admin-Konto erstellen',
    'setup_info'             => 'Es existieren noch keine Benutzerkonten. Erstellen Sie das erste Administrator-Konto.',
    'setup_username'         => 'Benutzername',
    'setup_password'         => 'Passwort',
    'setup_password_confirm' => 'Passwort bestätigen',
    'setup_submit'           => 'Admin-Konto erstellen',
    'setup_error_username'   => 'Benutzername muss 3–128 Zeichen lang sein (Buchstaben, Zahlen, _ - . erlaubt).',
    'setup_error_password'   => 'Das Passwort muss mindestens 8 Zeichen lang sein.',
    'setup_error_mismatch'   => 'Die Passwörter stimmen nicht überein.',
    'setup_success'          => 'Admin-Konto erstellt. Bitte anmelden.',

    // -------------------------------------------------------------------------
    // Crontab-Import
    // -------------------------------------------------------------------------
    'import_title'           => 'Aus Crontab importieren',
    'import_select_user'     => 'Linux-Benutzer auswählen',
    'import_load'            => 'Crontab laden',
    'import_none_found'      => 'Keine nicht verwalteten Crontab-Einträge für diesen Benutzer gefunden.',
    'import_schedule'        => 'Zeitplan',
    'import_command'         => 'Befehl',
    'import_description'     => 'Beschreibung (optional)',
    'import_tags'            => 'Tags (kommagetrennt)',
    'import_submit'          => 'Auswahl importieren',
    'import_select_all'      => 'Alle auswählen',
    'import_back'            => 'Zurück zu Cron-Jobs',
    'import_success'         => '{count} Job(s) erfolgreich importiert.',

    // -------------------------------------------------------------------------
    // Allgemein / geteilt
    // -------------------------------------------------------------------------
    'exit_code'               => 'Exit-Code',
    'duration'                => 'Dauer',
    'output'                  => 'Ausgabe',
    'started_at'              => 'Gestartet',
    'finished_at'             => 'Beendet',
    'actions'                 => 'Aktionen',
    'save'                    => 'Speichern',
    'cancel'                  => 'Abbrechen',
    'back'                    => 'Zurück',
    'no_results'              => 'Keine Ergebnisse gefunden.',
];
