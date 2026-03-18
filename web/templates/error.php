<?php

declare(strict_types=1);

/**
 * Cronmanager Web UI – Generic Error Page Template
 *
 * Displayed for agent unavailability, 404 not-found conditions and other
 * application-level errors.  Rendered inside the standard layout.
 *
 * Variables available in this template:
 *   int    $errorCode    – HTTP status code (e.g. 404, 500, 503)
 *   string $errorMessage – Human-readable error message (already translated)
 *
 * @author  Christian Schulz <technik@meinetechnikwelt.rocks>
 * @license GNU General Public License version 3 or later
 */

/** @var \Cronmanager\Web\I18n\Translator $translator */
$t = fn(string $k, array $r = []): string => $translator->t($k, $r);

$errorCode    = isset($errorCode)    ? (int)    $errorCode    : 500;
$errorMessage = isset($errorMessage) ? (string) $errorMessage : $t('error_500');
?>

<div class="flex flex-col items-center justify-center py-24 px-4 text-center">
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-12 max-w-lg w-full">

        <!-- Error code -->
        <div class="text-6xl font-bold mb-4 <?= $errorCode === 404 ? 'text-gray-400' : 'text-red-500' ?>">
            <?= htmlspecialchars((string) $errorCode, ENT_QUOTES, 'UTF-8') ?>
        </div>

        <!-- Error message -->
        <p class="text-gray-600 dark:text-gray-300 text-lg mb-8">
            <?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?>
        </p>

        <!-- Back link -->
        <a href="/dashboard"
           class="inline-block bg-blue-600 hover:bg-blue-700 text-white font-medium
                  px-6 py-2.5 rounded-lg text-sm transition focus:outline-none
                  focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
            &larr; <?= htmlspecialchars($t('nav_dashboard'), ENT_QUOTES, 'UTF-8') ?>
        </a>
    </div>
</div>
