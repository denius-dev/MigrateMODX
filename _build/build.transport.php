<?php
/**
 * Builds the MigrateMODX transport package.
 *
 *   MODX_CORE_PATH=/path/to/site/core/ php _build/build.transport.php
 *
 * Build on MODX 2.8 so the package installs on 2.8 and 3.x.
 */
$tstart = microtime(true);
set_time_limit(0);

require_once __DIR__ . '/build.config.php';

$root = dirname(__DIR__) . '/';
$sources = array(
    'root' => $root,
    'build' => $root . '_build/',
    'data' => $root . '_build/data/',
    'resolvers' => $root . '_build/resolvers/',
    'core' => $root . 'core/components/' . PKG_NAME_LOWER . '/',
    'assets' => $root . 'assets/components/' . PKG_NAME_LOWER . '/',
    'docs' => $root . 'core/components/' . PKG_NAME_LOWER . '/docs/',
);

$modx3 = is_file(MODX_CORE_PATH . 'vendor/autoload.php') && is_dir(MODX_CORE_PATH . 'src/Revolution');
if ($modx3) {
    require_once MODX_CORE_PATH . 'vendor/autoload.php';
    $modx = new \MODX\Revolution\modX();
} else {
    require_once MODX_CORE_PATH . 'model/modx/modx.class.php';
    $modx = new modX();
}
$modx->initialize('mgr');
$modx->setLogLevel(3);
$modx->setLogTarget(PHP_SAPI === 'cli' ? 'ECHO' : 'HTML');

$cls = function ($name) use ($modx3) {
    return $modx3 ? 'MODX\\Revolution\\' . $name : $name;
};

if ($modx3) {
    $builder = new \MODX\Revolution\Transport\modPackageBuilder($modx);
} else {
    $modx->loadClass('transport.modPackageBuilder', '', false, true);
    $builder = new modPackageBuilder($modx);
}
$builder->createPackage(PKG_NAME_LOWER, PKG_VERSION, PKG_RELEASE);
$builder->registerNamespace(
    PKG_NAME_LOWER,
    false,
    true,
    '{core_path}components/' . PKG_NAME_LOWER . '/',
    '{assets_path}components/' . PKG_NAME_LOWER . '/'
);
$modx->log(3, 'Package created, namespace registered.');

$builder->package->put(array(
    'source' => $sources['core'],
    'target' => "return MODX_CORE_PATH . 'components/';",
), array(
    'vehicle_class' => 'xPDOFileVehicle',
    xPDOTransport::UNINSTALL_FILES => true,
));
$builder->package->put(array(
    'source' => $sources['assets'],
    'target' => "return MODX_ASSETS_PATH . 'components/';",
), array(
    'vehicle_class' => 'xPDOFileVehicle',
    xPDOTransport::UNINSTALL_FILES => true,
));
$modx->log(3, 'Packaged core and assets files.');

$settings = include $sources['data'] . 'settings.php';
foreach ($settings as $key => $s) {
    $setting = $modx->newObject($cls('modSystemSetting'));
    $setting->fromArray(array(
        'key' => $key,
        'value' => $s[1],
        'xtype' => $s[0],
        'namespace' => PKG_NAME_LOWER,
        'area' => 'migratemodx_main',
        'editedon' => null,
    ), '', true, true);
    $builder->putVehicle($builder->createVehicle($setting, array(
        'unique_key' => 'key',
        'preserve_keys' => true,
        'update_object' => false,
    )));
}
$modx->log(3, 'Packaged ' . count($settings) . ' system settings.');

/*
 * RU top menu "Пакеты" = components (EN "Extras").
 * Child "Установщик" = installer (action workspaces).
 * Do NOT use parent workspaces — that key is not a visible topnav item, so the CMP vanishes.
 */
$menu = $modx->newObject($cls('modMenu'));
$menu->fromArray(array(
    'text' => PKG_NAME_LOWER,
    'parent' => 'components',
    'action' => 'index',
    'description' => PKG_NAME_LOWER . '_menu_desc',
    'icon' => '',
    'menuindex' => 50,
    'params' => '',
    'handler' => '',
    'permissions' => 'packages',
    'namespace' => PKG_NAME_LOWER,
), '', true, true);
$builder->putVehicle($builder->createVehicle($menu, array(
    'unique_key' => 'text',
    'preserve_keys' => true,
    'update_object' => true,
)));
$modx->log(3, 'Packaged manager menu under components (Пакеты).');

$builder->putVehicle($builder->createVehicle(array(
    'source' => $sources['resolvers'] . 'setup.php',
), array(
    'vehicle_class' => 'xPDOScriptVehicle',
)));

$builder->setPackageAttributes(array(
    'license' => file_get_contents($sources['docs'] . 'license.txt'),
    'readme' => file_get_contents($sources['docs'] . 'readme.txt'),
    'changelog' => file_get_contents($sources['docs'] . 'changelog.txt'),
    'requires' => array(
        'php' => '>=7.2',
        'modx' => '>=2.6.5',
    ),
));

$modx->log(3, 'Packing...');
$builder->pack();
$file = MODX_CORE_PATH . 'packages/' . PKG_NAME_LOWER . '-' . PKG_VERSION . '-' . PKG_RELEASE . '.transport.zip';
$modx->log(3, 'Done in ' . sprintf('%.2f', microtime(true) - $tstart) . ' s: ' . $file);
