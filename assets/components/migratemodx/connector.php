<?php
/**
 * Manager API for MigrateMODX CMP.
 */
define('MODX_CONNECTOR_INCLUDED', true);
require_once dirname(dirname(dirname(dirname(__FILE__)))) . '/config.core.php';
require_once MODX_CORE_PATH . 'config/' . MODX_CONFIG_KEY . '.inc.php';
require_once MODX_CONNECTORS_PATH . 'index.php';

$corePath = $modx->getOption('migratemodx_core_path', null, MODX_CORE_PATH . 'components/migratemodx/');
require_once $corePath . 'model/migratemodx/migratemodx.class.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$raw = (string)file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = array();
}

$token = '';
if (!empty($_SERVER['HTTP_MODAUTH'])) {
    $token = (string)$_SERVER['HTTP_MODAUTH'];
} elseif (!empty($_SERVER['HTTP_X_MODAUTH'])) {
    $token = (string)$_SERVER['HTTP_X_MODAUTH'];
} elseif (!empty($data['MODAUTH'])) {
    $token = (string)$data['MODAUTH'];
} elseif (!empty($_REQUEST['HTTP_MODAUTH'])) {
    $token = (string)$_REQUEST['HTTP_MODAUTH'];
}

$authed = $modx->user && $modx->user->isAuthenticated('mgr') && $token !== ''
    && hash_equals((string)$modx->user->getUserToken('mgr'), $token);

if (!$authed) {
    http_response_code(401);
    echo json_encode(array('ok' => false, 'error' => 'unauthorized'));
    return;
}

/* Match CMP controller: packages | workspaces | settings */
if (!$modx->hasPermission('packages')
    && !$modx->hasPermission('workspaces')
    && !$modx->hasPermission('settings')) {
    http_response_code(403);
    echo json_encode(array('ok' => false, 'error' => 'access denied'));
    return;
}

if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(array('ok' => false, 'error' => 'POST only'));
    return;
}

$action = isset($_REQUEST['action']) ? (string)$_REQUEST['action'] : '';
if ($action === '' && !empty($data['action'])) {
    $action = (string)$data['action'];
}
$action = strtolower(preg_replace('/[^a-z0-9_\-]/', '', $action));
unset($data['MODAUTH'], $data['action']);

try {
    @set_time_limit(300);
    $out = (new MigrateMODX($modx))->handle($action, $data);
} catch (Exception $e) {
    $modx->log(modX::LOG_LEVEL_ERROR, '[MigrateMODX] ' . $e->getMessage());
    $out = array('ok' => false, 'error' => $e->getMessage());
} catch (Throwable $e) {
    $modx->log(modX::LOG_LEVEL_ERROR, '[MigrateMODX] ' . $e->getMessage());
    $out = array('ok' => false, 'error' => $e->getMessage());
}

if (!empty($out['_file'])) {
    list($path, $name, $type) = $out['_file'];
    header('Content-Type: ' . $type);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="' . $name . '"');
    readfile($path);
    return;
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
