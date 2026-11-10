<?php
/**
 * Path helpers usable without a live modX instance (Recovery Console).
 */
class MmxPaths
{
    /** Default MODX 3 target — MiniShop3 current docs require 3.0.3+. */
    const DEFAULT_TARGET_MODX = '3.0.3';
    /** Default host PHP after shop migration — MiniShop3 requires 8.2+. */
    const DEFAULT_TARGET_PHP = '8.2';

    /**
     * Resolve site root from a known core path or from this package location.
     */
    public static function detectSiteRoot($hintCorePath = null)
    {
        if ($hintCorePath) {
            $core = rtrim(str_replace('\\', '/', $hintCorePath), '/');
            $parent = dirname($core);
            if (is_file($parent . '/config.core.php') || is_dir($core)) {
                return $parent . '/';
            }
        }
        /* assets/components/migratemodx/recovery → site root is 4 levels up from recovery, 5 from this file */
        $here = str_replace('\\', '/', dirname(__FILE__));
        /* .../core/components/migratemodx/model/migratemodx */
        $coreComp = dirname(dirname(dirname($here)));
        $core = dirname($coreComp);
        $root = dirname($core);
        if (is_file($root . '/config.core.php')) {
            return $root . '/';
        }
        return $root . '/';
    }

    /**
     * Snapshot store must live outside core/manager/connectors/setup.
     * Restoring those trees must never delete the archives being restored.
     */
    public static function defaultStorePath($siteRoot)
    {
        $siteRoot = rtrim(str_replace('\\', '/', $siteRoot), '/') . '/';
        return $siteRoot . 'migratemodx-data/';
    }

    public static function legacyStorePath($siteRoot)
    {
        $siteRoot = rtrim(str_replace('\\', '/', $siteRoot), '/') . '/';
        return $siteRoot . 'core/cache/migratemodx/';
    }

    /**
     * Prefer configured path; otherwise migratemodx-data/ (outside core).
     * One-shot migrate from legacy core/cache/migratemodx when needed.
     */
    public static function resolveStorePath($siteRoot, $configured = '')
    {
        $siteRoot = rtrim(str_replace('\\', '/', $siteRoot), '/') . '/';
        if (is_string($configured) && trim($configured) !== '') {
            $store = rtrim(str_replace('\\', '/', $configured), '/') . '/';
            self::ensureStoreDirs($store);
            return $store;
        }
        $preferred = self::defaultStorePath($siteRoot);
        $legacy = self::legacyStorePath($siteRoot);
        self::ensureStoreDirs($preferred);
        if (self::storeHasSnapshots($legacy) && !self::storeHasSnapshots($preferred)) {
            self::migrateStoreContents($legacy, $preferred);
        }
        return $preferred;
    }

    public static function ensureStoreDirs($storePath)
    {
        $storePath = rtrim(str_replace('\\', '/', $storePath), '/') . '/';
        foreach (array($storePath, $storePath . 'snapshots/', $storePath . 'patches/', $storePath . 'journals/') as $d) {
            if (!is_dir($d)) {
                @mkdir($d, 0755, true);
            }
        }
        $deny = $storePath . '.htaccess';
        if (!is_file($deny)) {
            @file_put_contents($deny, "Require all denied\nDeny from all\n");
        }
        $idx = $storePath . 'index.html';
        if (!is_file($idx)) {
            @file_put_contents($idx, '');
        }
    }

    public static function storeHasSnapshots($storePath)
    {
        $storePath = rtrim(str_replace('\\', '/', $storePath), '/') . '/';
        $glob = glob($storePath . 'snapshots/*/manifest.json');
        return is_array($glob) && count($glob) > 0;
    }

    public static function migrateStoreContents($from, $to)
    {
        $from = rtrim(str_replace('\\', '/', $from), '/') . '/';
        $to = rtrim(str_replace('\\', '/', $to), '/') . '/';
        if (!is_dir($from) || $from === $to) {
            return false;
        }
        self::ensureStoreDirs($to);
        foreach (array('snapshots', 'patches', 'journals') as $bucket) {
            $src = $from . $bucket;
            if (!is_dir($src)) {
                continue;
            }
            self::copyTreeRecursive($src, $to . $bucket);
        }
        foreach (array('accepted.json', 'last-audit.json', 'run.json', 'attention.json') as $f) {
            if (is_file($from . $f) && !is_file($to . $f)) {
                @copy($from . $f, $to . $f);
            }
        }
        return true;
    }

    public static function pathIsInside($path, $parent)
    {
        $path = rtrim(str_replace('\\', '/', $path), '/') . '/';
        $parent = rtrim(str_replace('\\', '/', $parent), '/') . '/';
        if ($parent === '/') {
            return false;
        }
        return strpos($path, $parent) === 0;
    }

    protected static function copyTreeRecursive($src, $dst)
    {
        $src = rtrim(str_replace('\\', '/', $src), '/');
        $dst = rtrim(str_replace('\\', '/', $dst), '/');
        if (!is_dir($dst)) {
            @mkdir($dst, 0755, true);
        }
        if (!is_dir($src)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            $target = $dst . '/' . str_replace('\\', '/', $it->getSubPathName());
            if ($item->isDir()) {
                if (!is_dir($target)) {
                    @mkdir($target, 0755, true);
                }
            } else {
                $dir = dirname($target);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }
                @copy($item->getPathname(), $target);
            }
        }
    }

    public static function loadConfigInc($siteRoot)
    {
        $siteRoot = rtrim(str_replace('\\', '/', $siteRoot), '/') . '/';
        $configCore = $siteRoot . 'config.core.php';
        if (!is_file($configCore)) {
            return null;
        }
        if (!defined('MODX_CORE_PATH')) {
            include $configCore;
        }
        if (!defined('MODX_CORE_PATH')) {
            return null;
        }
        $inc = MODX_CORE_PATH . 'config/' . (defined('MODX_CONFIG_KEY') ? MODX_CONFIG_KEY : 'config') . '.inc.php';
        if (!is_file($inc)) {
            return null;
        }
        $database_type = $database_server = $database_user = $database_password = $dbase = $table_prefix = null;
        $database_dsn = null;
        include $inc;
        return array(
            'core_path' => MODX_CORE_PATH,
            'database_type' => isset($database_type) ? $database_type : 'mysql',
            'database_server' => isset($database_server) ? $database_server : '',
            'database_user' => isset($database_user) ? $database_user : '',
            'database_password' => isset($database_password) ? $database_password : '',
            'dbase' => isset($dbase) ? trim($dbase, '`') : '',
            'table_prefix' => isset($table_prefix) ? $table_prefix : '',
            'database_dsn' => isset($database_dsn) ? $database_dsn : '',
            'site_root' => $siteRoot,
        );
    }
}
