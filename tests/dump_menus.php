<?php
define('MODX_API_MODE', true);
require dirname(__DIR__) . '/_sandbox/modx-2.8.8-pl/core/config/config.inc.php';
require MODX_CORE_PATH . 'model/modx/modx.class.php';
$m = new modX();
$m->initialize('mgr');
$prefix = $m->getOption('table_prefix');
$q = $m->query("SELECT text, parent, action, namespace, menuindex, permissions FROM {$prefix}menus ORDER BY parent, menuindex, text");
while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
    echo sprintf("%-20s | %-20s | a=%-15s | ns=%-12s | p=%s\n",
        $r['parent'] === '' ? '(top)' : $r['parent'],
        $r['text'],
        $r['action'],
        $r['namespace'],
        $r['permissions']
    );
}
