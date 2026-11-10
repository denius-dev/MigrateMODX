<?php
/**
 * One-click first aid: quarantine manager/setup killers + clear cache.
 * Works without a living MODX manager. Gated by Recovery token (same as Recovery Console).
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');

$siteRoot = dirname(dirname(dirname(dirname(dirname(__FILE__))))) . '/';
$siteRoot = str_replace('\\', '/', $siteRoot);
$coreModel = $siteRoot . 'core/components/migratemodx/model/migratemodx/';

require_once $coreModel . 'mmxpaths.class.php';
require_once $coreModel . 'mmxstatejournal.class.php';
require_once $coreModel . 'mmxvendorcompat.class.php';

function mmx_fa_h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

$storePath = MmxPaths::resolveStorePath($siteRoot, '');
$lockFile = $storePath . 'recovery.lock';
$lock = null;
if (!is_file($lockFile)) {
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

$token = isset($_GET['token']) ? (string)$_GET['token'] : (isset($_POST['token']) ? (string)$_POST['token'] : '');
$go = !empty($_GET['go']) || (isset($_POST['mmx_action']) && $_POST['mmx_action'] === 'harden');
$result = null;
$error = '';

if (!$lock || empty($lock['active'])) {
    $error = 'Recovery is not active. Run MigrateMODX Prepare (snapshot) first, or restore Recovery from a snapshot.';
} elseif ($token === '' || !hash_equals((string)$lock['token'], $token)) {
    $error = 'Invalid or missing token.';
} elseif ($go) {
    $dataDir = $siteRoot . 'core/components/migratemodx/data';
    $vc = new MmxVendorCompat(null, $siteRoot, $storePath, $dataDir);
    $harden = $vc->hardenSite();
    $journal = new MmxStateJournal($storePath);
    $journal->write(array('phase' => 'firstaid_harden'));
    $journal->log('First aid harden: ' . json_encode($harden), !empty($harden['ok']) ? 'info' : 'error');
    $result = $harden;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>MigrateMODX First Aid</title>
  <style>
    body { font-family: system-ui, sans-serif; max-width: 40rem; margin: 2rem auto; padding: 0 1rem; line-height: 1.45; }
    .ok { background: #e8f7ee; border: 1px solid #8fd1a8; padding: 1rem; }
    .err { background: #fdecea; border: 1px solid #f5c2c0; padding: 1rem; }
    .card { border: 1px solid #ddd; padding: 1rem 1.25rem; margin: 1rem 0; }
    button, .btn { display: inline-block; background: #1a1a1a; color: #fff; border: 0; padding: .65rem 1rem; cursor: pointer; text-decoration: none; }
    pre { white-space: pre-wrap; font-size: .85rem; background: #f6f6f6; padding: .75rem; }
    .muted { color: #666; font-size: .9rem; }
  </style>
</head>
<body>
  <h1>MigrateMODX First Aid</h1>
  <p>Empty manager after /setup/? This quarantines Tickets / AdminTools / Batcher (and any extra still writing <code>map['modUser']</code>), disables matching plugins when possible, and clears <code>core/cache</code>.</p>

  <?php if ($error) { ?>
    <div class="err"><?php echo mmx_fa_h($error); ?></div>
  <?php } ?>

  <?php if ($result) { ?>
    <div class="<?php echo !empty($result['ok']) ? 'ok' : 'err'; ?>">
      <?php if (!empty($result['ok'])) { ?>
        <strong>Done.</strong> Reload the MODX manager. Killers on disk should be gone.
      <?php } else { ?>
        <strong>Partial.</strong> Some blockers may still be present — check the report and rename via FTP if needed.
      <?php } ?>
      <pre><?php echo mmx_fa_h(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
      <p><a class="btn" href="/manager/">Open manager</a></p>
    </div>
  <?php } elseif (!$error) { ?>
    <div class="card">
      <p>One click. No FTP. Does <strong>not</strong> restore a snapshot — only quarantines known manager killers.</p>
      <form method="post">
        <input type="hidden" name="token" value="<?php echo mmx_fa_h($token); ?>">
        <input type="hidden" name="mmx_action" value="harden">
        <button type="submit">Quarantine killers + clear cache</button>
      </form>
      <p class="muted">Same token as Recovery Console. Deactivate Recovery from MigrateMODX when migration is finished.</p>
    </div>
  <?php } ?>
</body>
</html>
