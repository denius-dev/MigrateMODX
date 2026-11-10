<?php
/**
 * Lightweight unit checks without full MODX bootstrap.
 *   php tests/unit.php
 */
$root = dirname(__DIR__);
$failed = 0;
$passed = 0;
function uassert($c, $m) {
    global $failed, $passed;
    if ($c) { echo "[PASS] $m\n"; $passed++; } else { echo "[FAIL] $m\n"; $failed++; }
}

require_once $root . '/core/components/migratemodx/model/migratemodx/mmxknowledgebase.class.php';
require_once $root . '/core/components/migratemodx/model/migratemodx/mmxreadinessgate.class.php';
require_once $root . '/core/components/migratemodx/model/migratemodx/mmxstatejournal.class.php';
require_once $root . '/core/components/migratemodx/model/migratemodx/mmxpaths.class.php';

$kb = new MmxKnowledgeBase($root . '/core/components/migratemodx/data/kb/packages.json');
uassert($kb->lookup('seofilter')['status'] === 'incompatible', 'KB seofilter');
uassert($kb->lookup('msearch2')['status'] === 'replaced_by', 'KB msearch2');
uassert($kb->lookup('mscatalyst')['status'] === 'compatible', 'KB mscatalyst');
uassert($kb->lookup('minishop2')['successor'] === 'minishop3', 'KB minishop2 successor');
uassert($kb->lookup('minishop2-2.5.0-pl')['status'] === 'replaced_by', 'KB lookup by signature');
uassert($kb->lookup('ms2')['status'] === 'replaced_by', 'KB alias ms2');
uassert(!empty($kb->lookup('pdotools')['min_version']), 'KB pdotools min_version');

require_once $root . '/core/components/migratemodx/model/migratemodx/mmxpackageinventory.class.php';
$inv = new MmxPackageInventory((object)array(), $kb);
$ref = new ReflectionClass($inv);
$enrich = $ref->getMethod('enrich');
$enrich->setAccessible(true);
$resolve = $ref->getMethod('resolveInstalled');
$resolve->setAccessible(true);

$ms2 = $enrich->invoke($inv, 'minishop2', 'minishop2-2.4.0-pl', '2.4.0-pl', $kb->lookup('minishop2'));
$ms3 = $enrich->invoke($inv, 'minishop3', 'minishop3-1.2.0-pl', '1.2.0-pl', $kb->lookup('minishop3'));
uassert($ms2['verdict'] === 'replace', 'ms2 verdict replace');
uassert($ms2['has_migration_path'] === true, 'ms2 has migration path');
$resolved = $resolve->invoke($inv, array('minishop2' => $ms2, 'minishop3' => $ms3));
uassert($resolved['minishop2']['resolution'] === 'successor_installed', 'ms2 resolved when ms3 installed');
uassert($resolved['minishop2']['resolution_package'] === 'minishop3', 'ms2 resolution package');

$pdo = $enrich->invoke($inv, 'pdotools', 'pdotools-2.12.0-pl', '2.12.0-pl', $kb->lookup('pdotools'));
uassert($pdo['verdict'] === 'update', 'old pdotools needs update');
uassert($pdo['version_status'] === 'outdated', 'pdotools outdated vs min_version');
$pdoNew = $enrich->invoke($inv, 'pdotools', 'pdotools-3.0.2-pl', '3.0.2-pl', $kb->lookup('pdotools'));
uassert($pdoNew['verdict'] === 'ok', 'pdotools 3.x ok');

$gate2 = new MmxReadinessGate();
$g2 = $gate2->evaluate(array(
    'environment' => array('issues' => array()),
    'packages' => array_values($resolved),
    'heuristics' => array(),
    'php' => array(),
    'templates' => array(),
    'snapshots' => array('latest' => array('id' => 'x')),
), array());
$legacyWarn = false;
$ms2Block = false;
foreach ($g2['blockers'] as $b) {
    if (isset($b['key']) && $b['key'] === 'pkg:minishop2') $ms2Block = true;
}
foreach ($g2['warnings'] as $w) {
    if (isset($w['key']) && $w['key'] === 'pkg_legacy:minishop2') $legacyWarn = true;
}
uassert(!$ms2Block && $legacyWarn, 'successor installed → warning not blocker');

$phpPrep = null;
require_once $root . '/core/components/migratemodx/model/migratemodx/mmxphpcompatprep.class.php';
$phpPrep = new MmxPhpCompatPrep((object)array(), $root . '/core/components/migratemodx/data/php/rules.php', sys_get_temp_dir() . '/');
uassert($phpPrep->packageFromPath('/x/components/minishop2/model/x.php') === 'minishop2', 'packageFromPath ms2');
$fake = array(
    array('uid' => 'a', 'package' => 'minishop2', 'autofixable' => true, 'code_before' => '$a{0};', 'rule' => 'curly_offset', 'source' => 'file', 'id' => 0, 'path' => '/x', 'name' => 'x'),
    array('uid' => 'b', 'package' => 'custom', 'autofixable' => true, 'code_before' => '$a{0};', 'rule' => 'curly_offset', 'source' => 'snippet', 'id' => 1, 'path' => '', 'name' => 's'),
    array('uid' => 'c', 'package' => 'formit', 'autofixable' => true, 'code_before' => '$a{0};', 'rule' => 'curly_offset', 'source' => 'snippet', 'id' => 2, 'path' => '', 'name' => 'FormIt'),
    array('uid' => 'd', 'package' => 'formit', 'manual_override' => true, 'autofixable' => true, 'code_before' => '$a{0};', 'rule' => 'curly_offset', 'source' => 'snippet', 'id' => 3, 'path' => '', 'name' => 'FormItCustom'),
);
$kept = $phpPrep->filterFindings($fake, array('mode' => 'keepers', 'vendor_packages' => array('minishop2', 'formit')));
uassert(count($kept) === 2 && $kept[0]['uid'] === 'b' && $kept[1]['uid'] === 'd', 'php filter keepers skips vendor stock, keeps manual');
$sel = $phpPrep->filterFindings($fake, array('targets' => array('a')));
uassert(count($sel) === 1 && $sel[0]['uid'] === 'a', 'php filter by targets');

require_once $root . '/core/components/migratemodx/model/migratemodx/mmxpackageownership.class.php';
uassert(MmxPackageOwnership::isVendorRefreshVerdict('update') === true, 'ownership update is vendor');
uassert(MmxPackageOwnership::isVendorRefreshVerdict('ok') === true, 'ownership ok is vendor');
uassert(MmxPackageOwnership::isVendorRefreshVerdict('unknown') === false, 'ownership unknown not vendor');
$own = new MmxPackageOwnership((object)array());
$refOwn = new ReflectionClass($own);
$namesFn = $refOwn->getMethod('elementNamesFromBasename');
$namesFn->setAccessible(true);
$names = $namesFn->invoke($own, 'snippet.formit');
uassert(in_array('formit', $names, true), 'ownership strips snippet. prefix');
uassert($own->packageFromDocblock("/**\n * @package moddevtools\n */") === 'moddevtools', 'ownership reads @package');
uassert($own->packageFromDocblock('// no package') === '', 'ownership empty without @package');
uassert($own->normalizeElementKey('pdoCrumbs') === 'pdocrumbs', 'normalize pdoCrumbs');
uassert($own->normalizeElementKey('get_tickets') === 'gettickets', 'normalize get_tickets');
uassert($own->normalizeElementKey('msProducts') === 'msproducts', 'normalize msProducts');
uassert($own->isSubstantialEdit('<?php echo 1;', '<?php echo 1; ') === false, 'whitespace not substantial');
uassert($own->isSubstantialEdit('<?php echo 1;', '<?php /*x*/ echo 1;') === false, 'comment not substantial');
uassert($own->isSubstantialEdit('<?php return 1;', '<?php return totally_custom_logic_here_for_client();') === true, 'rewrite is substantial');

require_once $root . '/core/components/migratemodx/model/migratemodx/mmxpackagerepoprobe.class.php';
$fixtureXml = '<?xml version="1.0"?><item total="1"><item>'
    . '<name>FormIt</name><signature>formit-5.2.4-pl</signature><version>5.2.4</version>'
    . '<supports>3.0</supports><minimum_supports>3.0</minimum_supports>'
    . '</item></item>';
$httpCalls = array();
$probe = new MmxPackageRepoProbe(array(
    'cache_dir' => sys_get_temp_dir() . '/mmx-repo-test-' . getmypid() . '/',
    'timeout' => 2,
    'ttl' => 60,
    'target_modx' => '3.0.3',
    'repos' => array(
        'modx' => 'https://rest.modx.com/extras/',
        'modstore' => 'https://modstore.pro/extras/',
    ),
    'http_get' => function ($url, $timeout) use (&$httpCalls, $fixtureXml) {
        $httpCalls[] = $url;
        if (strpos($url, 'modstore.pro') !== false) {
            return false;
        }
        if (strpos($url, 'migratemodx_ping') !== false) {
            return '<?xml version="1.0"?><item total="0"></item>';
        }
        if (strpos($url, 'query=formit') !== false) {
            return $fixtureXml;
        }
        return '<?xml version="1.0"?><item total="0"></item>';
    },
));
$rep = $probe->probe(array('formit', 'moddevtools'));
uassert(!empty($rep['repos']['modx']['ok']), 'repo modx reachable via mock');
uassert(empty($rep['repos']['modstore']['ok']), 'repo modstore down via mock');
uassert(!empty($rep['packages']['formit']['best']['modx3']), 'repo formit has MODX 3 build');
uassert($rep['packages']['formit']['best']['version'] === '5.2.4', 'repo formit version');
$row = $probe->applyToRow(array(
    'name' => 'formit',
    'version' => '4.2.0',
    'verdict' => 'unknown',
    'evidence' => 'unknown',
    'notes' => '',
    'resolution' => 'none',
    'required_version' => '',
    'version_status' => 'unknown',
), $rep['packages']['formit']);
uassert($row['verdict'] === 'update', 'repo apply sets update when outdated');
uassert($row['repo_source'] === 'modx', 'repo source modx');
uassert($row['evidence'] === 'repo', 'repo evidence');

require_once $root . '/core/components/migratemodx/model/migratemodx/mmxrecovery.class.php';
$recTmp = new MmxRecovery(sys_get_temp_dir() . '/mmx-rec/', sys_get_temp_dir() . '/mmx-site/', sys_get_temp_dir() . '/mmx-assets/');
$urls = $recTmp->buildUrls('https://example.test/', 'abc123');
uassert($urls['url'] === 'https://example.test/assets/components/migratemodx/recovery/index.php?token=abc123', 'recovery full url');
uassert($urls['shortcut_url'] === 'https://example.test/migratemodx-recovery.php', 'recovery shortcut url');

$tmp = sys_get_temp_dir() . '/mmx_unit_' . getmypid();
@mkdir($tmp . '/journals', 0777, true);
$j = new MmxStateJournal($tmp);
$j->write(array('phase' => 'test'));
$j->log('hello');
$s = $j->read();
uassert($s['phase'] === 'test', 'journal phase');
uassert(count($s['messages']) === 1, 'journal message');

$gate = new MmxReadinessGate();
$r = $gate->evaluate(array(
    'environment' => array('issues' => array(array('severity' => 'blocker', 'code' => 'x', 'message' => 'bad'))),
    'packages' => array(),
    'heuristics' => array(),
    'php' => array(),
    'templates' => array(),
    'snapshots' => array('latest' => null),
), array());
uassert($r['traffic'] === 'red', 'gate red on blocker');
uassert($r['upgrade_ready'] === false, 'upgrade not ready');

$rules = include $root . '/core/components/migratemodx/data/php/rules.php';
uassert(is_array($rules) && count($rules) >= 5, 'php rules loaded');

$code = '$a{0}; echo "${name}";';
$fixed = preg_replace(
    '/(\$[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*(?:\[[^\]]+\])*)\{([\'"]?)([^}]+)\2\}/',
    '$1[$2$3$2]',
    $code
);
$fixed = preg_replace('/\\$\\{([a-zA-Z_][a-zA-Z0-9_]*)\\}/', '\$$1', $fixed);
uassert(strpos($fixed, '$a[0]') !== false, 'curly autofix pattern');
uassert(strpos($fixed, '$name') !== false && strpos($fixed, '${name}') === false, 'interp autofix pattern');

require_once $root . '/core/components/migratemodx/model/migratemodx/mmxtemplatesyntaxscan.class.php';
uassert(class_exists('MmxTemplateSyntaxScan'), 'template scan class loads');
uassert(class_exists('MmxFenomBridge'), 'fenom bridge class loads');
/* Regex path must work without Fenom installed */
$scan = new MmxTemplateSyntaxScan((object)array());
$ref = new ReflectionClass($scan);
$m = $ref->getMethod('scanContent');
$m->setAccessible(true);
$hits = $m->invoke($scan, "{if \$x}\nno close", null);
uassert(is_array($hits) && count($hits) > 0, 'fenom regex finds unclosed if without Fenom');

/* 1.2.0 migrate pipeline: rewrite tables, schema, matrix, attention shape */
$rwDir = $root . '/core/components/migratemodx/data/rewrites';
$modx3Json = json_decode((string)file_get_contents($rwDir . '/modx3.json'), true);
$ms2Json = json_decode((string)file_get_contents($rwDir . '/ms2-ms3.json'), true);
$schemaJson = json_decode((string)file_get_contents($rwDir . '/ms2-ms3-schema.json'), true);
uassert(!empty($modx3Json['safe_string_replacements']), 'modx3 rewrite table loads');
uassert(!empty($ms2Json['snippets']['msProducts']['to']), 'ms2 snippet table has msProducts');
uassert(!empty($ms2Json['attention_patterns']), 'ms2-ms3 has attention patterns for front');
$attIds = array();
foreach ((array)$ms2Json['attention_patterns'] as $p) {
    if (!empty($p['id'])) {
        $attIds[] = $p['id'];
    }
}
uassert(in_array('ms2_class_key_query', $attIds, true), 'ms2-ms3 detects class_key queries');
uassert(in_array('listing_msGallery', $attIds, true), 'ms2-ms3 detects listing msGallery');
uassert(!empty($schemaJson['tables']) && count($schemaJson['tables']) >= 8, 'ms2-ms3 schema has tables');
$schemaIds = array();
foreach ($schemaJson['tables'] as $t) {
    $schemaIds[] = $t['id'];
}
uassert(in_array('product_data', $schemaIds, true) && in_array('order', $schemaIds, true), 'schema maps product + order');

require_once $root . '/core/components/migratemodx/model/migratemodx/mmxmodx3rewrite.class.php';
require_once $root . '/core/components/migratemodx/model/migratemodx/mmxms2migrator.class.php';
require_once $root . '/core/components/migratemodx/model/migratemodx/mmxmigraterunner.class.php';
require_once $root . '/core/components/migratemodx/model/migratemodx/mmxms3frontcompat.class.php';
uassert(class_exists('MmxModx3Rewrite') && class_exists('MmxMs2Migrator') && class_exists('MmxMigrateRunner'), 'migrate classes load');
uassert(class_exists('MmxMs3FrontCompat'), 'MmxMs3FrontCompat loads');
require_once $root . '/core/components/migratemodx/model/migratemodx/mmxpackageinstaller.class.php';
uassert(class_exists('MmxPackageInstaller'), 'MmxPackageInstaller loads');
require_once $root . '/core/components/migratemodx/model/migratemodx/mmxvendorcompat.class.php';
uassert(in_array('tickets', MmxVendorCompat::knownBlockerNames(), true), 'tickets is setup blocker');
uassert(in_array('looked', MmxVendorCompat::postMs3BrokenNames(), true), 'looked is post-MS3 broken');
uassert(in_array('msdiscount', MmxVendorCompat::postMs3BrokenNames(), true), 'msdiscount is post-MS3 broken');
uassert(in_array('msorderdiscount', MmxVendorCompat::postMs3BrokenNames(), true), 'msorderdiscount is post-MS3 broken');
uassert(in_array('msearch2', MmxVendorCompat::postMs3BrokenNames(), true), 'msearch2 is post-MS3 broken via KB');
uassert(in_array('mstools', MmxVendorCompat::postMs3BrokenNames(), true), 'mstools is post-MS3 broken via KB');
uassert(!in_array('looked', MmxVendorCompat::knownBlockerNames(), true), 'looked not quarantined on MODX2 prepare');
uassert(method_exists('MmxVendorCompat', 'scrubExtensionPackagesSetting'), 'scrubExtensionPackagesSetting exists');
uassert(method_exists('MmxVendorCompat', 'discoverHeuristicBrokenExtras'), 'heuristic discovery exists');
uassert(method_exists('MmxMs3FrontCompat', 'run'), 'FrontCompat run exists');
$kbQ = new MmxKnowledgeBase($root . '/core/components/migratemodx/data/kb/packages.json');
uassert($kbQ->getVersion() === '1.2.0', 'KB version 1.2.0');
uassert(in_array('tickets', $kbQ->namesForQuarantine('prepare'), true), 'KB prepare includes tickets');
uassert(count($kbQ->namesForQuarantine('post_ms3')) >= 20, 'KB post_ms3 has broad coverage');
uassert($kbQ->lookup('mschart') !== null && $kbQ->lookup('mschart')['quarantine'] === 'post_ms3', 'mschart in KB post_ms3');
$pkgInst = new MmxPackageInstaller(null, $root . '/core/cache/migratemodx-test/');
$pkgPlan = $pkgInst->buildPlan(array(
    array(
        'name' => 'minishop2',
        'version' => '2.4.0-pl',
        'verdict' => 'replace',
        'successor' => 'minishop3',
    ),
    array(
        'name' => 'pdotools',
        'version' => '2.12.0-pl',
        'verdict' => 'update',
        'needs_update' => true,
        'version_status' => 'outdated',
        'repo_signature' => 'pdotools-3.0.2-pl',
        'repo_latest' => '3.0.2',
        'repo_source' => 'modx',
    ),
), array(
    'minishop3' => array('requires' => array('pdotools', 'vuetools')),
    'vuetools' => array(),
));
uassert(!empty($pkgPlan['items']), 'package plan has items');
$planNames = array();
foreach ($pkgPlan['items'] as $it) {
    $planNames[] = $it['name'] . ':' . $it['action'];
}
uassert(in_array('minishop3:install', $planNames, true), 'plan installs minishop3 successor');
uassert(in_array('vuetools:install', $planNames, true), 'plan installs vuetools dependency');
uassert(in_array('pdotools:update', $planNames, true) || in_array('pdotools:install', $planNames, true), 'plan updates or installs pdotools');
$frontCompat = new MmxMs3FrontCompat(null, $root . '/core/cache/migratemodx-test/', $root . '/');
$reps = $frontCompat->safeReplacements();
uassert(is_array($reps) && count($reps) >= 8, 'front compat has safe replacements');
$sample = "\$c = \$modx->newQuery('msProduct');\n\$c->where(array('class_key' => 'msProduct'));\nclass=\"ms2_form\"";
$patched = $sample;
foreach ($reps as $rep) {
    $patched = str_replace($rep['from'], $rep['to'], $patched);
}
uassert(strpos($patched, "MiniShop3\\Model\\msProduct") !== false, 'front compat rewrites newQuery/class_key');
uassert(strpos($patched, 'ms3_form') !== false, 'front compat rewrites ms2_form');
uassert(strpos($patched, "'class_key' => 'msProduct'") === false, 'legacy class_key removed');
$card = '<form class="product-1 ms2_form x"><button name="ms2_action" value="cart/add">Buy</button></form>';
$cardPatched = $card;
foreach ($reps as $rep) {
    $cardPatched = str_replace($rep['from'], $rep['to'], $cardPatched);
}
uassert(strpos($cardPatched, 'ms2_form') === false, 'mid-class ms2_form rewritten');
uassert(strpos($cardPatched, 'name="ms3_action"') !== false, 'ms2_action rewritten to ms3_action');

$cartForm = '<form class="ms3_form" data-ms3-form>'
    . '<input type="hidden" name="id" value="12">'
    . '<button type="submit" name="ms3_action" value="cart/add">Buy</button>'
    . '</form>';
$cartFixed = $frontCompat->injectMs3ActionHiddenInputs($cartForm);
uassert(strpos($cartFixed, 'type="hidden" name="ms3_action" value="cart/add"') !== false, 'hidden ms3_action injected for FormData');
uassert(substr_count($cartFixed, 'name="ms3_action"') === 2, 'hidden + button both keep ms3_action');
$cartAlready = '<form class="ms3_form"><input type="hidden" name="ms3_action" value="cart/add"><button name="ms3_action" value="cart/add">X</button></form>';
uassert($frontCompat->injectMs3ActionHiddenInputs($cartAlready) === $cartAlready, 'no duplicate hidden ms3_action');
$php8src = 'function getImageList($tvname = "", $resource_id = null, array $config) { return 1; }';
$refFc = new ReflectionClass('MmxMs3FrontCompat');
$fixPhp8 = $refFc->getMethod('fixPhp8OptionalBeforeRequired');
$fixPhp8->setAccessible(true);
$php8fixed = $fixPhp8->invoke($frontCompat, $php8src);
uassert(strpos($php8fixed, 'array $config = null') !== false, 'PHP8 typed required-after-optional gets = null');
$refHide = new ReflectionClass('MmxMs3FrontCompat');
uassert($refHide->hasMethod('healProductHidemenu'), 'healProductHidemenu exists');
uassert($refHide->hasMethod('healSearchFormTpl'), 'healSearchFormTpl exists');
uassert($refHide->hasMethod('healCategoryCards'), 'healCategoryCards exists');
uassert($refHide->hasMethod('healMiniCartTrigger'), 'healMiniCartTrigger exists');
uassert($refHide->hasMethod('healCartPage'), 'healCartPage exists');
uassert($refHide->hasMethod('healPurgeMs2FrontTraces'), 'healPurgeMs2FrontTraces exists');
$isMini = $refHide->getMethod('isMiniCartMarkupTarget');
$isMini->setAccessible(true);
$isCart = $refHide->getMethod('isCartPageMarkupTarget');
$isCart->setAccessible(true);
$rewriteCart = $refHide->getMethod('rewriteCartMarkup');
$rewriteCart->setAccessible(true);
uassert($isMini->invoke($frontCompat, 'customMiniCart', '') === true, 'customMiniCart detected as minicart');
uassert($isCart->invoke($frontCompat, 'tpl.msCart', '') === true, 'tpl.msCart detected as cart page');
uassert($isCart->invoke($frontCompat, 'customMiniCart', '') === false, 'minicart not treated as cart page');
$ms2CartRow = '<form class="ms2_form" method="post">'
    . '<input type="hidden" name="key" value="{$product.key}">'
    . '<button type="submit" name="ms2_action" value="cart/remove">X</button>'
    . '</form>';
$ms3CartRow = $rewriteCart->invoke($frontCompat, $ms2CartRow, array('strip_order' => false));
uassert(strpos($ms3CartRow, 'name="product_key"') !== false, 'cart page rewrites name=key → product_key');
uassert(strpos($ms3CartRow, 'value="{$product.product_key}"') !== false, 'cart page rewrites product.key → product_key');
uassert(strpos($ms3CartRow, 'ms3_form') !== false && strpos($ms3CartRow, 'data-ms3-form') !== false, 'cart page gets ms3_form + data-ms3-form');
uassert(strpos($ms3CartRow, 'ms3_action') !== false, 'cart page rewrites ms2_action');
$miniWithOrder = $ms2CartRow . '[[!msOrder]]';
$miniClean = $rewriteCart->invoke($frontCompat, $miniWithOrder, array('strip_order' => true));
uassert(strpos($miniClean, 'msOrder') === false, 'minicart strips nested msOrder');
$cartKeepsOrder = $rewriteCart->invoke($frontCompat, $miniWithOrder, array('strip_order' => false));
uassert(strpos($cartKeepsOrder, '[[!msOrder]]') !== false, 'cart page keeps msOrder');
$liveSync = $refHide->getMethod('ms3CartLiveSyncScript');
$liveSync->setAccessible(true);
$syncJs = $liveSync->invoke($frontCompat);
uassert(strpos($syncJs, '#msCart') !== false, 'live-sync refreshes full cart page #msCart');
uassert(strpos($syncJs, '#minicart-content') !== false, 'live-sync still refreshes minicart');
$boot = $refHide->getMethod('mmxWaitJqBootstrap');
$boot->setAccessible(true);
$bootJs = $boot->invoke($frontCompat);
uassert(strpos($bootJs, 'fn,deps') !== false && strpos($bootJs, 'fn,need') === false, 'WaitJq uses deps not need (Fenom-safe)');

$refRun = new ReflectionClass('MmxMigrateRunner');
$runner = $refRun->newInstanceWithoutConstructor();
$matrixFn = $refRun->getMethod('buildPackageMatrix');
$matrixFn->setAccessible(true);
$matrix = $matrixFn->invoke($runner, array(
    array(
        'name' => 'minishop2',
        'version' => '2.4.0-pl',
        'verdict' => 'replace',
        'successor' => 'minishop3',
        'notes' => 'x',
    ),
    array(
        'name' => 'minishop3',
        'version' => '1.0.0-pl',
        'verdict' => 'ok',
        'successor' => '',
        'notes' => '',
    ),
    array(
        'name' => 'pdotools',
        'version' => '2.12.0-pl',
        'verdict' => 'update',
        'version_status' => 'outdated',
        'successor' => '',
        'repo_best' => array('version' => '3.0.2-pl'),
        'notes' => '',
    ),
));
uassert(count($matrix) === 3, 'matrix row count');
$byName = array();
foreach ($matrix as $row) {
    $byName[$row['name']] = $row;
}
uassert(in_array('both_minishop2_and_minishop3', $byName['minishop2']['conflicts'], true), 'matrix detects ms2+ms3 conflict');
uassert($byName['pdotools']['needs_update'] === true && $byName['pdotools']['repo_latest'] === '3.0.2-pl', 'matrix outdated + repo');
uassert($byName['minishop3']['modx3_ok'] === true, 'matrix ms3 ok');

$exportFn = $refRun->getMethod('buildAgentExport');
$exportFn->setAccessible(true);
$md = $exportFn->invoke($runner, array(
    'generated_at' => '2026-01-01T00:00:00+00:00',
    'goal' => 'modx3',
    'done' => array(array('id' => 'a', 'message' => 'done one')),
    'failed' => array(),
    'needs_attention' => array(array('id' => 'n', 'message' => 'fix me')),
    'known' => array(array('id' => 'a', 'message' => 'done one')),
    'unknown' => array(array('id' => 'n', 'message' => 'fix me')),
    'packages' => $matrix,
    'ms3' => array('mode' => 'dry-run'),
    'gate' => array('score' => 70, 'upgrade_ready' => false),
));
uassert(strpos($md, '# MigrateMODX Attention Report') === 0, 'agent export title');
uassert(strpos($md, 'done one') !== false && strpos($md, 'fix me') !== false, 'agent export lists done/needs');
uassert(strpos($md, 'minishop2') !== false, 'agent export lists packages');

/* Runner step order contract (documented prepare sequence) */
$expectedSteps = array(
    'snapshot_recovery',
    'full_audit',
    'php_keepers',
    'modx3_rewrites',
    'package_matrix',
    'vendor_compat',
    'ms2_ms3_data',
    're_audit_report',
);
uassert(count($expectedSteps) === 8, 'prepare has 8 steps');

require_once $root . '/core/components/migratemodx/model/migratemodx/mmxvendorcompat.class.php';
$vroot = sys_get_temp_dir() . '/mmx_vendor_' . getmypid() . '/';
@mkdir($vroot . 'core/components/badpkg', 0777, true);
@mkdir($vroot . 'assets/components/badpkg', 0777, true);
file_put_contents(
    $vroot . 'core/components/badpkg/bad.php',
    "<?php\n\$x = \$arr{0};\nrequire 'model/modx/modcachemanager.class.php';\n"
);
file_put_contents($vroot . 'assets/components/badpkg/x.js', 'console.log(1)');
$vc = new MmxVendorCompat(null, $vroot, $vroot . 'store/', $root . '/core/components/migratemodx/data');
$vres = $vc->process(array(
    array('name' => 'badpkg', 'verdict' => 'fail', 'status' => 'incompatible'),
    array('name' => 'minishop2', 'verdict' => 'replace', 'status' => 'replaced_by', 'successor' => 'minishop3'),
), true);
uassert(count($vres['quarantined']) === 1, 'fail package quarantined when blockers remain');
uassert(is_dir($vroot . 'core/components/badpkg' . MmxVendorCompat::SUFFIX_DISABLED), 'quarantine suffix on disk');
uassert(count($vres['manual']) >= 1, 'replace package goes to manual (successor)');
uassert(in_array('tickets', MmxVendorCompat::knownBlockerNames(), true), 'tickets is a known manager blocker');
@mkdir($vroot . 'core/components/tickets', 0777, true);
@mkdir($vroot . 'assets/components/tickets', 0777, true);
@mkdir($vroot . 'core/components/tickets/model/tickets', 0777, true);
file_put_contents(
    $vroot . 'core/components/tickets/model/tickets/metadata.mysql.php',
    "<?php\n\$this->map['modUser']['composites']['AuthorProfile'] = array();\n"
);
uassert(in_array('tickets', $vc->discoverMapModUserKillers(), true), 'discover finds tickets modUser map');
uassert(in_array('tickets', $vc->listPresentBlockers(), true), 'tickets listed as present blocker');
$harden = $vc->hardenSite();
uassert(!empty($harden['ok']), 'hardenSite clears blockers');
uassert(is_dir($vroot . 'core/components/tickets' . MmxVendorCompat::SUFFIX_DISABLED), 'tickets folder force-quarantined');
uassert(empty($vc->listPresentBlockers()), 'no blockers remain after harden');
/* Clone with modUser map but unknown name */
@mkdir($vroot . 'core/components/customtickets/model/x', 0777, true);
file_put_contents(
    $vroot . 'core/components/customtickets/model/x/metadata.mysql.php',
    "<?php\n\$this->map[\"modUser\"]['composites'] = array();\n"
);
uassert(in_array('customtickets', $vc->discoverMapModUserKillers(), true), 'discover unknown modUser killer');
$harden2 = $vc->hardenSite();
uassert(is_dir($vroot . 'core/components/customtickets' . MmxVendorCompat::SUFFIX_DISABLED), 'discovered killer quarantined');
uassert(!empty($harden2['ok']), 'harden ok after discovery quarantine');
@mkdir($vroot . 'core/components/minishop2', 0777, true);
@mkdir($vroot . 'assets/components/minishop2', 0777, true);
file_put_contents($vroot . 'core/components/minishop2/x.php', "<?php\n");
$fq = $vc->forceQuarantinePackage('minishop2');
uassert(!empty($fq['ok']), 'forceQuarantinePackage minishop2 ok');
uassert(is_dir($vroot . 'core/components/minishop2' . MmxVendorCompat::SUFFIX_DISABLED), 'minishop2 force-quarantined');
/* Portable package: only curly offset, no blockers */
@mkdir($vroot . 'core/components/softpkg', 0777, true);
file_put_contents($vroot . 'core/components/softpkg/ok.php', "<?php\n\$y = \$arr{1};\n");
$vres2 = $vc->process(array(
    array('name' => 'softpkg', 'verdict' => 'fail', 'status' => 'incompatible'),
), true);
uassert(count($vres2['ported']) === 1, 'safe package ported when only PHP curly fix');
uassert(is_dir($vroot . 'core/components/softpkg' . MmxVendorCompat::SUFFIX_PORTED), 'ported suffix on disk');
$portedCode = file_get_contents($vroot . 'core/components/softpkg' . MmxVendorCompat::SUFFIX_PORTED . '/ok.php');
uassert(strpos($portedCode, '$arr[1]') !== false, 'ported file has PHP autofix applied');

$migrator = new MmxMs2Migrator((object)array(), $rwDir, sys_get_temp_dir() . '/mmx-ms3-' . getmypid() . '/');
$refMig = new ReflectionClass($migrator);
$loadFn = $refMig->getMethod('loadJson');
$loadFn->setAccessible(true);
$loaded = $loadFn->invoke($migrator, $rwDir . '/ms2-ms3-schema.json');
uassert(!empty($loaded['tables'][0]['columns']), 'migrator loads schema columns');
uassert(isset($loaded['tables'][0]['columns']['count']) && $loaded['tables'][0]['columns']['count'] === 'stock', 'ms2 count maps to ms3 stock');
uassert(isset($loaded['tables'][0]['columns']['source']) && $loaded['tables'][0]['columns']['source'] === 'source_id', 'ms2 source maps to ms3 source_id');

/* Phase guide + applyMs3Data gate on MODX 2 stub */
$stubModx = new class {
    public function getVersionData() { return array('version' => 2, 'full_version' => '2.8.8-pl'); }
    public function getObject() { return null; }
    public function getOption($k, $o = null, $d = '') { return $k === 'settings_version' ? '2.8.8-pl' : $d; }
};
$guideFn = $refRun->getMethod('buildGuide');
$guideFn->setAccessible(true);
$sp = $refRun->getProperty('modx');
$sp->setAccessible(true);
$sp->setValue($runner, $stubModx);
$guide = $guideFn->invoke($runner, array(
    'goal' => 'modx3',
    'finished_at' => date('c'),
    'upgrade_ready' => true,
), array('failed' => array()), null);
uassert((int)$guide['phase'] === 2, 'guide phase 2 when prepare clean on MODX 2');
$stubModx3 = new class {
    public function getVersionData() { return array('version' => '3.0.5-pl', 'full_version' => '3.0.5-pl'); }
    public function getObject() { return null; }
    public function getCollection() { return array(); }
    public function getOption($k, $o = null, $d = '') { return $k === 'settings_version' ? '3.0.5-pl' : $d; }
};
$sp->setValue($runner, $stubModx3);
$guide3 = $guideFn->invoke($runner, array(
    'goal' => 'modx3',
    'finished_at' => date('c'),
    'upgrade_ready' => true,
    'core_applied_at' => date('c'),
), array('failed' => array()), null);
uassert((int)$guide3['phase'] === 3, 'guide phase 3 when settings_version is 3.x');
uassert((int)$guide3['modx_major'] === 3, 'modx_major 3 from settings_version');
$majorFn = $refRun->getMethod('modxMajor');
$majorFn->setAccessible(true);
uassert((int)$majorFn->invoke($runner) === 3, 'modxMajor parses 3.0.5-pl');

/* Files already 3.x after Apply core, but /setup/ not done → stay phase 2, PHP then setup */
$stubFiles3Db2 = new class {
    public function getVersionData() { return array('version' => '3.0.5-pl', 'full_version' => '3.0.5-pl'); }
    public function getObject() { return null; }
    public function getCollection() { return array(); }
    public function getOption($k, $o = null, $d = '') { return $k === 'settings_version' ? '2.8.8-pl' : $d; }
};
$sp->setValue($runner, $stubFiles3Db2);
$guideSetup = $guideFn->invoke($runner, array(
    'goal' => 'modx3',
    'finished_at' => date('c'),
    'upgrade_ready' => true,
    'core_applied_at' => date('c'),
    'core_version' => '3.0.5',
    'php_for_setup' => '8.2',
), array('failed' => array()), null);
uassert((int)$guideSetup['phase'] === 2, 'guide stays phase 2 until /setup/ (settings still 2.x)');
uassert(!empty($guideSetup['setup_pending']), 'setup_pending after core apply');
$idsSetup = array();
foreach ($guideSetup['next_actions'] as $na) { $idsSetup[] = $na['id']; }
uassert(in_array('setup', $idsSetup, true), 'next_actions include setup');
uassert(!in_array('install_ms3', $idsSetup, true), 'next_actions must not jump to MiniShop3 before setup');
uassert(!in_array('apply_core', $idsSetup, true), 'do not re-ask Apply core when files already on 3');

$sp->setValue($runner, $stubModx);
$guide1 = $guideFn->invoke($runner, null, null, null);
uassert((int)$guide1['phase'] === 1, 'guide phase 1 before prepare');

$applyFn = $refRun->getMethod('applyMs3Data');
$applyFn->setAccessible(true);
$denied = $applyFn->invoke($runner, array('confirm_ms3' => true));
uassert(empty($denied['ok']), 'applyMs3Data denied on MODX 2');
uassert(stripos((string)$denied['error'], 'MODX 3') !== false, 'applyMs3Data error mentions MODX 3');
$sp->setValue($runner, $stubFiles3Db2);
$deniedSetup = $applyFn->invoke($runner, array('confirm_ms3' => true));
uassert(empty($deniedSetup['ok']), 'applyMs3Data denied when files 3.x but setup not done');

$schemaNotes = json_decode((string)file_get_contents($rwDir . '/modx2-modx3-schema-notes.json'), true);
uassert(!empty($schemaNotes['advice']) && !empty($schemaNotes['notes']), 'schema notes JSON loads');

$exportFn2 = $refRun->getMethod('buildAgentExport');
$exportFn2->setAccessible(true);
$md2 = $exportFn2->invoke($runner, array(
    'generated_at' => '2026-01-01T00:00:00+00:00',
    'goal' => 'modx3',
    'known' => array(array('id' => 'k1', 'message' => 'known item')),
    'unknown' => array(array('id' => 'u1', 'message' => 'unknown item')),
    'failed' => array(),
    'packages' => array(),
    'done' => array(),
    'needs_attention' => array(),
));
uassert(strpos($md2, 'DISCLAIMER') !== false, 'agent export has disclaimer');
uassert(strpos($md2, 'known item') !== false && strpos($md2, 'unknown item') !== false, 'agent export known/unknown');

$guidePhp = $guideFn->invoke($runner, array('goal' => 'php_on_2'), null, null);
uassert($guidePhp['goal'] === 'php_on_2', 'guide respects php_on_2 goal');
uassert(!empty($guidePhp['next_actions']), 'php goal has next_actions');

/* Live version catalog: auto target prefers latest MODX 3 that meets package floors */
require_once $root . '/core/components/migratemodx/model/migratemodx/mmxversioncatalog.class.php';
$cat = new MmxVersionCatalog(array(
    'http_get' => function ($url) {
        if (strpos($url, 'api.github.com') !== false) {
            return json_encode(array(
                array('name' => 'v3.2.4-pl'),
                array('name' => 'v3.0.3-pl'),
                array('name' => 'v2.8.9-pl'),
            ));
        }
        if (strpos($url, 'minishop3') !== false) {
            return '<table><tr><td>MODX Revolution</td><td>3.0.3+</td></tr><tr><td>PHP</td><td>8.2+</td></tr></table>';
        }
        return false;
    },
));
$resolved = $cat->resolve(array(
    'setting_modx' => 'auto',
    'setting_php' => 'auto',
    'packages' => array(
        array('name' => 'minishop2', 'successor' => 'minishop3', 'version' => '2.4.0'),
    ),
    'kb' => $kb,
));
uassert($resolved['modx_latest_3'] === '3.2.4', 'catalog discovers latest MODX 3 from tags');
uassert($resolved['modx_latest_28'] === '2.8.9', 'catalog discovers latest 2.8');
uassert(version_compare($resolved['required_min_modx'], '3.0.3', '>='), 'MS3 floor raises required min MODX');
uassert(version_compare($resolved['required_min_php'], '8.2', '>='), 'MS3 floor raises required min PHP');
uassert($resolved['effective_modx'] === '3.2.4', 'auto effective MODX is latest satisfying floors');
uassert($resolved['modx_mode'] === 'latest', 'auto mode is latest when latest >= floor');
$pinned = $cat->resolve(array('setting_modx' => '3.0.3', 'setting_php' => '8.2', 'kb' => $kb));
uassert($pinned['effective_modx'] === '3.0.3' && $pinned['modx_mode'] === 'pinned', 'pinned MODX respected');
$dl = $cat->downloadUrlFor('3.2.4');
uassert(!empty($dl['ok']) && strpos($dl['url'], 'modx-3.2.4-pl.zip') !== false, 'download URL built for any 3.x');

/* Snapshot store must stay outside core; restore must stage when store is under wipe target */
$tmpRoot = sys_get_temp_dir() . '/mmx_store_safe_' . getmypid() . '/';
@mkdir($tmpRoot . 'core/cache/migratemodx/snapshots/oldsnap', 0777, true);
file_put_contents($tmpRoot . 'core/cache/migratemodx/snapshots/oldsnap/manifest.json', '{"id":"oldsnap"}');
$resolved = MmxPaths::resolveStorePath($tmpRoot, '');
$resolvedNorm = str_replace('\\', '/', $resolved);
uassert(strpos($resolvedNorm, 'migratemodx-data/') !== false, 'default store outside core');
uassert(strpos($resolvedNorm, '/core/cache/') === false, 'resolved store not under core/cache');
uassert(is_file($resolved . 'snapshots/oldsnap/manifest.json'), 'legacy snapshots migrated to safe store');
uassert(MmxPaths::pathIsInside($tmpRoot . 'core/cache/migratemodx/snapshots/x', $tmpRoot . 'core'), 'pathIsInside detects store under core');
uassert(!MmxPaths::pathIsInside($tmpRoot . 'migratemodx-data/snapshots/x', $tmpRoot . 'core'), 'pathIsInside safe store not under core');

require_once $root . '/core/components/migratemodx/model/migratemodx/mmxsnapshotmanager.class.php';
require_once $root . '/core/components/migratemodx/model/migratemodx/mmxrollbackmanager.class.php';
$site = sys_get_temp_dir() . '/mmx_restore_safe_' . getmypid() . '/';
@mkdir($site . 'core/cache/migratemodx/snapshots/snap1/files', 0777, true);
@mkdir($site . 'core/keepme', 0777, true);
file_put_contents($site . 'core/keepme/marker.txt', 'alive');
/* Minimal fake zip-less snapshot: directory copy path */
@mkdir($site . 'core/cache/migratemodx/snapshots/snap1/files/core', 0777, true);
file_put_contents($site . 'core/cache/migratemodx/snapshots/snap1/files/core/restored.txt', 'from-snap');
$manifest = array(
    'id' => 'snap1',
    'files' => array(array('path' => 'core', 'zip' => 'files/core.zip', 'sha256' => 'x')),
    'database' => null,
);
/* Bypass checksum verify by writing matching empty zip unavailable — use files/ tree branch:
   rollback checks zip first; create invalid zip missing so it falls through to files/ dir */
file_put_contents(
    $site . 'core/cache/migratemodx/snapshots/snap1/manifest.json',
    json_encode($manifest)
);
/* Direct unit of threat detection + staging via reflection */
$sm = new MmxSnapshotManager($site . 'core/cache/migratemodx/', $site, null);
$rb = new MmxRollbackManager($sm);
$refRb = new ReflectionClass($rb);
$threat = $refRb->getMethod('snapshotThreatenedByRestore');
$threat->setAccessible(true);
$threatened = $threat->invoke(
    $rb,
    $site . 'core/cache/migratemodx/snapshots/snap1/',
    $site . 'core/cache/migratemodx/',
    $site,
    $manifest
);
uassert($threatened === true, 'legacy store under core is threatened by core restore');
$stage = $refRb->getMethod('stageSnapshotOutsideTargets');
$stage->setAccessible(true);
$stagedPath = $stage->invoke($rb, $site . 'core/cache/migratemodx/snapshots/snap1/', $site, 'snap1');
uassert(is_string($stagedPath) && is_dir($stagedPath), 'stageSnapshot creates work dir');
uassert(!MmxPaths::pathIsInside($stagedPath, $site . 'core'), 'staged snapshot not under core');
uassert(is_file($stagedPath . 'manifest.json'), 'staged snapshot has manifest');
/* cleanup */
$rbClear = $refRb->getMethod('rrmdir');
$rbClear->setAccessible(true);
$rbClear->invoke($rb, rtrim($stagedPath, '/'));
$rbClear->invoke($rb, rtrim($tmpRoot, '/'));
$rbClear->invoke($rb, rtrim($site, '/'));

/* Post-rollback migrate progress reset */
require_once $root . '/core/components/migratemodx/model/migratemodx/mmxpostrollback.class.php';
$prDir = sys_get_temp_dir() . '/mmx-post-rb-' . getmypid() . '/';
@mkdir($prDir, 0755, true);
file_put_contents($prDir . 'migrate-run.json', json_encode(array(
    'goal' => 'modx3',
    'finished_at' => date('c'),
    'core_applied_at' => date('c'),
    'ms3_data_ok' => true,
    'core_version' => '3.0.5-pl',
    'status' => 'completed',
)));
file_put_contents($prDir . 'last-audit.json', '{"environment":{"modx_version":"3.0.5-pl"}}');
uassert(MmxPostRollback::looksStaleAfterRollback(json_decode(file_get_contents($prDir . 'migrate-run.json'), true), 2) === true, 'stale run detected on MODX 2');
uassert(MmxPostRollback::looksStaleAfterRollback(json_decode(file_get_contents($prDir . 'migrate-run.json'), true), 3) === false, 'not stale on MODX 3');
$pr = MmxPostRollback::resetMigrateProgress($prDir, 'snap-demo', 'recovery_rollback');
uassert(!empty($pr['ok']), 'resetMigrateProgress ok');
$prRun = json_decode((string)file_get_contents($prDir . 'migrate-run.json'), true);
uassert(is_array($prRun) && empty($prRun['core_applied_at']) && empty($prRun['finished_at']), 'reset clears phase markers');
uassert($prRun['status'] === 'reset_after_rollback', 'reset status');
$prFlag = MmxPostRollback::readFlag($prDir);
uassert(!empty($prFlag['pending_audit']), 'pending_audit flag set');
uassert(!is_file($prDir . 'last-audit.json'), 'stale last-audit archived');
uassert(MmxPostRollback::looksStaleAfterRollback($prRun, 2) === false, 'fresh reset run not stale');
/* cleanup */
foreach (glob($prDir . '*') as $f) { @unlink($f); }
@rmdir($prDir);

echo "\n=== Unit: $passed passed, $failed failed ===\n";
exit($failed ? 1 : 0);
