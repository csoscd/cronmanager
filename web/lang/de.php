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
    'nav_crons'               => 'Alle Jobs',
    'nav_new_job'             => 'Neuer Job',
    'nav_import'              => 'Import',
    'nav_timeline'            => 'Zeitachse',
    'nav_swimlane'            => 'Swimlane',
    'nav_export'              => 'Export',
    'nav_users'               => 'Benutzer',
    'nav_maintenance'         => 'Wartung',
    'nav_housekeeping'        => 'Einstellungen',
    // Sidebar-Abschnittsbeschriftungen
    'nav_section_overview'       => 'Übersicht',
    'nav_section_jobs'           => 'Jobs',
    'nav_section_monitoring'     => 'Monitoring',
    'nav_section_configuration'  => 'Konfiguration',
    'nav_section_admin'          => 'Administration',
    'nav_section_tools'          => 'Tools',

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
    'cron_copy'               => 'Kopieren',
    'cron_copy_title'         => 'Kopie von: {name}',
    'cron_copy_notice'        => 'Felder aus einem bestehenden Job vorausgefüllt. Anpassen und speichern, um einen neuen eigenständigen Job zu erstellen.',
    'cron_delete_confirm'     => 'Möchten Sie diesen Job wirklich löschen?',
    'cron_open'                    => 'Öffnen',
    'cron_run_now'                 => 'Jetzt ausführen',
    'cron_run_confirm'             => 'Möchten Sie diesen Job jetzt ausführen? Er wird für die nächste Minute eingeplant.',
    'cron_run_select_targets'      => 'Wählen Sie die Ziele, auf denen dieser Job sofort ausgeführt werden soll.',
    'cron_run_select_at_least_one' => 'Bitte mindestens ein Ziel auswählen.',
    'cron_schedule'           => 'Zeitplan',
    'cron_command'            => 'Befehl',
    'cron_description'        => 'Beschreibung',
    'cron_linux_user'         => 'Linux-Benutzer',
    'cron_tags'               => 'Tags',
    'cron_active'             => 'Aktiv',
    'cron_inactive'           => 'Inaktiv',
    'cron_notify_on_failure'        => 'Benachrichtigungen bei Fehler / Limit-Überschreitung aktivieren',
    'cron_notify_on_failure_hint'   => 'Alerts senden, wenn dieser Job fehlschlägt oder das Laufzeitlimit überschreitet.',
    'cron_notify_on_recovery'       => 'Wiederherstellungs-Benachrichtigung senden',
    'cron_notify_on_recovery_hint'  => 'Eine Benachrichtigung senden, wenn der Job nach einer Fehlerserie, die einen Alert ausgelöst hat, wieder erfolgreich läuft. Erfordert aktivierte Fehlerbenachrichtigungen.',
    'cron_execution_limit'          => 'Ausführungslimit',
    'cron_execution_limit_hint'     => 'Maximale Laufzeit in Sekunden. Leer lassen für kein Limit.',
    'cron_execution_limit_seconds'  => 'Sekunden',
    'cron_auto_kill'                => 'Bei Limit-Überschreitung automatisch beenden',
    'cron_retention_days'           => 'Log-Aufbewahrung',
    'cron_retention_days_unit'      => 'Tage',
    'cron_retention_days_hint'      => 'Ausführungsprotokolle für diese Anzahl Tage aufbewahren. Leer lassen für unbegrenzte Aufbewahrung.',
    'cron_retry'                    => 'Automatischer Neustart bei Fehler',
    'cron_retry_count'              => 'Max. Versuche:',
    'cron_retry_delay'              => 'Wartezeit:',
    'cron_retry_delay_unit'         => 'Minuten',
    'cron_retry_hint'               => 'Job nach einem Fehler automatisch neu starten. Benachrichtigung wird erst nach Ausschöpfung aller Versuche gesendet. 0 Versuche = deaktiviert.',
    'cron_retry_exitcodes'          => 'Neustart bei Exit-Codes:',
    'cron_retry_exitcodes_placeholder' => 'z.B. 1-5,10,255',
    'cron_retry_exitcodes_hint'     => 'Kommagetrennte Werte und Bereiche (0–255). Leer = jeder Exit-Code ungleich 0 löst einen Neustart aus.',
    'cron_notify_after_failures'            => 'Benachrichtigen nach N aufeinanderfolgenden Fehlern',
    'cron_notify_after_failures_unit'       => 'aufeinanderfolgende Fehler',
    'cron_notify_after_failures_hint'       => 'Fehlerbenachrichtigung erst nach dieser Anzahl aufeinanderfolgender Fehler senden (Standard: 1 = bei jedem Fehler). Nach der Benachrichtigung werden keine weiteren Alerts gesendet, bis der Job erfolgreich läuft.',
    'cron_notify_after_limit_exceeded'      => 'Benachrichtigen nach N aufeinanderfolgenden Limit-Überschreitungen',
    'cron_notify_after_limit_exceeded_unit' => 'aufeinanderfolgende Limit-Überschreitungen',
    'cron_notify_after_limit_exceeded_hint' => 'Limit-Benachrichtigung erst nach dieser Anzahl aufeinanderfolgender Laufzeitüberschreitungen senden (Standard: 1 = jedes Mal). Nach der Benachrichtigung werden keine weiteren Alerts gesendet, bis eine Ausführung innerhalb des Limits abgeschlossen wird.',
    'cron_retry_badge'              => 'Versuch {attempt}/{total}',
    'cron_singleton'                => 'Singleton',
    'cron_singleton_hint'           => 'Neue Ausführungen überspringen, solange eine vorherige Instanz noch läuft.',
    'cron_kill_running'             => 'Job beenden',
    'cron_kill_confirm'             => 'Möchten Sie diese laufende Ausführung wirklich beenden?',
    'cron_kill_success'             => 'Beendigungssignal gesendet.',
    'cron_kill_no_pid'              => 'Automatisches Beenden nicht möglich: Für diese Ausführung wurde keine PID gespeichert (sie wurde gestartet, bevor die Kill-Funktion hinzugefügt wurde). Beenden Sie den Prozess manuell auf dem Host.',
    'cron_kill_already_finished'    => 'Diese Ausführung ist bereits abgeschlossen.',
    'cron_killed_badge'             => 'Beendet',
    'cron_limit_exceeded_badge'     => 'Limit überschritten',
    'cron_limit_badge'              => 'Limit: {n}s',
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
    'cron_crontab_missing'    => 'Kein Crontab-Eintrag',
    'cron_crontab_missing_hint' => 'Dieser Job ist in der Datenbank als aktiv markiert, hat aber keinen passenden Crontab-Eintrag – er wird nie ausgeführt. Bearbeite und speichere den Job erneut, um den Eintrag wiederherzustellen.',

    // -------------------------------------------------------------------------
    // Zeitachse
    // -------------------------------------------------------------------------
    'timeline_title'          => 'Zeitachse',
    'timeline_no_results'     => 'Keine Ausführungen gefunden.',
    'timeline_showing'        => '{from}–{to} von {total} Ausführungen',
    'timeline_direct_link_notice' => 'Ergebnisse aus einem direkten Link. Gespeicherte Filtereinstellungen wurden nicht angewendet. Klicke auf „Anwenden", um diese Filter zu speichern.',

    // -------------------------------------------------------------------------
    // Swimlane
    // -------------------------------------------------------------------------
    'swimlane_title'          => 'Zeitplan-Swimlane',
    'swimlane_from'           => 'Von',
    'swimlane_to'             => 'Bis',
    'swimlane_day'            => 'Tag',
    'swimlane_tag'            => 'Tag',
    'swimlane_target'         => 'Ziel',
    'swimlane_all_days'       => 'Alle Tage',
    'swimlane_no_results'     => 'Keine Jobs im gewählten Zeitbereich geplant.',
    'swimlane_no_results_hint'=> 'Stunden erweitern oder „Alle Tage" wählen.',
    // Info-Leiste / Tooltip JS-Strings
    'swimlane_time_prefix'    => 'Zeit:',
    'swimlane_day_prefix'     => 'Tag:',
    'swimlane_job_singular'   => 'Job',
    'swimlane_job_plural'     => 'Jobs',
    'swimlane_shown'          => 'angezeigt',
    'swimlane_next_prefix'    => 'Nächster:',
    'swimlane_at'             => 'um',
    'swimlane_in_prefix'      => 'in',
    'swimlane_min'            => 'Min.',
    'swimlane_hour_abbr'      => 'Std.',
    'swimlane_inactive_warning' => '⚠ Job ist inaktiv',
    'sw_schedule'             => 'Zeitplan',
    'sw_meaning'              => 'Bedeutung',
    'sw_fires_at'             => 'Startet um',
    'sw_target'               => 'Ziel',
    'sw_user'                 => 'Benutzer',
    'sw_tags'                 => 'Tags',
    'day_monday'              => 'Montag',
    'day_tuesday'             => 'Dienstag',
    'day_wednesday'           => 'Mittwoch',
    'day_thursday'            => 'Donnerstag',
    'day_friday'              => 'Freitag',
    'day_saturday'            => 'Samstag',
    'day_sunday'              => 'Sonntag',
    // Monatsnamen (für lokalisierte Datumsformatierung)
    'month_1'                 => 'Januar',
    'month_2'                 => 'Februar',
    'month_3'                 => 'März',
    'month_4'                 => 'April',
    'month_5'                 => 'Mai',
    'month_6'                 => 'Juni',
    'month_7'                 => 'Juli',
    'month_8'                 => 'August',
    'month_9'                 => 'September',
    'month_10'                => 'Oktober',
    'month_11'                => 'November',
    'month_12'                => 'Dezember',

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
    'filter_search'             => 'Suche',
    'filter_search_placeholder' => 'Beschreibung oder Befehl suchen…',
    'filter_all_users'          => 'Alle Benutzer',
    'filter_all_tags'           => 'Alle Tags',
    'filter_all_hosts'          => 'Alle Hosts',
    'filter_all_targets'        => 'Alle Ziele',
    'filter_status'             => 'Status',
    'filter_status_all'         => 'Alle',
    'filter_status_success'     => 'Erfolgreich',
    'filter_status_failed'      => 'Fehlgeschlagen',
    'filter_status_running'     => 'Läuft',
    'filter_result'             => 'Letztes Ergebnis',
    'filter_result_all'         => 'Alle',
    'filter_result_ok'          => 'Ok',
    'filter_result_failed'      => 'Fehlgeschlagen',
    'filter_result_not_run'     => 'Nicht gestartet',
    'filter_active'             => 'Status',
    'filter_active_all'         => 'Alle',
    'filter_active_yes'         => 'Aktiv',
    'filter_active_no'          => 'Inaktiv',
    'filter_apply'              => 'Anwenden',
    'filter_from'               => 'Von',
    'filter_to'                 => 'Bis',

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
    'import_select_target'   => 'Ziel',
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
    // Monitor-Seite (Job-Statistiken)
    // -------------------------------------------------------------------------
    'monitor_title'          => 'Monitor',
    'monitor_period'         => 'Zeitraum',
    'monitor_success_rate'   => 'Erfolgsrate',
    'monitor_avg_duration'   => 'Ø Dauer',
    'monitor_executions'     => 'Ausführungen',
    'monitor_alerts'         => 'Benachrichtigungen',
    'monitor_duration_chart' => 'Ausführungsdauer',
    'monitor_activity_chart' => 'Ausführungen pro Zeitabschnitt',
    'monitor_recent_title'   => 'Letzte Ausführungen',
    'monitor_no_data'        => 'Keine Ausführungen in diesem Zeitraum.',
    'monitor_min'            => 'Min',
    'monitor_max'            => 'Max',
    'monitor_seconds'        => 's',
    'monitor_success_label'  => 'Erfolgreich',
    'monitor_failed_label'   => 'Fehlgeschlagen',
    'monitor_notify_enabled' => 'Benachrichtigungen aktiv',
    'monitor_notify_disabled'=> 'Benachrichtigungen inaktiv',
    'monitor_link'           => 'Monitor',
    'monitor_target'         => 'Ziel',
    'monitor_all_targets'    => 'Alle',
    // Zeitraum-Schaltflächen
    'monitor_period_1h'      => '1 Std.',
    'monitor_period_6h'      => '6 Std.',
    'monitor_period_12h'     => '12 Std.',
    'monitor_period_24h'     => '24 Std.',
    'monitor_period_7d'      => '7 Tage',
    'monitor_period_30d'     => '30 Tage',
    'monitor_period_3m'      => '3 Monate',
    'monitor_period_6m'      => '6 Monate',
    'monitor_period_1y'      => '1 Jahr',

    // -------------------------------------------------------------------------
    // Allgemein / geteilt
    // -------------------------------------------------------------------------
    'exit_code'               => 'Exit-Code',
    'duration'                => 'Dauer',
    'output'                  => 'Ausgabe',
    'started_at'              => 'Gestartet',
    'finished_at'             => 'Beendet',
    'actions'                 => 'Aktionen',
    'select_all'              => 'Alle auswählen',
    'save'                    => 'Speichern',
    'cancel'                  => 'Abbrechen',
    'form_tab_basic'          => 'Grundeinstellungen',
    'form_advanced_settings'  => 'Erweitert',
    'form_tab_notify'         => 'Benachrichtigung',
    'filter_reset'            => 'Zurücksetzen',
    'back'                    => 'Zurück',

    // ── Agent management ──────────────────────────────────────────────────────
    'agents_title'               => 'Agents',
    'agents_desc'                => 'Verwalte die Remote-Agents, die Cronjobs auf verschiedenen Hosts überwachen.',
    'agents_none'                => 'Noch kein Agent konfiguriert.',
    'agent_add'                  => 'Agent hinzufügen',
    'agent_status'               => 'Status',
    'agent_status_online'        => 'Online',
    'agent_status_offline'       => 'Offline',
    'agent_disabled'             => 'deaktiviert',
    'agent_delete_confirm'       => 'Diesen Agent löschen?',
    'agent_delete_last'          => 'Der letzte Agent kann nicht gelöscht werden.',
    'agent_saved'                => 'Agent gespeichert.',
    'agent_switch'               => 'Agent',
    'agent_create_title'         => 'Neuen Agent anlegen',
    'agent_edit_title'           => 'Agent bearbeiten',
    'agent_name'                 => 'Name',
    'agent_description'          => 'Beschreibung',
    'agent_url'                  => 'URL',
    'agent_hmac_secret'          => 'HMAC-Secret',
    'agent_secret_leave_blank'   => 'leer lassen, um beizubehalten',
    'agent_secret_toggle'        => 'Secret anzeigen/verbergen',
    'agent_timeout'              => 'Timeout',
    'agent_sort_order'           => 'Reihenfolge',
    'agent_ssl_verify'           => 'SSL-Zertifikat prüfen',
    'agent_ssl_ca_bundle'        => 'CA-Bundle-Pfad',
    'agent_enabled'              => 'Aktiv',
    'agent_test_connection'      => 'Verbindung testen',
    'agent_test_ok'              => 'Verbindung erfolgreich',
    'agent_test_failed'          => 'Verbindung fehlgeschlagen',
    'agent_error_name_required'  => 'Der Name darf nicht leer sein.',
    'agent_error_name_too_long'  => 'Der Name darf maximal 100 Zeichen lang sein.',
    'agent_error_url_required'   => 'Die URL darf nicht leer sein.',
    'agent_error_url_invalid'    => 'Die URL muss mit http:// oder https:// beginnen.',
    'agent_error_secret_required'=> 'Das HMAC-Secret darf nicht leer sein.',

    // -------------------------------------------------------------------------
    // Job-Transfer zwischen Agents
    // -------------------------------------------------------------------------
    'bulk_transfer'                 => 'Transferieren',
    'transfer_title'                => 'Jobs transferieren',
    'transfer_jobs_selected'        => '{n} Job(s) zum Transferieren ausgewählt',
    'transfer_source'               => 'Quell-Agent',
    'transfer_destination'          => 'Ziel-Agent',
    'transfer_source_action'        => 'Quell-Jobs nach Transfer',
    'transfer_keep'                 => 'Behalten (Kopie)',
    'transfer_deactivate'           => 'Deaktivieren',
    'transfer_delete'               => 'Löschen',
    'transfer_compatibility'        => 'Kompatibilität',
    'transfer_warning_targets'      => 'Warnung: Folgende Targets am Ziel-Agent nicht verfügbar:',
    'transfer_target_missing'       => 'Target „{target}" nicht verfügbar',
    'transfer_target_ok'            => 'Kompatibel',
    'transfer_btn'                  => 'Transfer starten',
    'transfer_flash_done'           => '{n} Job(s) erfolgreich transferiert.',
    'transfer_flash_partial'        => '{n} Job(s) transferiert, {m} fehlgeschlagen.',
    'transfer_flash_error'          => 'Transfer fehlgeschlagen.',
    'transfer_error_no_destination' => 'Kein weiterer Agent konfiguriert. Bitte zuerst einen zweiten Agent anlegen.',
    'transfer_error_same_agent'     => 'Quell- und Ziel-Agent sind identisch.',
    'transfer_error_no_ids'         => 'Keine Jobs ausgewählt.',

    // -------------------------------------------------------------------------
    // Massenaktionen (Job-Liste)
    // -------------------------------------------------------------------------
    'bulk_selected'           => '{n} Job(s) ausgewählt',
    'bulk_activate'           => 'Aktivieren',
    'bulk_deactivate'         => 'Deaktivieren',
    'bulk_delete'             => 'Löschen',
    'bulk_retag'              => 'Tag ändern',
    'bulk_deselect'           => 'Auswahl aufheben',
    'bulk_tag_add'            => 'Tag hinzufügen',
    'bulk_tag_remove'         => 'Tag entfernen',
    'bulk_tag_select'         => 'Tag auswählen…',
    'bulk_confirm_delete'     => '{n} ausgewählte(n) Job(s) löschen? Dies kann nicht rückgängig gemacht werden.',
    'bulk_confirm_yes'        => 'Ja, löschen',
    'bulk_confirm_cancel'     => 'Abbrechen',
    'bulk_flash_activated'    => '{n} Job(s) aktiviert.',
    'bulk_flash_deactivated'  => '{n} Job(s) deaktiviert.',
    'bulk_flash_deleted'      => '{n} Job(s) gelöscht.',
    'bulk_flash_tagged'       => 'Tag bei {n} Job(s) aktualisiert.',
    'bulk_error_running'      => 'Löschen nicht möglich: Mindestens ein ausgewählter Job hat eine laufende Ausführung.',
    'bulk_error_agent'        => 'Massenaktion fehlgeschlagen: Agent nicht erreichbar.',
    'bulk_error_no_tag'       => 'Bitte einen Tag auswählen.',
    'no_results'              => 'Keine Ergebnisse gefunden.',
    'output_copy'             => 'Kopieren',
    'output_copied'           => 'Kopiert!',
    'output_copy_title'       => 'Ausgabe in Zwischenablage kopieren',
    'output_download'         => 'Herunterladen',
    'output_download_title'   => 'Ausgabe als Logdatei herunterladen',

    // -------------------------------------------------------------------------
    // Verwaltungsseite (ehemals "Wartung")
    // -------------------------------------------------------------------------
    'housekeeping_title'                 => 'Verwaltung',
    'maintenance_title'                  => 'Verwaltung',

    // Crontab-Synchronisation
    'maintenance_resync_title'           => 'Crontab-Synchronisation',
    'maintenance_resync_desc'            => 'Schreibt alle Crontab-Einträge aus der Datenbank neu. Aktive Jobs werden synchronisiert (Einträge hinzugefügt / aktualisiert); inaktive Jobs erhalten ihre Einträge entfernt. Verwenden Sie dies nach einer Migration oder wenn Crontab-Einträge nicht mehr aktuell sind.',
    'maintenance_resync_btn'             => 'Jetzt synchronisieren',
    'maintenance_resync_confirm'         => 'Alle Crontab-Einträge aus der Datenbank neu synchronisieren? Manuelle Crontab-Änderungen werden dabei überschrieben.',
    'maintenance_resync_success'         => 'Crontab-Synchronisation abgeschlossen: {synced} aktive(r) Job(s) synchronisiert.',
    'maintenance_resync_error'           => 'Crontab-Synchronisation fehlgeschlagen. Prüfen Sie das Agent-Protokoll.',

    // Hängende Ausführungen
    'maintenance_stuck_title'            => 'Hängende Ausführungen',
    'maintenance_stuck_desc'             => 'Ausführungen, die länger als der konfigurierte Schwellenwert laufen. Dies kann auf abgestürzte Jobs hinweisen, deren Abschluss-Ereignis nie empfangen wurde.',
    'maintenance_stuck_hours'            => 'Läuft seit mehr als',
    'maintenance_stuck_hours_unit'       => 'Stunden',
    'maintenance_stuck_refresh'          => 'Aktualisieren',
    'maintenance_stuck_none'             => 'Keine hängenden Ausführungen für diesen Schwellenwert gefunden.',
    'maintenance_stuck_resolve'          => 'Als beendet markieren',
    'maintenance_stuck_delete'           => 'Löschen',
    'maintenance_stuck_resolve_confirm'  => 'Diese Ausführung als beendet markieren (Exit-Code −1)? Der Eintrag bleibt in der Historie sichtbar.',
    'maintenance_stuck_delete_confirm'   => 'Diesen Ausführungseintrag dauerhaft löschen? Dies kann nicht rückgängig gemacht werden.',
    'maintenance_stuck_resolved'         => 'Ausführung als beendet markiert.',
    'maintenance_stuck_deleted'          => 'Ausführungseintrag gelöscht.',
    'maintenance_stuck_bulk_resolve'         => 'Als beendet markieren',
    'maintenance_stuck_bulk_delete'          => 'Auswahl löschen',
    'maintenance_stuck_selected'             => '{count} ausgewählt',
    'maintenance_stuck_bulk_resolve_confirm' => '{count} Ausführung(en) als beendet markieren (Exit-Code −1)?',
    'maintenance_stuck_bulk_delete_confirm'  => '{count} Ausführungseintrag/-einträge dauerhaft löschen? Dies kann nicht rückgängig gemacht werden.',
    'maintenance_stuck_bulk_resolved'        => '{count} Ausführung(en) als beendet markiert.',
    'maintenance_stuck_bulk_deleted'         => '{count} Ausführungseintrag/-einträge gelöscht.',

    // Historien-Bereinigung
    'maintenance_cleanup_title'          => 'Historien-Bereinigung',
    'maintenance_cleanup_desc'           => 'Löscht dauerhaft abgeschlossene Ausführungsprotokoll-Einträge, die älter als die angegebene Anzahl von Tagen sind. Laufende Ausführungen werden nie gelöscht.',
    'maintenance_cleanup_older_than'     => 'Einträge älter als löschen',
    'maintenance_cleanup_days'           => 'Tage',
    'maintenance_cleanup_btn'            => 'Bereinigen',
    'maintenance_cleanup_confirm'        => 'Alle abgeschlossenen Ausführungsprotokoll-Einträge älter als {days} Tage löschen? Dies kann nicht rückgängig gemacht werden.',
    'maintenance_cleanup_success'        => 'Historien-Bereinigung abgeschlossen: {count} Eintrag/Einträge gelöscht.',

    'maintenance_once_title'             => 'Run-Now-Bereinigung',
    'maintenance_once_desc'              => 'Entfernt veraltete Einmal-Crontab-Einträge, die von Run-Now-Jobs hinterlassen wurden. Diese Einträge entfernen sich normalerweise nach der Ausführung selbst, können aber verbleiben, wenn der Agent beim Bereinigungsaufruf nicht erreichbar war.',
    'maintenance_once_btn'               => 'Veraltete Einträge entfernen',
    'maintenance_once_confirm'           => 'Alle veralteten Run-Now-Crontab-Einträge entfernen? Es werden nur temporäre Zeitplanzeilen entfernt – keine Jobs oder Historiendaten werden verändert.',
    'maintenance_prune_title'            => 'Log-Aufbewahrung: Bereinigung',
    'maintenance_prune_desc'             => 'Aufbewahrungsrichtlinien sofort anwenden: Abgeschlossene Ausführungsprotokolle löschen, die älter als das konfigurierte Aufbewahrungszeitraum des jeweiligen Jobs sind, sowie veraltete Neustart-Status-Einträge entfernen. Läuft auch automatisch jede Nacht.',
    'maintenance_prune_btn'             => 'Logs jetzt bereinigen',
    'maintenance_prune_confirm'          => 'Abgelaufene Ausführungsprotokolle jetzt löschen? Diese Aktion kann nicht rückgängig gemacht werden.',
    'maintenance_prune_success'          => '{logs} Protokoll-Einträge und {retry_state} veraltete Neustart-Status-Einträge bereinigt.',
    'maintenance_prune_error'            => 'Log-Bereinigung fehlgeschlagen. Bitte Agent-Logs prüfen.',
    'maintenance_once_success'           => 'Run-Now-Bereinigung abgeschlossen: {count} veralteter Eintrag/Einträge entfernt.',
    'maintenance_once_none'              => 'Keine veralteten Run-Now-Einträge gefunden.',

    'maintenance_notify_title'           => 'Benachrichtigungstest',
    'maintenance_notify_desc'            => 'Sendet eine Testnachricht über den konfigurierten Benachrichtigungskanal, um zu prüfen ob E-Mail- und/oder Telegram-Benachrichtigungen korrekt funktionieren.',
    'maintenance_notify_mail_btn'        => 'Test-E-Mail senden',
    'maintenance_notify_telegram_btn'    => 'Test-Telegramnachricht senden',
    'maintenance_notify_ok'             => 'Test-{channel}-Benachrichtigung erfolgreich gesendet.',
    'maintenance_notify_disabled'        => 'Der {channel}-Benachrichtigungskanal ist in der Agentenkonfiguration deaktiviert.',
    'maintenance_notify_error'           => 'Der {channel}-Benachrichtigungskanal ist aktiviert, aber die Testnachricht konnte nicht gesendet werden. Details im Agenten-Log.',
    'maintenance_notify_agent_err'       => 'Der Agent konnte für den Benachrichtigungstest nicht erreicht werden.',

    // -------------------------------------------------------------------------
    // Wartungsseite (Wartungsfenster — ehemals "Targets")
    // -------------------------------------------------------------------------
    'nav_targets'                    => 'Wartung',
    'target_window_new'              => 'Neues Wartungsfenster',
    'target_window_edit'             => 'Wartungsfenster bearbeiten',
    'maintenance_windows_title'      => 'Wartungsfenster',
    'maintenance_windows_desc'       => 'Definiert geplante Wartungsfenster pro Target. Während eines aktiven Fensters werden Jobs, die "Im Wartungsfenster ausführen" nicht aktiviert haben, übersprungen (exit-Code −4). Jobs mit aktivierter Option werden normal ausgeführt, aber Fehlerbenachrichtigungen werden unterdrückt.',
    'maintenance_agent_target_name'  => 'Cronmanager Agent',
    'maintenance_agent_target_badge' => 'agent',
    'maintenance_agent_target_desc'  => 'Fenster für den Cronmanager Agent blockieren ALLE Job-Ausführungen, unabhängig von der per-Job-Einstellung "Im Wartungsfenster ausführen". Verwende dies für host-weite Wartungsperioden (z. B. VM-Suspend/Resume-Zyklen).',
    'targets_title'                  => 'Wartungsfenster',
    'targets_desc'                   => 'Definiert geplante Wartungsfenster pro Target. Während eines aktiven Fensters werden Jobs, die "Im Wartungsfenster ausführen" nicht aktiviert haben, übersprungen (exit-Code −4). Jobs mit aktivierter Option werden normal ausgeführt, aber Fehlerbenachrichtigungen werden unterdrückt.',
    'targets_no_windows'             => 'Keine Wartungsfenster für dieses Target definiert.',
    'targets_add_window'             => 'Fenster hinzufügen',
    'targets_window_schedule'        => 'Zeitplan',
    'targets_window_duration'        => 'Dauer',
    'targets_window_duration_min'    => 'Min.',
    'targets_window_description'     => 'Beschreibung',
    'targets_window_active'          => 'Aktiv',
    'targets_window_edit'            => 'Bearbeiten',
    'targets_window_delete'          => 'Löschen',
    'targets_window_delete_confirm'  => 'Dieses Wartungsfenster löschen? Jobs für dieses Target werden während dieses Zeitraums nicht mehr übersprungen.',
    'targets_window_form_target'     => 'Target',
    'targets_window_form_schedule'   => 'Cron-Zeitplan (Fensterbeginn)',
    'targets_window_form_duration'   => 'Dauer (Minuten)',
    'targets_window_form_desc'       => 'Beschreibung (optional)',
    'targets_window_form_active'     => 'Aktiv',
    'targets_window_form_save'       => 'Fenster speichern',
    'targets_window_form_cancel'     => 'Abbrechen',
    'targets_conflict_warning_some'  => 'Einige geplante Ausführungen dieses Jobs fallen in ein Wartungsfenster des Targets und werden übersprungen. Ausführungen außerhalb des Fensters laufen normal.',
    'targets_conflict_warning_all'   => 'Alle geprüften geplanten Ausführungen dieses Jobs fallen in ein Wartungsfenster des Targets. Der Job wird nicht ausgeführt, sofern das Fenster nicht angepasst oder "Im Wartungsfenster ausführen" aktiviert wird.',
    'targets_conflict_warning'       => 'Einige geplante Ausführungen dieses Jobs könnten während eines Wartungsfensters übersprungen werden. Bearbeiten Sie den Job für Details.',
    'targets_conflict_badge_all'     => 'Dieser Job wird nicht ausgeführt – alle geplanten Ausführungen fallen in ein Wartungsfenster. "Im Wartungsfenster ausführen" aktivieren oder das Fenster anpassen.',
    'targets_ssh_test'               => 'Testen',
    'targets_ssh_test_ok'            => 'Verbunden',
    'targets_ssh_test_failed'        => 'Fehlgeschlagen',
    'targets_ssh_test_testing'       => 'Teste…',
    'cron_run_in_maintenance'        => 'Im Wartungsfenster ausführen',
    'cron_run_in_maintenance_hint'   => 'Wenn aktiviert, läuft der Job auch während Wartungsfenstern, aber Fehlerbenachrichtigungen werden unterdrückt.',
    'cron_maintenance_skipped_badge' => 'Übersprungen (Wartung)',
    'cron_during_maintenance_badge'  => 'Wartung',
    'cron_interrupted_badge'         => 'Unterbrochen',
];
