<?php
/**
 * PHPUnit bootstrap. Looks at the standard Nextcloud test layout: this app's
 * tests live at <nc-root>/apps/audiolog/tests/, the Nextcloud test bootstrap
 * is at <nc-root>/tests/bootstrap.php. We pull that in so OCP\* and OC are
 * available, then make sure our app classes autoload too.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../tests/bootstrap.php';

\OC_App::loadApp('audiolog');
