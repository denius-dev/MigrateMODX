<?php
/**
 * Standalone Recovery Console — works without a living MODX manager.
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');

$siteRoot = dirname(dirname(dirname(dirname(dirname(__FILE__))))) . '/';
$siteRoot = str_replace('\\', '/', $siteRoot);
$coreModel = $siteRoot . 'core/components/migratemodx/model/migratemodx/';

require_once $coreModel . 'mmxpaths.class.php';
require_once $coreModel . 'mmxstatejournal.class.php';
require_once $coreModel . 'mmxsnapshotmanager.class.php';
require_once $coreModel . 'mmxrollbackmanager.class.php';
require_once $coreModel . 'mmxvendorcompat.class.php';
require_once $coreModel . 'mmxpostrollback.class.php';

$config = MmxPaths::loadConfigInc($siteRoot);
$storePath = MmxPaths::resolveStorePath($siteRoot, '');
$lockFile = $storePath . 'recovery.lock';
$lock = null;
if (!is_file($lockFile)) {
    /* Legacy lock may still live under core/cache/migratemodx */
    $legacyLock = MmxPaths::legacyStorePath($siteRoot) . 'recovery.lock';
    if (is_file($legacyLock)) {
        $lockFile = $legacyLock;
    }
}
if (is_file($lockFile)) {
    $lock = json_decode((string)file_get_contents($lockFile), true);
    if (is_array($lock) && !empty($lock['store_path'])) {
        $storePath = rtrim(str_replace('\\', '/', $lock['store_path']), '/') . '/';
        $lockFile = $storePath . 'recovery.lock';
        $lock = json_decode((string)@file_get_contents($lockFile), true);
    }
}

session_start();
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

function mmx_h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$flash = '';
$authed = !empty($_SESSION['mmx_recovery_ok']);

/* auth */
$token = isset($_GET['token']) ? (string)$_GET['token'] : (isset($_POST['token']) ? (string)$_POST['token'] : '');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if (!$lock || empty($lock['active'])) {
        $flash = 'Recovery is not active. Create a snapshot from MigrateMODX in the manager first.';
    } elseif (!empty($lock['locked_until']) && time() < (int)$lock['locked_until']) {
        $flash = 'Too many failures. Try again later.';
    } elseif ($token === '' || !hash_equals((string)$lock['token'], $token)) {
        $flash = 'Invalid token.';
    } elseif (!password_verify((string)$_POST['password'], (string)$lock['password_hash'])) {
        $lock['failures'] = isset($lock['failures']) ? ((int)$lock['failures'] + 1) : 1;
        if ($lock['failures'] >= 5) {
            $lock['locked_until'] = time() + 900;
            $lock['failures'] = 0;
        }
        file_put_contents($lockFile, json_encode($lock, JSON_PRETTY_PRINT));
        $flash = 'Invalid password.';
    } else {
        $_SESSION['mmx_recovery_ok'] = 1;
        $_SESSION['mmx_recovery_token'] = $token;
        $lock['failures'] = 0;
        $lock['locked_until'] = 0;
        file_put_contents($lockFile, json_encode($lock, JSON_PRETTY_PRINT));
        $authed = true;
        header('Location: index.php?token=' . rawurlencode($token));
        exit;
    }
}

if ($authed && isset($_SESSION['mmx_recovery_token'])) {
    $token = (string)$_SESSION['mmx_recovery_token'];
}

$action = isset($_POST['mmx_action']) ? (string)$_POST['mmx_action'] : '';
$result = null;
$journal = new MmxStateJournal($storePath);
$state = $journal->read();
$sm = new MmxSnapshotManager($storePath, $siteRoot, $config);
$snapshots = $sm->listSnapshots();

if ($authed && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'rollback' && !empty($_POST['snapshot_id'])) {
        $rb = new MmxRollbackManager($sm);
        $journal->write(array('phase' => 'recovery_rollback'));
        $snapId = (string)$_POST['snapshot_id'];
        $result = $rb->restore($snapId);
        if (!empty($result['ok'])) {
            $result['migrate_reset'] = MmxPostRollback::resetMigrateProgress(
                $storePath,
                $snapId,
                'recovery_rollback'
            );
            $result['message'] = (!empty($result['message']) ? $result['message'] . ' ' : '')
                . 'Migrate progress reset. Open MigrateMODX in the manager — audit runs automatically.';
        }
        $journal->log('Recovery rollback: ' . json_encode($result), !empty($result['ok']) ? 'info' : 'error');
        $state = $journal->read();
    } elseif ($action === 'quarantine_blockers') {
        $dataDir = $siteRoot . 'core/components/migratemodx/data';
        $vc = new MmxVendorCompat(null, $siteRoot, $storePath, $dataDir);
        $q = $vc->quarantineKnownBlockers(true);
        $cache = $vc->clearModxCache();
        $result = array(
            'ok' => !empty($q['ok']),
            'quarantine' => $q,
            'cache' => $cache,
            'message' => 'Quarantined known manager/setup killers (AdminTools, Batcher, Tickets) and cleared core/cache. Reload the manager.',
        );
        $journal->write(array('phase' => 'recovery_quarantine'));
        $journal->log('Recovery quarantine: ' . json_encode($result), !empty($result['ok']) ? 'info' : 'error');
        $state = $journal->read();
    } elseif ($action === 'logout') {
        $_SESSION = array();
        session_destroy();
        header('Location: index.php?token=' . rawurlencode($token));
        exit;
    } elseif ($action === 'download_report') {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="migratemodx-incident.json"');
        echo json_encode(array(
            'state' => $state,
            'diag' => mmx_diag($siteRoot, $config, $storePath),
            'snapshots' => $snapshots,
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function mmx_diag($siteRoot, $config, $storePath)
{
    $core = $siteRoot . 'core';
    $log = $core . '/cache/logs/error.log';
    $tail = '';
    if (is_file($log)) {
        $lines = @file($log);
        if ($lines) {
            $tail = implode('', array_slice($lines, -60));
        }
    }
    $dbOk = null;
    if ($config && !empty($config['dbase'])) {
        try {
            $dsn = !empty($config['database_dsn'])
                ? $config['database_dsn']
                : 'mysql:host=' . $config['database_server'] . ';dbname=' . $config['dbase'] . ';charset=utf8';
            new PDO($dsn, $config['database_user'], $config['database_password']);
            $dbOk = true;
        } catch (Exception $e) {
            $dbOk = false;
        }
    }
    return array(
        'php_version' => PHP_VERSION,
        'site_root' => $siteRoot,
        'core_exists' => is_dir($core),
        'config_loaded' => is_array($config),
        'db_connect' => $dbOk,
        'store_path' => $storePath,
        'error_log_tail' => $tail,
    );
}

$diag = mmx_diag($siteRoot, $config, $storePath);
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>MigrateMODX Recovery</title>
  <link rel="stylesheet" href="recovery.css">
</head>
<body>
<main class="wrap">
  <h1>MigrateMODX Recovery</h1>
  <p class="muted">Standalone console — does not load the MODX manager.</p>
  <?php if ($flash) { ?><div class="err"><?php echo mmx_h($flash); ?></div><?php } ?>

  <?php if (!$authed) { ?>
    <form method="post" class="card">
      <input type="hidden" name="token" value="<?php echo mmx_h($token); ?>">
      <label>Recovery password
        <input type="password" name="password" required autocomplete="current-password">
      </label>
      <button type="submit">Unlock</button>
      <p class="muted">Token must match the one issued when the snapshot was created.</p>
    </form>
  <?php } else { ?>

    <?php if ($result) { ?>
      <div class="<?php echo !empty($result['ok']) ? 'ok' : 'err'; ?>">
        <?php
          if (!empty($result['message'])) {
              echo mmx_h($result['message']);
          } else {
              echo 'Rollback: ' . (!empty($result['ok']) ? 'OK' : mmx_h(json_encode($result)));
          }
        ?>
        <?php if (!empty($result['quarantine'])) { ?>
          <pre><?php echo mmx_h(json_encode($result['quarantine'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
        <?php } ?>
      </div>
    <?php } ?>

    <section class="card">
      <h2>Broken manager after /setup/?</h2>
      <p>Stock <strong>Tickets</strong> (and AdminTools/Batcher) break MODX 3 manager UI. Quarantine renames their folders to <code>.__disabled_for_modx3</code> and clears <code>core/cache</code>.</p>
      <form method="post" onsubmit="return confirm('Quarantine AdminTools, Batcher, Tickets now?');">
        <input type="hidden" name="token" value="<?php echo mmx_h($token); ?>">
        <input type="hidden" name="mmx_action" value="quarantine_blockers">
        <button type="submit">Quarantine known blockers + clear cache</button>
      </form>
      <p class="muted">Or via FTP rename <code>core/components/tickets</code> and <code>assets/components/tickets</code>, then delete contents of <code>core/cache/</code>.</p>
    </section>

    <section class="card">
      <h2>Incident status</h2>
      <p>Phase: <strong><?php echo mmx_h(isset($state['phase']) ? $state['phase'] : 'unknown'); ?></strong></p>
      <p>Target: <?php echo mmx_h(isset($state['target_version']) ? $state['target_version'] : '—'); ?></p>
      <p>Last error: <?php echo mmx_h(isset($state['last_error']) ? $state['last_error'] : '—'); ?></p>
      <p>Updated: <?php echo mmx_h(isset($state['updated_at']) ? $state['updated_at'] : '—'); ?></p>
      <pre><?php
        $msgs = isset($state['messages']) ? $state['messages'] : array();
        $msgs = array_slice($msgs, -40);
        echo mmx_h(json_encode($msgs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
      ?></pre>
    </section>

    <section class="card">
      <h2>Diagnostics</h2>
      <ul>
        <li>PHP: <?php echo mmx_h($diag['php_version']); ?></li>
        <li>Core exists: <?php echo $diag['core_exists'] ? 'yes' : 'no'; ?></li>
        <li>Config loaded: <?php echo $diag['config_loaded'] ? 'yes' : 'no'; ?></li>
        <li>DB connect: <?php echo $diag['db_connect'] === null ? 'n/a' : ($diag['db_connect'] ? 'yes' : 'no'); ?></li>
      </ul>
      <h3>error.log (tail)</h3>
      <pre><?php echo mmx_h($diag['error_log_tail'] ? $diag['error_log_tail'] : '(empty)'); ?></pre>
    </section>

    <section class="card">
      <h2>Snapshots</h2>
      <?php if (!$snapshots) { ?>
        <p>No snapshots found in store.</p>
      <?php } else { ?>
        <table>
          <tr><th>ID</th><th>Created</th><th></th></tr>
          <?php foreach ($snapshots as $s) { ?>
            <tr>
              <td><?php echo mmx_h($s['id']); ?></td>
              <td><?php echo mmx_h(isset($s['created_at']) ? $s['created_at'] : ''); ?></td>
              <td>
                <form method="post" onsubmit="return confirm('Restore this snapshot?');">
                  <input type="hidden" name="token" value="<?php echo mmx_h($token); ?>">
                  <input type="hidden" name="mmx_action" value="rollback">
                  <input type="hidden" name="snapshot_id" value="<?php echo mmx_h($s['id']); ?>">
                  <button type="submit">Rollback</button>
                </form>
              </td>
            </tr>
          <?php } ?>
        </table>
      <?php } ?>
    </section>

    <section class="card actions">
      <form method="post">
        <input type="hidden" name="token" value="<?php echo mmx_h($token); ?>">
        <input type="hidden" name="mmx_action" value="download_report">
        <button type="submit">Download incident report</button>
      </form>
      <form method="post">
        <input type="hidden" name="token" value="<?php echo mmx_h($token); ?>">
        <input type="hidden" name="mmx_action" value="logout">
        <button type="submit">Logout</button>
      </form>
    </section>
    <p class="muted">Deactivate Recovery from the MigrateMODX manager page after a successful migration.</p>
  <?php } ?>
</main>
</body>
</html>
