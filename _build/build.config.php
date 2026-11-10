<?php
define('PKG_NAME', 'MigrateMODX');
define('PKG_NAME_LOWER', 'migratemodx');
define('PKG_VERSION', '1.3.31');
define('PKG_RELEASE', 'pl');

if (!defined('MODX_CORE_PATH')) {
    $core = getenv('MODX_CORE_PATH');
    if (!$core) {
        foreach (array(dirname(__DIR__), dirname(__DIR__, 2), dirname(__DIR__, 3)) as $dir) {
            if (is_file($dir . '/config.core.php')) {
                require $dir . '/config.core.php';
                break;
            }
        }
    } else {
        define('MODX_CORE_PATH', rtrim($core, '/\\') . '/');
    }
}
if (!defined('MODX_CORE_PATH')) {
    fwrite(STDERR, "MODX core not found. Set MODX_CORE_PATH=/path/to/site/core/\n");
    exit(1);
}
