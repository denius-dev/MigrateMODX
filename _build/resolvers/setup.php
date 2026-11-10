<?php
/**
 * Post-install: store dir + ensure menu sits under components (Пакеты).
 *
 * @var xPDOTransport $transport
 * @var array $options
 * @var array $object
 */
if ($transport->xpdo) {
    /** @var modX $modx */
    $modx = &$transport->xpdo;
    $m3 = class_exists('MODX\\Revolution\\modX');
    $cls = function ($name) use ($m3) {
        return $m3 ? 'MODX\\Revolution\\' . $name : $name;
    };
    $level = defined('modX::LOG_LEVEL_INFO') ? modX::LOG_LEVEL_INFO : xPDO::LOG_LEVEL_INFO;

    $pathsFile = $modx->getOption('core_path') . 'components/migratemodx/model/migratemodx/mmxpaths.class.php';
    if (is_file($pathsFile)) {
        require_once $pathsFile;
    }
    $base = defined('MODX_BASE_PATH') ? MODX_BASE_PATH : dirname(rtrim($modx->getOption('core_path'), '/\\')) . '/';
    $configured = $modx->getOption('migratemodx_store_path', null, '');
    if (class_exists('MmxPaths', false)) {
        $store = MmxPaths::resolveStorePath($base, $configured);
    } else {
        $store = rtrim(str_replace('\\', '/', $base), '/') . '/migratemodx-data/';
        foreach (array($store, $store . 'snapshots/', $store . 'patches/', $store . 'journals/') as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
        }
        $deny = $store . '.htaccess';
        if (!is_file($deny)) {
            @file_put_contents($deny, "Require all denied\nDeny from all\n");
        }
    }
    $modx->log($level, '[MigrateMODX] Store ready at ' . $store);

    /* Prefer live version catalog: bump pinned legacy defaults to auto */
    $clsSetting = $cls('modSystemSetting');
    $targetModx = $modx->getObject($clsSetting, array('key' => 'migratemodx_target_modx'));
    if ($targetModx && in_array((string)$targetModx->get('value'), array('', '3.0.0', '3.0.0-pl', '3.0.3', '3.0.3-pl'), true)) {
        $targetModx->set('value', 'auto');
        $targetModx->save();
        $modx->log($level, '[MigrateMODX] migratemodx_target_modx set to auto (live catalog).');
    }
    $targetPhp = $modx->getObject($clsSetting, array('key' => 'migratemodx_target_php'));
    if ($targetPhp && in_array((string)$targetPhp->get('value'), array('', '8.1', '8.0', '7.4', '8.2'), true)) {
        $targetPhp->set('value', 'auto');
        $targetPhp->save();
        $modx->log($level, '[MigrateMODX] migratemodx_target_php set to auto (live catalog).');
    }

    /* Fix orphan menu from 1.0.0-pl that used parent=workspaces */
    $menu = $modx->getObject($cls('modMenu'), array('text' => 'migratemodx'));
    if ($menu) {
        $changed = false;
        if ($menu->get('parent') !== 'components') {
            $menu->set('parent', 'components');
            $changed = true;
        }
        if ($menu->get('permissions') === 'workspaces' || $menu->get('permissions') === '') {
            $menu->set('permissions', 'packages');
            $changed = true;
        }
        if ($changed) {
            $menu->save();
            $modx->log($level, '[MigrateMODX] Menu moved under components (Пакеты).');
        }
    } else {
        $menu = $modx->newObject($cls('modMenu'));
        $menu->fromArray(array(
            'text' => 'migratemodx',
            'parent' => 'components',
            'action' => 'index',
            'description' => 'migratemodx_menu_desc',
            'icon' => '',
            'menuindex' => 50,
            'params' => '',
            'handler' => '',
            'permissions' => 'packages',
            'namespace' => 'migratemodx',
        ), '', true, true);
        $menu->save();
        $modx->log($level, '[MigrateMODX] Menu created under components (Пакеты).');
    }

    /* Clear menu cache so the item appears without a full browser fight */
    $cache = $modx->getCacheManager();
    if ($cache) {
        $cache->refresh(array(
            'menu' => array(),
            'system_settings' => array(),
        ));
    }
}
return true;
