<?php
require_once dirname(__FILE__) . '/mmxsnapshotmanager.class.php';

class MmxRollbackManager
{
    /** @var MmxSnapshotManager */
    protected $snapshots;

    public function __construct(MmxSnapshotManager $snapshots)
    {
        $this->snapshots = $snapshots;
    }

    public function restore($snapshotId)
    {
        $verify = $this->snapshots->verify($snapshotId);
        if (empty($verify['ok'])) {
            return $verify;
        }
        $manifest = $verify['manifest'];
        $store = $this->snapshots->getStorePath();
        $dir = $store . 'snapshots/' . $snapshotId . '/';
        $root = $this->snapshots->getSiteRoot();

        $workDir = $dir;
        $staged = null;
        if ($this->snapshotThreatenedByRestore($dir, $store, $root, $manifest)) {
            $staged = $this->stageSnapshotOutsideTargets($dir, $root, $snapshotId);
            if ($staged === null) {
                return array(
                    'ok' => false,
                    'errors' => array('cannot stage snapshot outside restore targets (store under core?)'),
                    'snapshot' => $snapshotId,
                );
            }
            $workDir = $staged;
        }

        $this->maintenance($root, true);
        $errors = array();

        foreach ($manifest['files'] as $f) {
            $zip = $workDir . $f['zip'];
            $target = $root . $f['path'];
            if (is_file($zip) && class_exists('ZipArchive')) {
                $this->rrmdir($target);
                @mkdir($target, 0755, true);
                $za = new ZipArchive();
                if ($za->open($zip) === true) {
                    /* zip entries are rootName/... */
                    $za->extractTo($root);
                    $za->close();
                } else {
                    $errors[] = 'unzip failed: ' . $f['path'];
                }
            } elseif (is_dir($workDir . 'files/' . $f['path'])) {
                $this->rrmdir($target);
                $this->copyTree($workDir . 'files/' . $f['path'], $target);
            } else {
                $errors[] = 'missing archive: ' . $f['path'];
            }
        }

        foreach (array('config.core.php', 'index.php') as $rf) {
            $bak = $workDir . 'files/_root_' . $rf;
            if (is_file($bak)) {
                @copy($bak, $root . $rf);
            }
        }

        if (!empty($manifest['database']['file'])) {
            $sql = $workDir . $manifest['database']['file'];
            if (!$this->importSql($sql)) {
                $errors[] = 'database import failed';
            }
        }

        $this->clearCache($root);
        $this->maintenance($root, false);

        if ($staged !== null) {
            $this->rrmdir(rtrim($staged, '/'));
        }

        return array(
            'ok' => count($errors) === 0,
            'errors' => $errors,
            'snapshot' => $snapshotId,
            'staged' => $staged !== null,
        );
    }

    /**
     * True when wiping a restore target would delete the snapshot/store itself.
     */
    protected function snapshotThreatenedByRestore($snapshotDir, $store, $root, array $manifest)
    {
        $paths = array();
        foreach ($manifest['files'] as $f) {
            if (!empty($f['path'])) {
                $paths[] = $root . $f['path'];
            }
        }
        foreach ($paths as $target) {
            if (MmxPaths::pathIsInside($snapshotDir, $target) || MmxPaths::pathIsInside($store, $target)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Copy snapshot to a work dir that is never part of core/manager/connectors/setup.
     */
    protected function stageSnapshotOutsideTargets($snapshotDir, $root, $snapshotId)
    {
        $safeBase = MmxPaths::defaultStorePath($root) . '.restore-staging/';
        if (!is_dir($safeBase)) {
            @mkdir($safeBase, 0755, true);
        }
        /* If even migratemodx-data is somehow under a wipe target, fall back to system temp */
        $dest = $safeBase . $snapshotId . '-' . substr(md5(uniqid('', true)), 0, 8) . '/';
        if (is_dir($dest)) {
            $this->rrmdir($dest);
        }
        if (!$this->copyTree($snapshotDir, $dest)) {
            $tmp = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/mmx-restore-' . $snapshotId . '-' . getmypid() . '/';
            if (is_dir($tmp)) {
                $this->rrmdir($tmp);
            }
            if (!$this->copyTree($snapshotDir, $tmp)) {
                return null;
            }
            return rtrim($tmp, '/') . '/';
        }
        return rtrim($dest, '/') . '/';
    }

    protected function importSql($sqlFile)
    {
        $cfg = $this->snapshots->getConfig();
        if (!$cfg || !is_file($sqlFile)) {
            return false;
        }
        $host = isset($cfg['database_server']) ? $cfg['database_server'] : 'localhost';
        $user = isset($cfg['database_user']) ? $cfg['database_user'] : '';
        $pass = isset($cfg['database_password']) ? $cfg['database_password'] : '';
        $db = isset($cfg['dbase']) ? $cfg['dbase'] : '';

        /* Prefer mysql CLI for large dumps when available */
        $mysqlBin = $this->findMysqlBinary();
        if ($mysqlBin !== '' && $db !== '') {
            $cmd = escapeshellarg($mysqlBin)
                . ' --default-character-set=utf8mb4'
                . ' -h ' . escapeshellarg($host)
                . ' -u ' . escapeshellarg($user)
                . ' ' . ($pass !== '' ? '-p' . escapeshellarg($pass) : '')
                . ' ' . escapeshellarg($db)
                . ' < ' . escapeshellarg($sqlFile)
                . ' 2>&1';
            $out = array();
            $code = 1;
            @exec($cmd, $out, $code);
            if ($code === 0) {
                return true;
            }
        }

        if (class_exists('mysqli')) {
            @$mysqli = new mysqli($host, $user, $pass, $db);
            if ($mysqli && !$mysqli->connect_errno) {
                $mysqli->set_charset('utf8mb4');
                $mysqli->query('SET FOREIGN_KEY_CHECKS=0');
                $ok = $this->mysqliImportFile($mysqli, $sqlFile);
                $mysqli->query('SET FOREIGN_KEY_CHECKS=1');
                $mysqli->close();
                if ($ok) {
                    return true;
                }
            }
        }

        try {
            $dsn = !empty($cfg['database_dsn'])
                ? $cfg['database_dsn']
                : 'mysql:host=' . $host . ';dbname=' . $db . ';charset=utf8mb4';
            $pdo = new PDO($dsn, $user, $pass, array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
            ));
            $sql = file_get_contents($sqlFile);
            if ($sql === false || $sql === '') {
                return false;
            }
            $pdo->exec($sql);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    protected function findMysqlBinary()
    {
        foreach (array('mysql', '/usr/bin/mysql', '/usr/local/bin/mysql') as $bin) {
            $out = array();
            $code = 1;
            @exec(escapeshellarg($bin) . ' --version 2>&1', $out, $code);
            if ($code === 0) {
                return $bin;
            }
        }
        return '';
    }

    /**
     * Stream-import SQL via mysqli (handles large dumps better than one PDO::exec).
     */
    protected function mysqliImportFile($mysqli, $sqlFile)
    {
        $fh = @fopen($sqlFile, 'rb');
        if (!$fh) {
            return false;
        }
        $buf = '';
        $ok = true;
        while (!feof($fh)) {
            $chunk = fread($fh, 1024 * 512);
            if ($chunk === false) {
                $ok = false;
                break;
            }
            $buf .= $chunk;
            $offset = 0;
            $len = strlen($buf);
            for ($i = 0; $i < $len; $i++) {
                if ($buf[$i] !== ';') {
                    continue;
                }
                /* naive statement end; good enough for mysqldump-style files */
                $stmt = substr($buf, $offset, $i - $offset + 1);
                $offset = $i + 1;
                $trim = trim($stmt);
                if ($trim === '' || strpos($trim, '--') === 0 || strpos($trim, '/*') === 0) {
                    continue;
                }
                if (!$mysqli->query($stmt)) {
                    /* keep going on non-fatal; stop only if connection dies */
                    if (!$mysqli->ping()) {
                        $ok = false;
                        break 2;
                    }
                }
            }
            $buf = substr($buf, $offset);
        }
        $tail = trim($buf);
        if ($ok && $tail !== '') {
            if (!$mysqli->query($tail) && !$mysqli->ping()) {
                $ok = false;
            }
        }
        fclose($fh);
        return $ok;
    }

    protected function maintenance($root, $on)
    {
        $file = rtrim($root, '/') . '/maintenance.html';
        if ($on) {
            @file_put_contents($file, '<!DOCTYPE html><title>Maintenance</title><h1>MigrateMODX restore in progress</h1>');
        } else {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    protected function clearCache($root)
    {
        $cache = rtrim($root, '/') . '/core/cache';
        if (!is_dir($cache)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($cache, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            if (strpos($path, '/cache/migratemodx') !== false) {
                continue;
            }
            if ($file->isDir()) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
    }

    protected function rrmdir($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
        @rmdir($dir);
    }

    protected function copyTree($src, $dst)
    {
        $src = rtrim(str_replace('\\', '/', $src), '/');
        $dst = rtrim(str_replace('\\', '/', $dst), '/');
        if (!is_dir($src)) {
            return false;
        }
        @mkdir($dst, 0755, true);
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            $target = $dst . '/' . str_replace('\\', '/', $it->getSubPathName());
            if ($item->isDir()) {
                @mkdir($target, 0755, true);
            } else {
                $dir = dirname($target);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }
                if (!@copy($item->getPathname(), $target)) {
                    return false;
                }
            }
        }
        return true;
    }
}
