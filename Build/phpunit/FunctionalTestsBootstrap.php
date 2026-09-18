<?php

declare(strict_types=1);

/*
 * Functional test bootstrap, copied from typo3/testing-framework as recommended.
 * TYPO3_PATH_ROOT is provided by typo3/cms-composer-installers through
 * .Build/vendor/typo3/autoload-include.php; typo3Database* env vars select the DB.
 */

require dirname(__DIR__, 2) . '/.Build/vendor/autoload.php';

(static function (): void {
    $testbase = new \TYPO3\TestingFramework\Core\Testbase();
    $testbase->defineOriginalRootPath();
    $testbase->createDirectory(ORIGINAL_ROOT . 'typo3temp/var/tests');
    $testbase->createDirectory(ORIGINAL_ROOT . 'typo3temp/var/transient');
})();
