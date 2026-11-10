<?php
define('MODX_API_MODE', true);
require dirname(__DIR__) . '/_sandbox/modx-2.8.8-pl/core/config/config.inc.php';
require MODX_CORE_PATH . 'model/modx/modx.class.php';
$m = new modX();
$m->initialize('mgr');

$ns = $m->getObject('modNamespace', array('name' => 'migratemodx'));
echo "ns path=" . ($ns ? $ns->getCorePath() : 'MISSING') . PHP_EOL;
$path = ($ns ? $ns->getCorePath() : MODX_CORE_PATH . 'components/migratemodx/') . 'lexicon/ru/default.inc.php';
echo "file=" . $path . ' exists=' . (is_file($path) ? 'yes' : 'no') . PHP_EOL;

$m->setOption('cultureKey', 'ru');
$m->setOption('manager_language', 'ru');
$m->lexicon->load('ru:migratemodx:default');
$m->lexicon->load('migratemodx:default');
echo 'migratemodx=' . $m->lexicon('migratemodx') . PHP_EOL;
echo 'desc=' . $m->lexicon('migratemodx_menu_desc') . PHP_EOL;
echo 'audit=' . $m->lexicon('migratemodx_admin_run_audit') . PHP_EOL;

$_lang = array();
include $path;
echo 'direct include migratemodx=' . (isset($_lang['migratemodx']) ? $_lang['migratemodx'] : 'no') . PHP_EOL;
echo 'entries count from fetch=' . count($m->lexicon->fetch('migratemodx_', false)) . PHP_EOL;
