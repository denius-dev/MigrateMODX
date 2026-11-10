<?php
require_once dirname(__FILE__) . '/mmxpaths.class.php';
require_once dirname(__FILE__) . '/mmxstatejournal.class.php';
require_once dirname(__FILE__) . '/mmxversioncatalog.class.php';

class MmxUpgradeOrchestrator
{
    /** @var modX */
    protected $modx;
    /** @var MmxStateJournal */
    protected $journal;
    protected $storePath;

    public function __construct($modx, MmxStateJournal $journal, $storePath)
    {
        $this->modx = $modx;
        $this->journal = $journal;
        $this->storePath = rtrim(str_replace('\\', '/', $storePath), '/') . '/';
    }

    public function plan()
    {
        $ver = $this->modx->getVersionData();
        $current = isset($ver['full_version']) ? $ver['full_version'] : '2.8.0';
        $steps = array();
        $resolved = null;
        if (class_exists('MmxVersionCatalog', false) || is_file(dirname(__FILE__) . '/mmxversioncatalog.class.php')) {
            if (!class_exists('MmxVersionCatalog', false)) {
                require_once dirname(__FILE__) . '/mmxversioncatalog.class.php';
            }
            $cat = new MmxVersionCatalog(array(
                'cache_dir' => $this->storePath . 'version-cache/',
                'timeout' => 10,
            ));
            $resolved = $cat->resolve(array(
                'setting_modx' => (string)$this->modx->getOption('migratemodx_target_modx', null, 'auto'),
                'setting_php' => (string)$this->modx->getOption('migratemodx_target_php', null, 'auto'),
            ));
        }
        $targetModx = is_array($resolved) && !empty($resolved['effective_modx'])
            ? $resolved['effective_modx']
            : MmxPaths::DEFAULT_TARGET_MODX;
        $targetPhp = is_array($resolved) && !empty($resolved['effective_php'])
            ? $resolved['effective_php']
            : MmxPaths::DEFAULT_TARGET_PHP;
        $latest28 = is_array($resolved) && !empty($resolved['modx_latest_28'])
            ? $resolved['modx_latest_28']
            : '2.8.8';
        if ((int)$ver['version'] === 2) {
            $steps[] = array('id' => 'to_28', 'label' => 'Ensure latest MODX 2.8.x', 'target' => $latest28);
            $steps[] = array(
                'id' => 'to_3',
                'label' => 'Upgrade to MODX ' . $targetModx . ' (live target from catalogs/package floors)',
                'target' => $targetModx,
            );
            $steps[] = array('id' => 'health', 'label' => 'Run health check', 'target' => '');
            $steps[] = array(
                'id' => 'php_host',
                'label' => 'Switch host PHP to ' . $targetPhp . '+ (manual on hosting)',
                'target' => $targetPhp,
            );
        } else {
            $steps[] = array('id' => 'already_3', 'label' => 'Site already on MODX 3 — use health / PHP prep only', 'target' => $current);
        }
        return array(
            'current' => $current,
            'steps' => $steps,
            'target_modx' => $targetModx,
            'target_php' => $targetPhp,
            'versions' => $resolved,
        );
    }

    /**
     * Download official MODX zip and extract into a staging folder (does not replace live files until confirm).
     */
    public function downloadTarget($version = null)
    {
        if ($version === null || $version === '' || strtolower((string)$version) === 'auto') {
            $plan = $this->plan();
            $version = !empty($plan['target_modx']) ? $plan['target_modx'] : MmxPaths::DEFAULT_TARGET_MODX;
        }
        $this->journal->write(array('phase' => 'download', 'target_version' => $version, 'last_error' => ''));
        $this->journal->log('Downloading MODX ' . $version);

        $catalog = new MmxVersionCatalog(array('cache_dir' => $this->storePath . 'version-cache/'));
        $dlMeta = $catalog->downloadUrlFor($version);
        if (empty($dlMeta['ok'])) {
            $this->journal->setError('Unknown version download URL: ' . $version);
            return array('ok' => false, 'error' => 'Unknown version: ' . $version);
        }
        $url = $dlMeta['url'];
        $version = !empty($dlMeta['signature']) ? $dlMeta['signature'] : $version;
        $dir = $this->storePath . 'distros/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $zip = $dir . 'modx-' . preg_replace('/[^0-9a-z.\-]/', '', $version) . '.zip';
        if (!is_file($zip) || filesize($zip) < 1000) {
            $data = $this->httpGet($url);
            if ($data === false || strlen($data) < 1000) {
                $this->journal->setError('Download failed: ' . $url);
                return array('ok' => false, 'error' => 'Download failed', 'url' => $url);
            }
            file_put_contents($zip, $data);
        }
        $this->journal->log('Distro ready: ' . $zip);
        return array('ok' => true, 'zip' => $zip, 'url' => $url);
    }

    /**
     * Apply distro over site root (dangerous). Requires confirm_staging + confirm_apply flags.
     */
    public function applyDistro($zipPath, $confirmStaging = false, $confirmApply = false)
    {
        if (!$confirmStaging || !$confirmApply) {
            return array('ok' => false, 'error' => 'Both confirm_staging and confirm_apply required');
        }
        if (!is_file($zipPath) || !class_exists('ZipArchive')) {
            return array('ok' => false, 'error' => 'Zip missing or ZipArchive unavailable');
        }
        $this->journal->write(array('phase' => 'apply', 'last_error' => ''));
        $this->journal->log('Applying distro ' . $zipPath);

        $tmp = $this->storePath . 'distros/extract-' . date('YmdHis') . '/';
        @mkdir($tmp, 0755, true);
        $za = new ZipArchive();
        if ($za->open($zipPath) !== true) {
            $this->journal->setError('Cannot open zip');
            return array('ok' => false, 'error' => 'Cannot open zip');
        }
        $za->extractTo($tmp);
        $za->close();

        /* find extracted root (modx-3.0.0-pl/) */
        $extractedRoot = $tmp;
        $subs = glob($tmp . 'modx-*', GLOB_ONLYDIR);
        if ($subs && is_dir($subs[0])) {
            $extractedRoot = rtrim(str_replace('\\', '/', $subs[0]), '/') . '/';
        }

        $base = MODX_BASE_PATH;
        foreach (array('manager', 'connectors', 'setup') as $dir) {
            if (is_dir($extractedRoot . $dir)) {
                $this->copyMerge($extractedRoot . $dir, $base . $dir);
            }
        }
        /* core except config and cache */
        if (is_dir($extractedRoot . 'core')) {
            $this->copyMerge($extractedRoot . 'core', $base . 'core', array('config', 'cache', 'components'));
        }
        foreach (array('index.php', 'ht.access', 'favicon.ico') as $f) {
            if (is_file($extractedRoot . $f)) {
                @copy($extractedRoot . $f, $base . $f);
            }
        }

        $this->journal->write(array('phase' => 'setup_pending'));
        $this->journal->log('Files applied. Open /setup/ to finish MODX upgrade, then run health check.');
        return array(
            'ok' => true,
            'message' => 'Core files merged. Visit /setup/ in the browser to complete the upgrade, then return to MigrateMODX health check.',
            'setup_url' => $this->modx->getOption('site_url') . 'setup/',
        );
    }

    protected function copyMerge($src, $dst, array $skipTop = array())
    {
        $src = rtrim(str_replace('\\', '/', $src), '/');
        $dst = rtrim(str_replace('\\', '/', $dst), '/');
        if (!is_dir($dst)) {
            @mkdir($dst, 0755, true);
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            $sub = substr(str_replace('\\', '/', $item->getPathname()), strlen($src) + 1);
            $top = explode('/', $sub);
            if ($skipTop && in_array($top[0], $skipTop, true)) {
                continue;
            }
            $target = $dst . '/' . $sub;
            if ($item->isDir()) {
                if (!is_dir($target)) {
                    @mkdir($target, 0755, true);
                }
            } else {
                $parent = dirname($target);
                if (!is_dir($parent)) {
                    @mkdir($parent, 0755, true);
                }
                @copy($item->getPathname(), $target);
            }
        }
    }

    protected function httpGet($url)
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 600,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT => 'MigrateMODX/1.0',
            ));
            $data = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code >= 200 && $code < 300 && $data !== false) {
                return $data;
            }
            return false;
        }
        $ctx = stream_context_create(array('http' => array('timeout' => 600, 'header' => "User-Agent: MigrateMODX/1.0\r\n")));
        $data = @file_get_contents($url, false, $ctx);
        return $data === false ? false : $data;
    }
}
