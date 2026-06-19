<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap: loads all autoloaders so that both production classes
 * (Cronmanager\Agent\*, Cronmanager\Web\*) and test helpers (Tests\*) are
 * available without manual require statements in each test file.
 *
 * Load order:
 *   1. /opt/phplib/vendor/autoload.php – shared runtime libs (Guzzle, Monolog, etc.)
 *      loaded first so that class definitions from phplib are preferred over any
 *      duplicates installed locally.
 *   2. vendor/autoload.php – project vendor: PHPUnit, Mockery, Faker, plus the
 *      PSR-4 maps for Cronmanager\Agent\*, Cronmanager\Web\*, and Tests\*.
 */

// Shared system libraries (must exist on the host / inside the container)
$phplib = '/opt/phplib/vendor/autoload.php';
if (file_exists($phplib)) {
    require_once $phplib;
}

// Project-local vendor (PHPUnit, Mockery, Faker + PSR-4 maps)
$projectVendor = dirname(__DIR__) . '/vendor/autoload.php';
if (!file_exists($projectVendor)) {
    fwrite(
        STDERR,
        "ERROR: vendor/autoload.php not found.\n" .
        "Run `composer install` in the project root first.\n"
    );
    exit(1);
}
require_once $projectVendor;
