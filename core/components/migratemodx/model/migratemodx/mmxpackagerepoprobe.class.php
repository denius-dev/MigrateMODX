<?php
/**
 * Probe official MODX extras + modstore.pro for installed packages.
 * Failures (timeouts, downtime) never break the audit — they are reported.
 */
class MmxPackageRepoProbe
{
    protected $cacheDir;
    protected $timeout;
    protected $ttl;
    protected $targetModx;
    protected $repos;
    /** @var callable|null fn(string $url, int $timeout): string|false */
    protected $httpGet;

    public function __construct(array $opts = array())
    {
        $this->cacheDir = isset($opts['cache_dir']) ? rtrim(str_replace('\\', '/', $opts['cache_dir']), '/') . '/' : '';
        $this->timeout = isset($opts['timeout']) ? max(2, (int)$opts['timeout']) : 8;
        $this->ttl = isset($opts['ttl']) ? max(60, (int)$opts['ttl']) : 86400;
        $this->targetModx = isset($opts['target_modx']) ? (string)$opts['target_modx'] : '3.0.3';
        $this->repos = isset($opts['repos']) && is_array($opts['repos']) ? $opts['repos'] : array(
            'modx' => 'https://rest.modx.com/extras/',
            'modstore' => 'https://modstore.pro/extras/',
        );
        $this->httpGet = isset($opts['http_get']) && is_callable($opts['http_get']) ? $opts['http_get'] : null;
        if ($this->cacheDir !== '' && !is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * @param string[] $packageNames lowercase package names
     * @return array{packages:array,repos:array}
     */
    public function probe(array $packageNames)
    {
        $packageNames = array_values(array_unique(array_filter(array_map('strtolower', $packageNames))));
        $repoMeta = array();
        $byPkg = array();
        foreach ($packageNames as $n) {
            $byPkg[$n] = array(
                'name' => $n,
                'hits' => array(),
                'best' => null,
            );
        }

        foreach ($this->repos as $repoId => $baseUrl) {
            $repoMeta[$repoId] = array(
                'id' => $repoId,
                'url' => $baseUrl,
                'ok' => false,
                'reachable' => false,
                'error' => '',
                'checked' => 0,
                'hits' => 0,
            );
            if ($baseUrl === '' || $baseUrl === '0') {
                $repoMeta[$repoId]['error'] = 'disabled';
                continue;
            }
            $alive = $this->pingRepo($repoId, $baseUrl);
            $repoMeta[$repoId]['reachable'] = $alive['ok'];
            $repoMeta[$repoId]['error'] = $alive['error'];
            if (!$alive['ok']) {
                continue;
            }
            $repoMeta[$repoId]['ok'] = true;
            foreach ($packageNames as $name) {
                $repoMeta[$repoId]['checked']++;
                $hit = $this->lookupPackage($repoId, $baseUrl, $name);
                if ($hit && !empty($hit['found'])) {
                    $repoMeta[$repoId]['hits']++;
                    $byPkg[$name]['hits'][$repoId] = $hit;
                    $byPkg[$name]['best'] = $this->pickBest($byPkg[$name]['best'], $hit);
                }
            }
        }

        return array(
            'packages' => $byPkg,
            'repos' => $repoMeta,
        );
    }

    /**
     * Merge probe result into an inventory row (mutates and returns it).
     */
    public function applyToRow(array $row, array $probePkg)
    {
        $best = isset($probePkg['best']) ? $probePkg['best'] : null;
        $row['repo_hits'] = isset($probePkg['hits']) ? $probePkg['hits'] : array();
        $row['repo_latest'] = '';
        $row['repo_signature'] = '';
        $row['repo_source'] = '';
        $row['repo_supports'] = '';
        $row['repo_minimum_supports'] = '';
        $row['repo_php_min'] = '';
        $row['repo_modx3'] = false;
        $row['repo_found'] = false;

        if (!$best || empty($best['found'])) {
            return $row;
        }

        $row['repo_found'] = true;
        $row['repo_latest'] = isset($best['version']) ? (string)$best['version'] : '';
        $row['repo_signature'] = isset($best['signature']) ? (string)$best['signature'] : '';
        $row['repo_source'] = isset($best['repo']) ? (string)$best['repo'] : '';
        $row['repo_supports'] = isset($best['supports']) ? (string)$best['supports'] : '';
        $row['repo_minimum_supports'] = isset($best['minimum_supports']) ? (string)$best['minimum_supports'] : '';
        $row['repo_php_min'] = isset($best['php_min']) ? (string)$best['php_min'] : '';
        $row['repo_modx3'] = !empty($best['modx3']);

        $installed = isset($row['version']) ? (string)$row['version'] : '';
        $latest = $row['repo_latest'];
        $evidenceWas = isset($row['evidence']) ? $row['evidence'] : 'unknown';

        if ($row['repo_modx3']) {
            if ($installed !== '' && $latest !== ''
                && version_compare($this->normalizeVersion($installed), $this->normalizeVersion($latest), '<')
            ) {
                if (in_array($row['verdict'], array('unknown', 'ok'), true)) {
                    $row['verdict'] = 'update';
                    $row['resolution'] = 'needs_update';
                    $row['version_status'] = 'outdated';
                    $row['required_version'] = $latest;
                } elseif ($row['verdict'] === 'update' && $row['required_version'] === '') {
                    $row['required_version'] = $latest;
                    $row['version_status'] = 'outdated';
                }
                $note = 'Repo ' . $row['repo_source'] . ' has ' . $row['repo_signature']
                    . ' (supports MODX ' . $row['repo_supports'] . ').';
                $row['notes'] = trim($row['notes'] . ($row['notes'] !== '' ? ' ' : '') . $note);
            } elseif (in_array($row['verdict'], array('unknown'), true)) {
                $row['verdict'] = 'ok';
                $row['status'] = 'compatible_from';
                $row['resolution'] = 'ok';
                $row['notes'] = trim($row['notes'] . ($row['notes'] !== '' ? ' ' : '')
                    . 'Found MODX 3–ready build on ' . $row['repo_source'] . ': ' . $row['repo_signature'] . '.');
            }
            if ($evidenceWas === 'unknown' || $evidenceWas === 'kb') {
                $row['evidence'] = $evidenceWas === 'kb' ? 'kb+repo' : 'repo';
            }
        } else {
            /* Package exists on repo but no MODX 3–supporting build found. */
            if ($row['verdict'] === 'unknown') {
                $row['notes'] = trim($row['notes'] . ($row['notes'] !== '' ? ' ' : '')
                    . 'On ' . $row['repo_source'] . ' latest is ' . $row['repo_signature']
                    . ' (supports ' . ($row['repo_supports'] !== '' ? $row['repo_supports'] : '?')
                    . ') — no MODX 3 build detected.');
                $row['evidence'] = 'repo';
            }
        }

        return $row;
    }

    protected function pingRepo($repoId, $baseUrl)
    {
        $cacheKey = 'ping_' . $repoId;
        $cached = $this->readCache($cacheKey);
        if (is_array($cached) && isset($cached['ok'])) {
            return $cached;
        }
        /* Lightweight request: empty package list / root extras */
        $url = rtrim($baseUrl, '/') . '/package?query=migratemodx_ping&limit=1&supports='
            . rawurlencode($this->supportsParam());
        $body = $this->httpGet($url);
        $out = array('ok' => false, 'error' => '');
        if ($body === false || $body === '') {
            $out['error'] = 'unreachable';
        } elseif (stripos($body, '<html') !== false && stripos($body, '<item') === false) {
            $out['error'] = 'non_xml';
        } elseif (stripos($body, '<?xml') !== false || stripos($body, '<item') !== false) {
            $out['ok'] = true;
        } else {
            /* modstore may return empty/error JSON when down partially */
            $out['error'] = 'unexpected_response';
        }
        /* Cache ping briefly (15 min) so downtime is not re-probed every package */
        $this->writeCache($cacheKey, $out, 900);
        return $out;
    }

    protected function lookupPackage($repoId, $baseUrl, $name)
    {
        $cacheKey = 'pkg_' . $repoId . '_' . preg_replace('/[^a-z0-9_\-]/', '', $name);
        $cached = $this->readCache($cacheKey);
        if (is_array($cached) && isset($cached['found'])) {
            return $cached;
        }

        $hit = $this->fetchBestMatch($repoId, $baseUrl, $name, true);
        if (!$hit['found']) {
            $hit = $this->fetchBestMatch($repoId, $baseUrl, $name, false);
        }
        $this->writeCache($cacheKey, $hit, $this->ttl);
        return $hit;
    }

    protected function fetchBestMatch($repoId, $baseUrl, $name, $forModx3)
    {
        $params = array(
            'query' => $name,
            'limit' => 25,
        );
        if ($forModx3) {
            $params['supports'] = $this->supportsParam();
        }
        $url = rtrim($baseUrl, '/') . '/package?' . http_build_query($params);
        $body = $this->httpGet($url);
        $empty = array(
            'found' => false,
            'repo' => $repoId,
            'modx3' => false,
            'version' => '',
            'signature' => '',
            'supports' => '',
            'minimum_supports' => '',
            'php_min' => '',
            'name' => '',
        );
        if ($body === false || $body === '' || (stripos($body, '<item') === false && stripos($body, '<?xml') === false)) {
            return $empty;
        }
        $items = $this->parsePackageXml($body);
        $best = null;
        foreach ($items as $it) {
            if (!$this->isNameMatch($name, $it)) {
                continue;
            }
            $it['repo'] = $repoId;
            $it['modx3'] = $this->supportsModx3($it['supports'], $it['minimum_supports']);
            if ($forModx3 && !$it['modx3']) {
                continue;
            }
            $best = $this->pickBest($best, $it);
        }
        if (!$best) {
            return $empty;
        }
        $best['found'] = true;
        return $best;
    }

    protected function parsePackageXml($xml)
    {
        $out = array();
        $prev = libxml_use_internal_errors(true);
        $sx = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$sx) {
            return $out;
        }
        $nodes = array();
        if (isset($sx->item)) {
            foreach ($sx->item as $child) {
                /* Outer wrapper also named item — only leaf packages have signature */
                if (isset($child->signature) || isset($child->name)) {
                    $nodes[] = $child;
                }
                if (isset($child->item)) {
                    foreach ($child->item as $inner) {
                        $nodes[] = $inner;
                    }
                }
            }
        }
        foreach ($nodes as $n) {
            $sig = isset($n->signature) ? (string)$n->signature : '';
            $displayName = isset($n->display_name) ? (string)$n->display_name : '';
            if ($sig === '' && $displayName !== '') {
                $sig = $displayName;
            }
            $display = isset($n->name) ? (string)$n->name : '';
            $ver = isset($n->version) ? (string)$n->version : '';
            if ($ver === '' && $sig !== '' && preg_match('/-(\d+\.\d+(?:\.\d+)?)/', $sig, $m)) {
                $ver = $m[1];
            }
            $supports = isset($n->supports) ? (string)$n->supports : '';
            $minSup = isset($n->minimum_supports) ? (string)$n->minimum_supports : '';
            $location = isset($n->location) ? (string)$n->location : '';
            $phpMin = '';
            foreach (array('php_min', 'php_version', 'minimum_php', 'php') as $phpField) {
                if (isset($n->{$phpField}) && trim((string)$n->{$phpField}) !== '') {
                    $phpMin = trim((string)$n->{$phpField});
                    break;
                }
            }
            if ($sig === '' && $display === '') {
                continue;
            }
            $out[] = array(
                'found' => true,
                'signature' => $sig,
                'name' => $display,
                'version' => $ver,
                'supports' => $supports,
                'minimum_supports' => $minSup,
                'php_min' => $phpMin,
                'location' => $location,
                'modx3' => false,
                'repo' => '',
            );
        }
        return $out;
    }

    protected function isNameMatch($packageName, array $it)
    {
        $pkg = strtolower($packageName);
        $sig = strtolower(isset($it['signature']) ? $it['signature'] : '');
        $name = strtolower(preg_replace('/[^a-z0-9_\-]/', '', isset($it['name']) ? $it['name'] : ''));
        if ($sig === $pkg || $name === $pkg) {
            return true;
        }
        if ($sig !== '' && (strpos($sig, $pkg . '-') === 0 || $sig === $pkg)) {
            return true;
        }
        /* display "FormIt" vs package formit */
        if ($name !== '' && $name === $pkg) {
            return true;
        }
        return false;
    }

    protected function supportsModx3($supports, $minimumSupports = '')
    {
        foreach (array($supports, $minimumSupports) as $s) {
            $s = trim((string)$s);
            if ($s === '') {
                continue;
            }
            if (preg_match('/\b3(\.\d+)?\b/', $s)) {
                return true;
            }
            if (stripos($s, 'revolution-3') !== false) {
                return true;
            }
        }
        return false;
    }

    protected function pickBest($current, $candidate)
    {
        if (!$candidate || empty($candidate['found']) && empty($candidate['signature']) && empty($candidate['version'])) {
            /* allow candidate with found flag set later */
        }
        if (!$current) {
            return $candidate;
        }
        $preferNew = !empty($candidate['modx3']) && empty($current['modx3']);
        if ($preferNew) {
            return $candidate;
        }
        if (!empty($current['modx3']) && empty($candidate['modx3'])) {
            return $current;
        }
        $cv = $this->normalizeVersion(isset($current['version']) ? $current['version'] : '');
        $nv = $this->normalizeVersion(isset($candidate['version']) ? $candidate['version'] : '');
        if (version_compare($nv, $cv, '>')) {
            return $candidate;
        }
        return $current;
    }

    protected function supportsParam()
    {
        $v = $this->normalizeVersion($this->targetModx);
        return 'Revolution-' . $v . '-pl';
    }

    protected function normalizeVersion($v)
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
            curl_setopt($ch, CURLOPT_USERAGENT, 'MigrateMODX/1.1 (+https://modx.com)');
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Accept: application/xml,text/xml,*/*',
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
                'header' => "Accept: application/xml\r\nUser-Agent: MigrateMODX/1.1\r\n",
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
        $file = $this->cacheDir . $key . '.json';
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string)@file_get_contents($file), true);
        if (!is_array($data) || !isset($data['_expires']) || $data['_expires'] < time()) {
            return null;
        }
        return isset($data['payload']) ? $data['payload'] : null;
    }

    protected function writeCache($key, $payload, $ttl)
    {
        if ($this->cacheDir === '') {
            return;
        }
        $file = $this->cacheDir . $key . '.json';
        @file_put_contents($file, json_encode(array(
            '_expires' => time() + (int)$ttl,
            'payload' => $payload,
        )));
    }
}
