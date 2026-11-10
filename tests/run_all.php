<?php
/**
 * Run all MigrateMODX automated suites.
 *
 *   php tests/run_all.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
set_time_limit(0);

$root = dirname(__DIR__);
$suites = array(
    'unit' => $root . '/tests/unit.php',
    'resilience' => $root . '/tests/resilience.php',
    'battle' => $root . '/tests/battle.php',
);

$failed = 0;
foreach ($suites as $name => $file) {
    if (!is_file($file)) {
        echo "[SKIP] $name — missing $file\n";
        continue;
    }
    echo "\n######## RUN $name ########\n";
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file);
    passthru($cmd, $code);
    if ($code !== 0) {
        echo "[SUITE FAIL] $name (exit $code)\n";
        $failed++;
    } else {
        echo "[SUITE OK] $name\n";
    }
}

echo "\n======== OVERALL: " . ($failed ? "$failed suite(s) failed" : 'all suites passed') . " ========\n";
exit($failed ? 1 : 0);
