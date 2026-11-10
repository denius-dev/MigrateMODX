<?php
/**
 * Battle harness: install transport into sandbox MODX 2.8.8 and exercise MigrateMODX.
 *
 *   php tests/battle.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
set_time_limit(0);

$site = dirname(__DIR__) . '/_sandbox/modx-2.8.8-pl';
$core = $site . '/core/';
$failed = 0;
$passed = 0;

function assert_true($cond, $msg)
{
    global $failed, $passed;
    if ($cond) {
        echo "[PASS] $msg\n";
        $passed++;
    } else {
        echo "[FAIL] $msg\n";
        $failed++;
    }
}

echo "=== MigrateMODX battle tests ===\n";
echo "Site: $site\n";

define('MODX_API_MODE', true);
require_once $core . 'config/config.inc.php';
require_once $core . 'model/modx/modx.class.php';
$modx = new modX();
$modx->initialize('mgr');
$modx->setLogLevel(modX::LOG_LEVEL_ERROR);
$modx->setLogTarget('ECHO');

assert_true(is_object($modx), 'modX initialized');
$ver = $modx->getVersionData();
assert_true(isset($ver['full_version']) && strpos($ver['full_version'], '2.8') === 0, 'MODX is 2.8.x (' . $ver['full_version'] . ')');

$sig = 'migratemodx-1.3.27-pl';
$zip = $core . 'packages/' . $sig . '.transport.zip';
assert_true(is_file($zip), 'Transport zip exists: ' . $zip);

/* Register + install via workspace processors (same path as Package Management) */
echo "Scanning local packages...\n";
$scan = $modx->runProcessor('workspace/packages/scanLocal');
assert_true($scan && !$scan->isError(), 'scanLocal ok' . ($scan && $scan->isError() ? (': ' . $scan->getMessage()) : ''));

$tbl = $modx->getOption('table_prefix', null, 'modx_') . 'transport_packages';
$pkgRow = $modx->query("SELECT signature, installed FROM `{$tbl}` WHERE signature = " . $modx->quote($sig));
$row = $pkgRow ? $pkgRow->fetch(PDO::FETCH_ASSOC) : false;
if (!$row) {
    clearstatcache();
    $scan2 = $modx->runProcessor('workspace/packages/scanLocal');
    assert_true($scan2 && !$scan2->isError(), 'scanLocal retry');
    $pkgRow = $modx->query("SELECT signature, installed FROM `{$tbl}` WHERE signature = " . $modx->quote($sig));
    $row = $pkgRow ? $pkgRow->fetch(PDO::FETCH_ASSOC) : false;
}
assert_true((bool)$row, 'Package registered in DB after scanLocal');

if (empty($row['installed'])) {
    echo "Installing package via processor...\n";
    $inst = $modx->runProcessor('workspace/packages/install', array('signature' => $sig));
    $msg = $inst ? $inst->getMessage() : 'no response';
    assert_true($inst && !$inst->isError(), 'package install ok: ' . $msg);
} else {
    assert_true(true, 'Package already installed');
}

$ns = $modx->getObject('modNamespace', array('name' => 'migratemodx'));
assert_true((bool)$ns, 'Namespace migratemodx exists');

$menu = $modx->getObject('modMenu', array('text' => 'migratemodx'));
assert_true((bool)$menu, 'Menu item migratemodx exists');
if ($menu) {
    assert_true($menu->get('parent') === 'components', 'Menu parent is components (Пакеты)');
    assert_true($menu->get('action') === 'index', 'Menu action is index');
    assert_true($menu->get('namespace') === 'migratemodx', 'Menu namespace is migratemodx');
    assert_true($menu->get('permissions') === 'packages', 'Menu permission is packages');
}

$compCore = $core . 'components/migratemodx/';
$compAssets = $site . '/assets/components/migratemodx/';
assert_true(is_file($compCore . 'model/migratemodx/migratemodx.class.php'), 'Core class installed');
assert_true(is_file($compAssets . 'connector.php'), 'Connector installed');
assert_true(is_file($compAssets . 'recovery/index.php'), 'Recovery console installed');
assert_true(is_file($compAssets . 'mgr/migratemodx-admin.js'), 'Admin JS installed');

require_once $compCore . 'model/migratemodx/migratemodx.class.php';
$svc = new MigrateMODX($modx);

/* Seed broken Fenom chunk + PHP snippet for scanners */
$chunkClass = 'modChunk';
$chunk = $modx->getObject($chunkClass, array('name' => 'mmxBattleFenom'));
if (!$chunk) {
    $chunk = $modx->newObject($chunkClass);
    $chunk->set('name', 'mmxBattleFenom');
}
$chunk->set('snippet', "{if \$foo}\n  broken without close\n{\$bar}");
$chunk->save();

$snip = $modx->getObject('modSnippet', array('name' => 'mmxBattlePhp'));
if (!$snip) {
    $snip = $modx->newObject('modSnippet');
    $snip->set('name', 'mmxBattlePhp');
}
$snip->set('snippet', "<?php\n\$a = array(1,2);\necho \$a{0};\n\$name = 'x';\necho \"\${name}\";\neach(\$a);\n");
$snip->save();

$report = $svc->fullAudit();
assert_true(is_array($report) && !empty($report['gate']), 'fullAudit returns gate');
assert_true(isset($report['packages']), 'audit has packages');
assert_true(isset($report['environment']['modx_version']), 'audit has environment');
assert_true(is_array($report['php']), 'audit has php findings');
assert_true(is_array($report['templates']), 'audit has template findings');

$phpHit = false;
foreach ($report['php'] as $f) {
    if (isset($f['name']) && $f['name'] === 'mmxBattlePhp') {
        $phpHit = true;
        break;
    }
}
assert_true($phpHit, 'PHP scanner found mmxBattlePhp issues');

$fenomHit = false;
foreach ($report['templates'] as $f) {
    if (isset($f['name']) && $f['name'] === 'mmxBattleFenom') {
        $fenomHit = true;
        break;
    }
}
assert_true($fenomHit, 'Template scanner found mmxBattleFenom issues');

/* KB lookup for known packages — inventory may only list installed; check KB file directly */
$kb = json_decode(file_get_contents($compCore . 'data/kb/packages.json'), true);
assert_true(isset($kb['packages']['mscatalyst']), 'KB contains mscatalyst');
assert_true(isset($kb['packages']['seofilter']) && $kb['packages']['seofilter']['status'] === 'incompatible', 'KB marks seofilter incompatible');
assert_true(isset($kb['packages']['minishop2']) && $kb['packages']['minishop2']['status'] === 'replaced_by', 'KB marks minishop2 replaced_by');

$dry = $svc->handle('php_dryrun', array('scope' => 'custom'));
assert_true(!empty($dry['ok']), 'php_dryrun ok');
assert_true(is_array($dry['fixes']), 'php_dryrun returns fixes array');

$apply = $svc->handle('php_apply', array('scope' => 'custom'));
assert_true(!empty($apply['ok']), 'php_apply ok');
assert_true((int)$apply['applied'] >= 1, 'php_apply applied at least one fix');

$snip2 = $modx->getObject('modSnippet', array('name' => 'mmxBattlePhp'));
$code = $snip2 ? (string)$snip2->get('snippet') : '';
assert_true(strpos($code, '$a{0}') === false, 'curly offset removed from snippet');
assert_true(strpos($code, '${name}') === false, 'dollar-brace interp removed from snippet');

$snap = $svc->handle('snapshot_create', array('label' => 'battle'));
assert_true(!empty($snap['ok']), 'snapshot_create ok');
assert_true(!empty($snap['snapshot']['id']), 'snapshot has id');
assert_true(!empty($snap['recovery']['password']), 'recovery password issued');
assert_true(!empty($snap['recovery']['token']), 'recovery token issued');

$verify = $svc->handle('snapshot_verify', array('id' => $snap['snapshot']['id']));
assert_true(!empty($verify['ok']), 'snapshot_verify ok');

$lock = $core . 'cache/migratemodx/recovery.lock';
assert_true(is_file($lock), 'recovery.lock exists');
$shortcut = $site . '/migratemodx-recovery.php';
assert_true(is_file($shortcut), 'recovery shortcut in site root');

$journal = $svc->handle('journal');
assert_true(!empty($journal['ok']) && isset($journal['state']['phase']), 'journal readable');

$plan = $svc->handle('upgrade_plan');
assert_true(!empty($plan['ok']) && !empty($plan['plan']['steps']), 'upgrade_plan has steps');

$health = $svc->handle('health');
assert_true(!empty($health['ok']) && isset($health['health']['php_version']), 'health check runs');

/* migrate_apply_core must refuse without staging/apply confirms (never silent applyDistro) */
$coreRefuse = $svc->handle('migrate_apply_core', array());
assert_true(empty($coreRefuse['ok']), 'migrate_apply_core refused without confirms');
assert_true(stripos((string)(isset($coreRefuse['error']) ? $coreRefuse['error'] : ''), 'confirm') !== false, 'migrate_apply_core mentions confirm');

$status0 = $svc->handle('migrate_status');
assert_true(!empty($status0['ok']), 'migrate_status ok');
assert_true(isset($status0['phase']) && (int)$status0['phase'] >= 1, 'migrate_status has phase');
assert_true(isset($status0['next_actions']) && is_array($status0['next_actions']), 'migrate_status has next_actions');

$goalPhp = $svc->handle('migrate_set_goal', array('goal' => 'php_on_2'));
assert_true(!empty($goalPhp['ok']) && $goalPhp['goal'] === 'php_on_2', 'migrate_set_goal php_on_2');

$prepPhp = $svc->handle('migrate_prepare_php', array('skip_snapshot' => true));
assert_true(isset($prepPhp['run']['goal']) && $prepPhp['run']['goal'] === 'php_on_2', 'prepare_php sets goal');
assert_true(isset($prepPhp['attention']['known']) && isset($prepPhp['attention']['unknown']), 'prepare_php attention buckets');
assert_true(isset($prepPhp['attention']['agent_export']) && strpos($prepPhp['attention']['agent_export'], 'DISCLAIMER') !== false, 'prepare_php agent disclaimer');

$goal3 = $svc->handle('migrate_set_goal', array('goal' => 'modx3'));
assert_true(!empty($goal3['ok']) && $goal3['goal'] === 'modx3', 'migrate_set_goal modx3');

$ms3Refuse = $svc->handle('migrate_apply_ms3_data', array('confirm_ms3' => true));
assert_true(empty($ms3Refuse['ok']), 'migrate_apply_ms3_data refused on MODX 2');

$prep = $svc->handle('migrate_prepare', array('skip_snapshot' => true, 'apply_ms3_data' => true, 'confirm_ms3' => true));
assert_true(isset($prep['run']) && is_array($prep['run']['steps']), 'migrate_prepare returns steps');
assert_true(isset($prep['attention']['done']) && isset($prep['attention']['failed']) && isset($prep['attention']['needs_attention']), 'attention report shape');
assert_true(isset($prep['attention']['known']) && isset($prep['attention']['unknown']), 'attention known/unknown buckets');
assert_true(isset($prep['attention']['schema_notes']['advice']), 'attention has schema notes advice');
assert_true(isset($prep['attention']['packages']) && is_array($prep['attention']['packages']), 'attention has package matrix');
assert_true(isset($prep['attention']['agent_export']) && strpos($prep['attention']['agent_export'], 'DISCLAIMER') !== false, 'agent_export disclaimer');
assert_true(isset($prep['phase']), 'prepare returns phase');
assert_true(isset($prep['goal']) && $prep['goal'] === 'modx3', 'prepare goal modx3');
assert_true(empty($prep['run']['opts']['apply_ms3_data']), 'prepare ignores apply_ms3_data flag');
$ms3Mode = isset($prep['attention']['ms3']['mode']) ? $prep['attention']['ms3']['mode'] : '';
assert_true($ms3Mode === 'dry-run' || $ms3Mode === '', 'prepare ms3 mode is dry-run (not apply)');
$stepIds = array();
foreach ($prep['run']['steps'] as $st) {
    $stepIds[] = $st['id'];
}
assert_true(in_array('full_audit', $stepIds, true) && in_array('package_matrix', $stepIds, true), 'prepare includes audit + matrix');
assert_true(!in_array('apply_core', $stepIds, true), 'prepare does not include apply_core step');
$jrAfterPrep = $svc->handle('journal');
$phase = isset($jrAfterPrep['state']['phase']) ? $jrAfterPrep['state']['phase'] : '';
assert_true($phase !== 'apply', 'prepare did not set journal phase=apply (no applyDistro)');

/* Recovery lock auth check without HTTP */
$lockData = json_decode(file_get_contents($lock), true);
assert_true(!empty($lockData['active']), 'recovery active');
assert_true(password_verify($snap['recovery']['password'], $lockData['password_hash']), 'recovery password verifies');

$deact = $svc->handle('recovery_deactivate');
assert_true(!empty($deact['ok']), 'recovery_deactivate ok');
assert_true(!is_file($shortcut), 'recovery shortcut removed after deactivate');

echo "\n=== Results: $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
