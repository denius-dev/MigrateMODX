<?php
require_once dirname(__FILE__) . '/mmxpaths.class.php';

/**
 * Discover current MODX / package versions and compute effective targets.
 * Failures are soft: falls back to floors (DEFAULT_TARGET_*) and KB.
 */
class MmxVersionCatalog
{
    protected $cacheDir;
    protected $timeout;
    protected $ttl;
    /** @var callable|null */
    protected $httpGet;

    public function __construct(array $opts = array())
    {
        $this->cacheDir = isset($opts['cache_dir']) ? rtrim(str_replace('\\', '/', $opts['cache_dir']), '/') . '/' : '';
        $this->timeout = isset($opts['timeout']) ? max(2, (int)$opts['timeout']) : 10;
        $this->ttl = isset($opts['ttl']) ? max(60, (int)$opts['ttl']) : 21600;
        $this->httpGet = isset($opts['http_get']) && is_callable($opts['http_get']) ? $opts['http_get'] : null;
        if ($this->cacheDir !== '' && !is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * @param array $opts setting_modx, setting_php, packages (inventory rows), kb (MmxKnowledgeBase|null)
     * @return array
     */
    public function resolve(array $opts = array())
    {
        $settingModx = isset($opts['setting_modx']) ? trim((string)$opts['setting_modx']) : '';
        $settingPhp = isset($opts['setting_php']) ? trim((string)$opts['setting_php']) : '';
        $packages = isset($opts['packages']) && is_array($opts['packages']) ? $opts['packages'] : array();
        $kb = isset($opts['kb']) ? $opts['kb'] : null;

        $modxDisc = $this->discoverModxReleases();
        $ms3Docs = $this->discoverMiniShop3Docs();

        $reqModx = MmxPaths::DEFAULT_TARGET_MODX; /* floor for MS3 path */
        $reqPhp = MmxPaths::DEFAULT_TARGET_PHP;
        $drivers = array(
            array(
                'source' => 'floor',
                'name' => 'migratemodx',
                'min_modx' => MmxPaths::DEFAULT_TARGET_MODX,
                'min_php' => MmxPaths::DEFAULT_TARGET_PHP,
                'note' => 'Safe floor for MiniShop3-era migrations',
            ),
        );

        if (!empty($modxDisc['php_min'])) {
            $reqPhp = $this->maxVersion($reqPhp, $modxDisc['php_min']);
            $drivers[] = array(
                'source' => 'modx_core',
                'name' => 'modx',
                'min_modx' => isset($modxDisc['latest_3']) ? $modxDisc['latest_3'] : '',
                'min_php' => $modxDisc['php_min'],
                'note' => 'MODX 3 core PHP minimum (fallback/catalog)',
            );
        }

        if (!empty($ms3Docs['min_modx']) || !empty($ms3Docs['min_php'])) {
            if (!empty($ms3Docs['min_modx'])) {
                $reqModx = $this->maxVersion($reqModx, $ms3Docs['min_modx']);
            }
            if (!empty($ms3Docs['min_php'])) {
                $reqPhp = $this->maxVersion($reqPhp, $ms3Docs['min_php']);
            }
            $drivers[] = array(
                'source' => 'docs',
                'name' => 'minishop3',
                'min_modx' => isset($ms3Docs['min_modx']) ? $ms3Docs['min_modx'] : '',
                'min_php' => isset($ms3Docs['min_php']) ? $ms3Docs['min_php'] : '',
                'note' => 'docs.modx.pro MiniShop3 quick-start',
            );
        }

        $pkgReqs = $this->collectPackageRequirements($packages, $kb);
        foreach ($pkgReqs as $row) {
            if (!empty($row['min_modx'])) {
                $reqModx = $this->maxVersion($reqModx, $row['min_modx']);
            }
            if (!empty($row['min_php'])) {
                $reqPhp = $this->maxVersion($reqPhp, $row['min_php']);
            }
            $drivers[] = $row;
        }

        $latest3 = isset($modxDisc['latest_3']) ? $modxDisc['latest_3'] : '';
        $latest28 = isset($modxDisc['latest_28']) ? $modxDisc['latest_28'] : '2.8.8';

        $pinnedModx = $this->isAuto($settingModx) ? '' : $this->normalizeVersion($settingModx);
        $pinnedPhp = $this->isAuto($settingPhp) ? '' : $this->normalizeVersion($settingPhp);

        if ($pinnedModx !== '') {
            $effectiveModx = $pinnedModx;
            $modxMode = 'pinned';
        } else {
            /* Prefer newest MODX 3.x that still satisfies package floors */
            if ($latest3 !== '' && version_compare($latest3, $reqModx, '>=')) {
                $effectiveModx = $latest3;
                $modxMode = 'latest';
            } else {
                $effectiveModx = $reqModx;
                $modxMode = 'required_min';
            }
        }

        if ($pinnedPhp !== '') {
            $effectivePhp = $pinnedPhp;
            $phpMode = 'pinned';
        } else {
            $effectivePhp = $reqPhp;
            $phpMode = 'required_min';
        }

        $download = $this->downloadUrlFor($effectiveModx);

        return array(
            'ok' => true,
            'modx_latest_3' => $latest3,
            'modx_latest_28' => $latest28,
            'modx_php_min' => isset($modxDisc['php_min']) ? $modxDisc['php_min'] : '',
            'required_min_modx' => $reqModx,
            'required_min_php' => $reqPhp,
            'effective_modx' => $effectiveModx,
            'effective_php' => $effectivePhp,
            'modx_mode' => $modxMode,
            'php_mode' => $phpMode,
            'download_url' => $download['url'],
            'download_ok' => !empty($download['ok']),
            'drivers' => $drivers,
            'package_requirements' => $pkgReqs,
            'ms3_docs' => $ms3Docs,
            'modx_discovery' => $modxDisc,
            'checked_at' => date('c'),
        );
    }

    public function downloadUrlFor($version)
    {
        $v = $this->normalizeVersion($version);
        if ($v === '0.0.0') {
            return array('ok' => false, 'url' => '', 'version' => '');
        }
        $sig = $v . '-pl';
        $url = 'https://modx.com/download/direct/modx-' . $sig . '.zip';
        return array(
            'ok' => true,
            'url' => $url,
            'version' => $v,
            'signature' => $sig,
        );
    }

    public function isAuto($value)
    {
        $v = strtolower(trim((string)$value));
        return $v === '' || $v === 'auto' || $v === 'latest';
    }

    public function normalizeVersion($v)
    {
        $v = strtolower(trim((string)$v));
        $v = preg_replace('/^v/', '', $v);
        if (preg_match('/(\d+\.\d+\.\d+)/', $v, $m)) {
            return $m[1];
        }
        if (preg_match('/(\d+\.\d+)/', $v, $m)) {
            return $m[1] . '.0';
        }
        if (preg_match('/(\d+)/', $v, $m)) {
            return $m[1] . '.0.0';
        }
        return '0.0.0';
    }

    public function maxVersion($a, $b)
    {
        $na = $this->normalizeVersion($a);
        $nb = $this->normalizeVersion($b);
        return version_compare($nb, $na, '>') ? $nb : $na;
    }

    protected function discoverModxReleases()
    {
        $cacheKey = 'modx-releases-v1';
        $cached = $this->readCache($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $out = array(
            'latest_3' => '',
            'latest_28' => '',
            'php_min' => '8.1', /* MODX 3.2 docs: PHP 8.1 */
            'source' => '',
            'error' => '',
            'tags' => array(),
        );

        $body = $this->httpGet('https://api.github.com/repos/modxcms/revolution/tags?per_page=40');
        if ($body === false || $body === '') {
            $out['error'] = 'github_tags_unreachable';
            $out['latest_3'] = MmxPaths::DEFAULT_TARGET_MODX;
            $out['latest_28'] = '2.8.8';
            $this->writeCache($cacheKey, $out, 1800);
            return $out;
        }
        $json = json_decode($body, true);
        if (!is_array($json)) {
            $out['error'] = 'github_tags_bad_json';
            $out['latest_3'] = MmxPaths::DEFAULT_TARGET_MODX;
            $out['latest_28'] = '2.8.8';
            $this->writeCache($cacheKey, $out, 1800);
            return $out;
        }

        $tags3 = array();
        $tags28 = array();
        foreach ($json as $row) {
            if (!is_array($row) || empty($row['name'])) {
                continue;
            }
            $name = (string)$row['name'];
            if (!preg_match('/^v?(\d+\.\d+\.\d+)(?:-pl)?$/i', $name, $m)) {
                continue;
            }
            $ver = $m[1];
            $out['tags'][] = $ver;
            if (strpos($ver, '3.') === 0) {
                $tags3[] = $ver;
            } elseif (strpos($ver, '2.8.') === 0) {
                $tags28[] = $ver;
            }
        }
        usort($tags3, 'version_compare');
        usort($tags28, 'version_compare');
        $out['latest_3'] = $tags3 ? end($tags3) : MmxPaths::DEFAULT_TARGET_MODX;
        $out['latest_28'] = $tags28 ? end($tags28) : '2.8.8';
        $out['source'] = 'github_tags';
        /* MODX 3.1+/3.2 docs list PHP 8.1; keep floor, packages may raise to 8.2 */
        if ($out['latest_3'] !== '' && version_compare($out['latest_3'], '3.1.0', '>=')) {
            $out['php_min'] = '8.1';
        } else {
            $out['php_min'] = '7.2.5';
        }

        $this->writeCache($cacheKey, $out, $this->ttl);
        return $out;
    }

    protected function discoverMiniShop3Docs()
    {
        $cacheKey = 'ms3-docs-reqs-v1';
        $cached = $this->readCache($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $out = array(
            'min_modx' => '',
            'min_php' => '',
            'source' => '',
            'error' => '',
        );

        $urls = array(
            'https://docs.modx.pro/components/minishop3/quick-start',
            'https://docs.modx.pro/en/components/minishop3/',
        );
        foreach ($urls as $url) {
            $body = $this->httpGet($url);
            if ($body === false || strlen($body) < 200) {
                continue;
            }
            /* Cloudflare challenge pages are useless */
            if (stripos($body, 'Just a moment') !== false && stripos($body, 'challenge') !== false) {
                $out['error'] = 'cloudflare_challenge';
                continue;
            }
            if (preg_match('/MODX(?:\s+Revolution)?[^0-9]{0,40}(\d+\.\d+(?:\.\d+)?)\+/iu', $body, $m)) {
                $out['min_modx'] = $this->normalizeVersion($m[1]);
            }
            if (preg_match('/\bPHP[^0-9]{0,40}(\d+\.\d+(?:\.\d+)?)\+/iu', $body, $m)) {
                $out['min_php'] = $this->normalizeVersion($m[1]);
            }
            /* Table cells: MODX Revolution | 3.0.3+ */
            if ($out['min_modx'] === '' && preg_match('/Revolution<\/t[dh]>\s*<t[dh][^>]*>\s*(\d+\.\d+(?:\.\d+)?)\+/iu', $body, $m)) {
                $out['min_modx'] = $this->normalizeVersion($m[1]);
            }
            if ($out['min_php'] === '' && preg_match('/>PHP<\/t[dh]>\s*<t[dh][^>]*>\s*(\d+\.\d+(?:\.\d+)?)\+/iu', $body, $m)) {
                $out['min_php'] = $this->normalizeVersion($m[1]);
            }
            if ($out['min_modx'] !== '' || $out['min_php'] !== '') {
                $out['source'] = $url;
                break;
            }
        }

        if ($out['min_modx'] === '' && $out['min_php'] === '') {
            /* Hard fallback from known current docs when scrape blocked */
            $out['min_modx'] = '3.0.3';
            $out['min_php'] = '8.2';
            $out['source'] = 'fallback_kb';
            if ($out['error'] === '') {
                $out['error'] = 'docs_unreachable_used_fallback';
            }
        }

        $this->writeCache($cacheKey, $out, $this->ttl);
        return $out;
    }

    protected function collectPackageRequirements(array $packages, $kb)
    {
        $out = array();
        $seen = array();

        /* Always consider MiniShop3 / pdoTools floors when shop migration is relevant */
        $watch = array('minishop3', 'pdotools', 'vuetools');
        foreach ($packages as $p) {
            if (!empty($p['name'])) {
                $watch[] = strtolower((string)$p['name']);
            }
            if (!empty($p['successor'])) {
                $watch[] = strtolower((string)$p['successor']);
            }
        }
        $watch = array_values(array_unique($watch));

        foreach ($watch as $name) {
            if ($name === '' || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $minModx = '';
            $minPhp = '';
            $latest = '';
            $source = '';

            /* From live inventory / repo probe */
            foreach ($packages as $p) {
                if (!isset($p['name']) || strtolower((string)$p['name']) !== $name) {
                    continue;
                }
                if (!empty($p['repo_latest'])) {
                    $latest = (string)$p['repo_latest'];
                } elseif (!empty($p['version'])) {
                    $latest = (string)$p['version'];
                }
                if (!empty($p['repo_minimum_supports'])) {
                    $minModx = $this->normalizeVersion($p['repo_minimum_supports']);
                    $source = 'repo';
                } elseif (!empty($p['minimum_supports'])) {
                    $minModx = $this->normalizeVersion($p['minimum_supports']);
                    $source = 'repo';
                }
                if (!empty($p['repo_php_min'])) {
                    $minPhp = $this->normalizeVersion($p['repo_php_min']);
                    $source = $source === '' ? 'repo' : $source;
                }
                break;
            }

            /* KB floors */
            if ($kb && is_object($kb) && method_exists($kb, 'lookup')) {
                $row = $kb->lookup($name);
                if (is_array($row)) {
                    if ($minModx === '' && !empty($row['min_modx'])) {
                        $minModx = $this->normalizeVersion($row['min_modx']);
                        $source = $source === '' ? 'kb' : $source . '+kb';
                    }
                    if ($minPhp === '' && !empty($row['php'])) {
                        $minPhp = $this->normalizeVersion($row['php']);
                        $source = $source === '' ? 'kb' : $source . '+kb';
                    }
                }
            }

            if ($minModx === '' && $minPhp === '' && $latest === '') {
                continue;
            }
            $out[] = array(
                'source' => $source !== '' ? $source : 'inventory',
                'name' => $name,
                'latest' => $latest,
                'min_modx' => $minModx,
                'min_php' => $minPhp,
                'note' => 'package requirement',
            );
        }

        return $out;
    }

    protected function httpGet($url)
    {
        if ($this->httpGet) {
            return call_user_func($this->httpGet, $url, $this->timeout);
        }
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->timeout);
            curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
            curl_setopt($ch, CURLOPT_USERAGENT, 'MigrateMODX/1.3 (+version-catalog)');
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Accept: application/json,text/html,application/xml,*/*',
            ));
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($body === false || $code >= 500 || $code === 0) {
                return false;
            }
            return (string)$body;
        }
        $ctx = stream_context_create(array(
            'http' => array(
                'timeout' => $this->timeout,
                'header' => "Accept: application/json\r\nUser-Agent: MigrateMODX/1.3\r\n",
            ),
            'ssl' => array('verify_peer' => true, 'verify_peer_name' => true),
        ));
        $body = @file_get_contents($url, false, $ctx);
        return $body === false ? false : (string)$body;
    }

    protected function readCache($key)
    {
        if ($this->cacheDir === '') {
            return null;
        }
        $file = $this->cacheDir . sha1($key) . '.json';
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string)file_get_contents($file), true);
        if (!is_array($data) || empty($data['expires']) || $data['expires'] < time()) {
            return null;
        }
        return isset($data['payload']) ? $data['payload'] : null;
    }

    protected function writeCache($key, $payload, $ttl)
    {
        if ($this->cacheDir === '') {
            return;
        }
        $file = $this->cacheDir . sha1($key) . '.json';
        @file_put_contents($file, json_encode(array(
            'expires' => time() + (int)$ttl,
            'payload' => $payload,
        )));
    }
}
