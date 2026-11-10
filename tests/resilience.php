<?php
/**
 * Resilience / missing-deps tests — no live MODX required.
 * Simulates broken Fenom, exploding services, empty inventory, lint.
 *
 *   php tests/resilience.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$root = dirname(__DIR__);
$failed = 0;
$passed = 0;

function rassert($cond, $msg)
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

echo "=== MigrateMODX resilience ===\n";

/* ---- PHP lint all component PHP ---- */
$lintDirs = array(
    $root . '/core/components/migratemodx',
    $root . '/assets/components/migratemodx',
    $root . '/_build',
);
$phpFiles = array();
foreach ($lintDirs as $dir) {
    if (!is_dir($dir)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && strtolower($f->getExtension()) === 'php') {
            $phpFiles[] = $f->getPathname();
        }
    }
}
rassert(count($phpFiles) >= 15, 'found component PHP files (' . count($phpFiles) . ')');
$lintFail = 0;
foreach ($phpFiles as $file) {
    $out = array();
    $code = 0;
    exec('php -l ' . escapeshellarg($file) . ' 2>&1', $out, $code);
    if ($code !== 0) {
        echo "  LINT: " . implode("\n  ", $out) . "\n";
        $lintFail++;
    }
}
rassert($lintFail === 0, 'php -l clean on all component PHP');

/* ---- No PHP 5.6-incompatible tokens we rely on without require >=7.2 ---- */
$transport = file_get_contents($root . '/_build/build.transport.php');
rassert(strpos($transport, "'php' => '>=7.2'") !== false || strpos($transport, '>=7.2') !== false, 'package requires PHP >=7.2');
rassert(
    preg_match("/'modx'\\s*=>\\s*'>=2\\.6/", $transport) || preg_match("/'modx'\\s*=>\\s*'>=2\\./", $transport),
    'package requires MODX 2.6+'
);

/* ---- Stub modX for offline fullAudit ---- */
class MmxStubEl
{
    protected $d;
    public function __construct(array $d) { $this->d = $d; }
    public function get($k) { return isset($this->d[$k]) ? $this->d[$k] : null; }
}

class MmxStubModx
{
    public $user;
    protected $opts;
    public function __construct(array $opts = array())
    {
        $this->opts = $opts;
        $this->user = new MmxStubEl(array('username' => 'test'));
    }
    public function getOption($k, $skip = null, $default = null)
    {
        return array_key_exists($k, $this->opts) ? $this->opts[$k] : $default;
    }
    public function getVersionData()
    {
        return isset($this->opts['_version']) ? $this->opts['_version'] : array(
            'version' => 2, 'major_version' => 6, 'minor_version' => 5, 'full_version' => '2.6.5-pl',
        );
    }
    public function getCollection($class, $q = null)
    {
        if (!empty($this->opts['_explode_collection'])) {
            throw new RuntimeException('getCollection boom');
        }
        if ($class === 'modChunk' || $class === 'modTemplate') {
            return array(
                new MmxStubEl(array(
                    'id' => 1,
                    'name' => 'brokenFenom',
                    'templatename' => 'brokenFenom',
                    'snippet' => "{if \$x}\nno close",
                    'content' => "{if \$x}\nno close",
                )),
            );
        }
        if ($class === 'modSnippet' || $class === 'modPlugin') {
            return array(
                new MmxStubEl(array(
                    'id' => 2,
                    'name' => 'oldPhp',
                    'snippet' => "<?php \$a{0};",
                    'plugincode' => "<?php \$a{0};",
                )),
            );
        }
        if ($class === 'modNamespace') {
            return array(new MmxStubEl(array('name' => 'pdotools')), new MmxStubEl(array('name' => 'core')));
        }
        return array();
    }
    public function getObject($c, $crit = null) { return null; }
    public function addPackage($a, $b = null) { return true; }
    public function loadClass($a, $b = '', $c = false, $d = false) { return false; }
    public function newQuery($c) { return null; }
    public function getService($name, $class = '', $path = '')
    {
        if (!empty($this->opts['_explode_service'])) {
            throw new Error('Class \'Fenom\\Provider\' not found');
        }
        return null;
    }
}

if (!defined('MODX_CORE_PATH')) {
    $fixture = sys_get_temp_dir() . '/mmx_resilience_' . getmypid() . '/';
    @mkdir($fixture . 'components/pdotools/model/fenom', 0777, true);
    @mkdir($fixture . 'cache/migratemodx', 0777, true);
    /* Broken Fenom: class file exists, Provider does not — factory would fatal without our guard */
    file_put_contents($fixture . 'components/pdotools/model/fenom/Fenom.php', "<?php\n"
        . "class Fenom {\n"
        . "  public static function factory(\$a, \$b = null) {\n"
        . "    if (!class_exists('Fenom\\\\Provider', false)) {\n"
        . "      throw new Error(\"Class 'Fenom\\\\Provider' not found\");\n"
        . "    }\n"
        . "    return new self();\n"
        . "  }\n"
        . "  public function compileCode(\$c, \$n) { return true; }\n"
        . "}\n");
    define('MODX_CORE_PATH', $fixture);
    define('MODX_BASE_PATH', $fixture);
}

$store = MODX_CORE_PATH . 'cache/migratemodx/';
$modx = new MmxStubModx(array(
    'migratemodx_core_path' => $root . '/core/components/migratemodx/',
    'migratemodx_store_path' => $store,
    'core_path' => MODX_CORE_PATH,
    'assets_path' => MODX_BASE_PATH . 'assets/',
    '_version' => array('version' => 2, 'major_version' => 6, 'minor_version' => 5, 'full_version' => '2.6.5-pl'),
));

require_once $root . '/core/components/migratemodx/model/migratemodx/migratemodx.class.php';
$svc = new MigrateMODX($modx);

$report = $svc->fullAudit();
rassert(is_array($report) && isset($report['gate']), 'fullAudit on stub MODX 2.6.5 returns gate');
rassert(isset($report['templates']) && is_array($report['templates']), 'templates section present');
$fenomHit = false;
foreach ($report['templates'] as $t) {
    if (isset($t['name']) && $t['name'] === 'brokenFenom') {
        $fenomHit = true;
    }
}
rassert($fenomHit, 'regex Fenom scan works without Provider');
rassert(!empty($report['packages']), 'namespaces inventory works without transport package class');
rassert(isset($report['environment']['modx_version']), 'environment probed');

/* Exploding getService must not kill audit */
$modxBoom = new MmxStubModx(array(
    'migratemodx_core_path' => $root . '/core/components/migratemodx/',
    'migratemodx_store_path' => $store,
    'core_path' => MODX_CORE_PATH,
    'assets_path' => MODX_BASE_PATH . 'assets/',
    '_explode_service' => true,
));
$svc2 = new MigrateMODX($modxBoom);
$r2 = null;
$err = null;
try {
    $r2 = $svc2->fullAudit();
} catch (Throwable $e) {
    $err = $e->getMessage();
}
rassert($err === null && is_array($r2) && isset($r2['gate']), 'fullAudit survives Fenom\\Provider Error from getService');

/* Exploding collections — still returns report */
$modxBoom2 = new MmxStubModx(array(
    'migratemodx_core_path' => $root . '/core/components/migratemodx/',
    'migratemodx_store_path' => $store,
    'core_path' => MODX_CORE_PATH,
    'assets_path' => MODX_BASE_PATH . 'assets/',
    '_explode_collection' => true,
));
$svc3 = new MigrateMODX($modxBoom2);
$r3 = $svc3->fullAudit();
rassert(isset($r3['gate']), 'fullAudit survives getCollection exceptions');

/* handle() wraps unknown + audit */
$h = $svc->handle('audit', array());
rassert(!empty($h['ok']) && isset($h['report']['gate']), 'handle(audit) ok');
$h2 = $svc->handle('nope', array());
rassert(empty($h2['ok']), 'handle(unknown) fails soft');

/* Template getFenom with fixture Fenom.php but no Provider */
require_once $root . '/core/components/migratemodx/model/migratemodx/mmxtemplatesyntaxscan.class.php';
$scan = new MmxTemplateSyntaxScan($modx);
$ref = new ReflectionClass($scan);
$m = $ref->getMethod('getFenom');
$m->setAccessible(true);
$fenom = null;
try {
    $fenom = $m->invoke($scan);
    $okFenom = true;
} catch (Throwable $e) {
    $okFenom = false;
    echo "  getFenom threw: " . $e->getMessage() . "\n";
}
rassert($okFenom && $fenom === null, 'getFenom returns null when Provider missing (no fatal)');

/* Environment marks 2.6 as warning (not yet 2.8) */
$probe = new MmxEnvironmentProbe($modx);
$env = $probe->run('3.0.0', '8.1');
$codes = array();
foreach ($env['issues'] as $i) {
    $codes[] = $i['code'];
}
rassert(in_array('modx_not_28', $codes, true), '2.6.5 gets modx_not_28 warning');

/* 2.8.9 stub — no modx_not_28 */
$modx28 = new MmxStubModx(array(
    'migratemodx_core_path' => $root . '/core/components/migratemodx/',
    'migratemodx_store_path' => $store,
    'core_path' => MODX_CORE_PATH,
    'assets_path' => MODX_BASE_PATH . 'assets/',
    '_version' => array('version' => 2, 'major_version' => 8, 'minor_version' => 9, 'full_version' => '2.8.9-pl'),
));
$env28 = (new MmxEnvironmentProbe($modx28))->run('3.0.0', '8.1');
$codes28 = array();
foreach ($env28['issues'] as $i) {
    $codes28[] = $i['code'];
}
rassert(!in_array('modx_not_28', $codes28, true), '2.8.9 has no modx_not_28 warning');

/* Recovery activate on stub paths */
$rec = $svc->handle('recovery_status', array());
rassert(!empty($rec['ok']), 'recovery_status ok');

/* Lexicon files parse */
foreach (array('en', 'ru') as $lang) {
    $_lang = array();
    include $root . '/core/components/migratemodx/lexicon/' . $lang . '/default.inc.php';
    rassert(!empty($_lang['migratemodx_admin_run_audit']), "lexicon $lang has run_audit");
    rassert(!empty($_lang['migratemodx_admin_busy_audit']), "lexicon $lang has busy_audit");
    rassert(!empty($_lang['migratemodx_admin_tab_migrate']), "lexicon $lang has tab_migrate");
    rassert(!empty($_lang['migratemodx_admin_migrate_prepare']), "lexicon $lang has migrate_prepare");
}

/* Admin JS must default buttons to type=button */
$js = file_get_contents($root . '/assets/components/migratemodx/mgr/migratemodx-admin.js');
rassert(strpos($js, "tag === 'button'") !== false, 'admin JS forces button type');
rassert(strpos($js, 'mmx-busy-overlay') !== false, 'admin JS has busy overlay');
rassert(strpos($js, 'Object.assign') === false, 'admin JS avoids Object.assign');

/* Connector permission includes packages */
$conn = file_get_contents($root . '/assets/components/migratemodx/connector.php');
rassert(strpos($conn, "hasPermission('packages')") !== false, 'connector allows packages permission');

echo "\n=== Resilience: $passed passed, $failed failed ===\n";
exit($failed ? 1 : 0);
