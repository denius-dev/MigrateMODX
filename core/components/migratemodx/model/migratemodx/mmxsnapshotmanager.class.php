<?php
require_once dirname(__FILE__) . '/mmxpaths.class.php';

/**
 * Snapshot files + DB. Usable with modX or with config array (Recovery).
 */
class MmxSnapshotManager
{
    protected $storePath;
    protected $siteRoot;
    protected $config;

    /**
     * @param string $storePath
     * @param string $siteRoot
     * @param array|null $config from MmxPaths::loadConfigInc or null when using modX later
     */
    public function __construct($storePath, $siteRoot, $config = null)
    {
        $this->storePath = rtrim(str_replace('\\', '/', $storePath), '/') . '/';
        $this->siteRoot = rtrim(str_replace('\\', '/', $siteRoot), '/') . '/';
        $this->config = $config;
        foreach (array($this->storePath, $this->storePath . 'snapshots/') as $d) {
            if (!is_dir($d)) {
                @mkdir($d, 0755, true);
            }
        }
    }

    public static function fromModX($modx)
    {
        $configured = $modx->getOption('migratemodx_store_path', null, '');
        $base = defined('MODX_BASE_PATH') ? MODX_BASE_PATH : dirname(rtrim($modx->getOption('core_path'), '/\\')) . '/';
        $store = MmxPaths::resolveStorePath($base, $configured);
        $cfg = MmxPaths::loadConfigInc($base);
        return new self($store, $base, $cfg);
    }

    public function listSnapshots()
    {
        $out = array();
        foreach (glob($this->storePath . 'snapshots/*/manifest.json') as $m) {
            $data = json_decode((string)file_get_contents($m), true);
            if (is_array($data)) {
                $data['path'] = dirname($m);
                $out[] = $data;
            }
        }
        usort($out, function ($a, $b) {
            return strcmp(isset($b['id']) ? $b['id'] : '', isset($a['id']) ? $a['id'] : '');
        });
        return $out;
    }

    public function create($label = '')
    {
        $id = date('Ymd-His');
        $dir = $this->storePath . 'snapshots/' . $id . '/';
        @mkdir($dir, 0755, true);
        @mkdir($dir . 'files/', 0755, true);

        $paths = array('core', 'manager', 'connectors');
        if (is_dir($this->siteRoot . 'setup')) {
            $paths[] = 'setup';
        }
        foreach (array('config.core.php', 'index.php') as $f) {
            if (is_file($this->siteRoot . $f)) {
                @copy($this->siteRoot . $f, $dir . 'files/_root_' . $f);
            }
        }

        $archived = array();
        foreach ($paths as $rel) {
            $src = $this->siteRoot . $rel;
            if (!is_dir($src)) {
                continue;
            }
            $zipFile = $dir . 'files/' . $rel . '.zip';
            if ($this->zipDir($src, $zipFile, $rel)) {
                $archived[] = array('path' => $rel, 'zip' => 'files/' . $rel . '.zip', 'sha256' => hash_file('sha256', $zipFile));
            }
        }

        $dbFile = $dir . 'database.sql';
        $dbOk = $this->dumpDatabase($dbFile);
        $manifest = array(
            'id' => $id,
            'label' => $label,
            'created_at' => date('c'),
            'site_root' => $this->siteRoot,
            'files' => $archived,
            'database' => $dbOk ? array('file' => 'database.sql', 'sha256' => hash_file('sha256', $dbFile)) : null,
            'php_version' => PHP_VERSION,
        );
        $manifest['checksum'] = hash('sha256', json_encode($archived) . ($dbOk ? $manifest['database']['sha256'] : ''));
        file_put_contents($dir . 'manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return $manifest;
    }

    public function verify($snapshotId)
    {
        $dir = $this->storePath . 'snapshots/' . $snapshotId . '/';
        $mfile = $dir . 'manifest.json';
        if (!is_file($mfile)) {
            return array('ok' => false, 'error' => 'manifest missing');
        }
        $manifest = json_decode((string)file_get_contents($mfile), true);
        if (!is_array($manifest)) {
            return array('ok' => false, 'error' => 'bad manifest');
        }
        foreach ($manifest['files'] as $f) {
            $zip = $dir . $f['zip'];
            if (!is_file($zip) || hash_file('sha256', $zip) !== $f['sha256']) {
                return array('ok' => false, 'error' => 'checksum fail: ' . $f['path']);
            }
        }
        if (!empty($manifest['database'])) {
            $db = $dir . $manifest['database']['file'];
            if (!is_file($db) || hash_file('sha256', $db) !== $manifest['database']['sha256']) {
                return array('ok' => false, 'error' => 'database checksum fail');
            }
        }
        return array('ok' => true, 'manifest' => $manifest);
    }

    protected function zipDir($src, $zipFile, $rootName)
    {
        if (!class_exists('ZipArchive')) {
            return $this->copyTree($src, dirname($zipFile) . '/' . $rootName);
        }
        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return false;
        }
        $src = rtrim(str_replace('\\', '/', $src), '/');
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            $local = $rootName . '/' . substr($path, strlen($src) + 1);
            if ($file->isDir()) {
                $zip->addEmptyDir($local);
            } else {
                /* skip our own huge store inside core/cache */
                if (strpos($local, '/cache/migratemodx/') !== false) {
                    continue;
                }
                $zip->addFile($path, $local);
            }
        }
        $zip->close();
        return is_file($zipFile);
    }

    protected function copyTree($src, $dst)
    {
        @mkdir($dst, 0755, true);
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            $target = $dst . '/' . $it->getSubPathName();
            if ($item->isDir()) {
                @mkdir($target, 0755, true);
            } else {
                @copy($item->getPathname(), $target);
            }
        }
        return true;
    }

    protected function dumpDatabase($outFile)
    {
        if (!$this->config || empty($this->config['dbase'])) {
            return false;
        }
        $host = $this->config['database_server'];
        $user = $this->config['database_user'];
        $pass = $this->config['database_password'];
        $db = $this->config['dbase'];

        /* try mysqldump */
        $mysqldump = $this->findMysqldump();
        if ($mysqldump) {
            $cmd = escapeshellarg($mysqldump)
                . ' --host=' . escapeshellarg($host)
                . ' --user=' . escapeshellarg($user)
                . ' --password=' . escapeshellarg($pass)
                . ' --single-transaction --quick '
                . escapeshellarg($db)
                . ' > ' . escapeshellarg($outFile);
            @exec($cmd, $o, $code);
            if ($code === 0 && is_file($outFile) && filesize($outFile) > 0) {
                return true;
            }
        }

        try {
            $dsn = !empty($this->config['database_dsn'])
                ? $this->config['database_dsn']
                : 'mysql:host=' . $host . ';dbname=' . $db . ';charset=utf8';
            $pdo = new PDO($dsn, $user, $pass, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
            $fh = fopen($outFile, 'wb');
            if (!$fh) {
                return false;
            }
            fwrite($fh, "-- MigrateMODX dump\nSET FOREIGN_KEY_CHECKS=0;\n");
            $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM);
            foreach ($tables as $row) {
                $table = $row[0];
                $ct = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`')->fetch(PDO::FETCH_NUM);
                fwrite($fh, "\nDROP TABLE IF EXISTS `" . str_replace('`', '``', $table) . "`;\n");
                fwrite($fh, $ct[1] . ";\n\n");
                $stmt = $pdo->query('SELECT * FROM `' . str_replace('`', '``', $table) . '`', PDO::FETCH_ASSOC);
                while ($r = $stmt->fetch()) {
                    $cols = array();
                    $vals = array();
                    foreach ($r as $k => $v) {
                        $cols[] = '`' . str_replace('`', '``', $k) . '`';
                        if ($v === null) {
                            $vals[] = 'NULL';
                        } else {
                            $vals[] = $pdo->quote($v);
                        }
                    }
                    fwrite($fh, 'INSERT INTO `' . str_replace('`', '``', $table) . '` (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ");\n");
                }
            }
            fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
            fclose($fh);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    protected function findMysqldump()
    {
        $candidates = array('mysqldump', 'mysqldump.exe');
        foreach ($candidates as $c) {
            $out = array();
            @exec((strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'where ' : 'which ') . $c, $out, $code);
            if ($code === 0 && !empty($out[0])) {
                return trim($out[0]);
            }
        }
        return null;
    }

    public function getStorePath()
    {
        return $this->storePath;
    }

    public function getSiteRoot()
    {
        return $this->siteRoot;
    }

    public function getConfig()
    {
        return $this->config;
    }
}
