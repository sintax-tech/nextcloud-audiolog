<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * PHPUnit bootstrap.
 *
 * Standalone (default): `composer install` at the repo root, then
 * `composer test`. Uses vendor/autoload.php, which maps OCA\Audiolog\ to lib/
 * and pulls the public OCP\ interfaces from nextcloud/ocp. Classes of other
 * apps the code talks to (Deck) come from tests/stubs/.
 *
 * Inside a Nextcloud checkout (<nc-root>/apps/audiolog/tests) without a
 * local vendor/, falls back to the server's own test bootstrap.
 */
declare(strict_types=1);

$vendorAutoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($vendorAutoload)) {
    require_once $vendorAutoload;
    foreach (glob(__DIR__ . '/stubs/*.php') ?: [] as $stub) {
        require_once $stub;
    }
    return;
}

require_once __DIR__ . '/../../../tests/bootstrap.php';
\OC_App::loadApp('audiolog');
