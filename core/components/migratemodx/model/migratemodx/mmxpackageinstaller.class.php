<?php
/**
 * Download + install extras via MODX transport providers (rest.modx.com / modstore.pro).
 *
 * Plans successors (miniShop2→MiniShop3), KB requires[], and outdated MODX-3-ready updates.
 * Never uninstalls packages; never silent — caller must confirm.
 */
class MmxPackageInstaller
{
    /** @var modX */
    protected $modx;
    protected $storePath;
    /** @var array */
    protected $repoUrls;

    public function __construct($modx, $storePath, array $repoUrls = array())
    {
        $this->modx = $modx;
        $this->storePath = rtrim(str_replace('\\', '/', (string)$storePath), '/') . '/';
        $this->repoUrls = $repoUrls;
        if (empty($this->repoUrls['modx'])) {
            $this->repoUrls['modx'] = 'https://rest.modx.com/extras/';
        }
        if (empty($this->repoUrls['modstore'])) {
            $this->repoUrls['modstore'] = 'https://modstore.pro/extras/';
        }
    }

    /**
     * Build install/update plan from audit package rows (+ KB successors/requires).
     *
     * @param array $packages
     * @param array $kbPackages packages.json map
     * @return array{items:array,summary:array}
     */
    public function buildPlan(array $packages, array $kbPackages = array())
    {
        $installed = array();
        foreach ($packages as $p) {
            $n = strtolower(isset($p['name']) ? $p['name'] : '');
            if ($n !== '') {
                $installed[$n] = $p;
            }
        }
        $items = array();
        $seen = array();

        /* 1) Successors for replaced packages (miniShop2→MiniShop3, mSearch2→msCatalyst, …) */
        foreach ($packages as $p) {
            $successor = isset($p['successor']) ? strtolower((string)$p['successor']) : '';
            if ($successor === '') {
                continue;
            }
            $verdict = isset($p['verdict']) ? $p['verdict'] : '';
            $wantsSuccessor = ($verdict === 'replace')
                || !empty($p['migration'])
                || (!empty($p['resolution']) && $p['resolution'] === 'successor_installed');
            /* Always queue missing successor when KB/audit named one for an installed legacy package */
            if (!$wantsSuccessor && $verdict !== 'ok' && $verdict !== 'update') {
                $wantsSuccessor = true;
            }
            if (!$wantsSuccessor) {
                continue;
            }
            if (isset($installed[$successor])) {
                continue;
            }
            $this->queueInstall($items, $seen, $successor, 'successor', $p, $kbPackages);
        }

        /* 2) Explicit KB requires for already-queued / installed successors */
        foreach ($items as $it) {
            if ($it['action'] !== 'install') {
                continue;
            }
            $reqs = $this->kbRequires($it['name'], $kbPackages);
            foreach ($reqs as $req) {
                if (isset($installed[$req])) {
                    /* may still need update — handled below */
                    continue;
                }
                $this->queueInstall($items, $seen, $req, 'dependency', array('name' => $it['name']), $kbPackages);
            }
        }
        /* deps for MS3 even if user already installed it manually */
        if (isset($installed['minishop3']) || isset($seen['minishop3'])) {
            foreach ($this->kbRequires('minishop3', $kbPackages) as $req) {
                if (!isset($installed[$req])) {
                    $this->queueInstall($items, $seen, $req, 'dependency', array('name' => 'minishop3'), $kbPackages);
                }
            }
        }

        /* 3) Outdated packages with MODX 3–ready repo build */
        foreach ($packages as $p) {
            $name = strtolower(isset($p['name']) ? $p['name'] : '');
            if ($name === '' || isset($seen[$name])) {
                continue;
            }
            $needs = !empty($p['needs_update'])
                || (isset($p['version_status']) && $p['version_status'] === 'outdated')
                || (isset($p['verdict']) && $p['verdict'] === 'update')
                || (isset($p['resolution']) && $p['resolution'] === 'needs_update');
            if (!$needs) {
                continue;
            }
            /* Do not "update" a package marked replace — successor path handles it */
            if (isset($p['verdict']) && $p['verdict'] === 'replace') {
                continue;
            }
            $sig = '';
            if (!empty($p['repo_signature'])) {
                $sig = (string)$p['repo_signature'];
            } elseif (!empty($p['repo_best']['signature'])) {
                $sig = (string)$p['repo_best']['signature'];
            }
            $items[] = array(
                'name' => $name,
                'action' => 'update',
                'reason' => 'outdated',
                'signature' => $sig,
                'from' => isset($p['version']) ? (string)$p['version'] : '',
                'to' => isset($p['repo_latest']) ? (string)$p['repo_latest'] : '',
                'source' => isset($p['repo_source']) ? (string)$p['repo_source'] : '',
            );
            $seen[$name] = true;
        }

        /* deps → updates → successors (pdoTools/VueTools ready before MiniShop3) */
        usort($items, function ($a, $b) {
            $rank = array('dependency' => 0, 'outdated' => 1, 'successor' => 2);
            $ra = isset($rank[$a['reason']]) ? $rank[$a['reason']] : 9;
            $rb = isset($rank[$b['reason']]) ? $rank[$b['reason']] : 9;
            if ($ra !== $rb) {
                return $ra - $rb;
            }
            return strcmp($a['name'], $b['name']);
        });

        return array(
            'items' => $items,
            'summary' => array(
                'total' => count($items),
                'installs' => count(array_filter($items, function ($i) {
                    return $i['action'] === 'install';
                })),
                'updates' => count(array_filter($items, function ($i) {
                    return $i['action'] === 'update';
                })),
            ),
        );
    }

    /**
     * @param array $plan from buildPlan
     * @param bool $apply
     * @return array
     */
    public function run(array $plan, $apply = true)
    {
        $done = array();
        $failed = array();
        $needs = array();
        $items = isset($plan['items']) ? $plan['items'] : array();
        if (!$items) {
            return array(
                'ok' => true,
                'done' => array(),
                'failed' => array(),
                'needs_attention' => array(),
                'applied' => (bool)$apply,
                'installed_ms3' => $this->isPackageInstalled('minishop3'),
            );
        }

        $providers = null;
        if ($apply) {
            $providers = $this->ensureProviders();
            if (!$providers) {
                return array(
                    'ok' => false,
                    'done' => array(),
                    'failed' => array(array(
                        'id' => 'providers',
                        'message' => 'No transport providers available (rest.modx.com / modstore.pro)',
                    )),
                    'needs_attention' => array(),
                    'applied' => true,
                    'installed_ms3' => $this->isPackageInstalled('minishop3'),
                );
            }
        }

        foreach ($items as $item) {
            $name = $item['name'];
            $label = $item['action'] . ':' . $name
                . (!empty($item['signature']) ? ' (' . $item['signature'] . ')' : '');
            if (!$apply) {
                $done[] = array(
                    'id' => 'pkg_plan_' . $name,
                    'message' => 'Would ' . $item['action'] . ' «' . $name . '» (' . $item['reason'] . ')',
                    'item' => $item,
                    'applied' => false,
                );
                continue;
            }
            try {
                $result = $this->downloadAndInstall(
                    $name,
                    isset($item['signature']) ? $item['signature'] : '',
                    $providers
                );
                if (!empty($result['ok'])) {
                    $done[] = array(
                        'id' => 'pkg_' . $item['action'] . '_' . $name,
                        'message' => ucfirst($item['action']) . 'ed «' . $name . '»'
                            . (!empty($result['signature']) ? ' as ' . $result['signature'] : ''),
                        'item' => $item,
                        'detail' => $result,
                        'applied' => true,
                    );
                } else {
                    $msg = isset($result['error']) ? $result['error'] : 'install failed';
                    $failed[] = array(
                        'id' => 'pkg_fail_' . $name,
                        'message' => 'Could not ' . $item['action'] . ' «' . $name . '»: ' . $msg,
                        'item' => $item,
                        'detail' => $result,
                    );
                    if (!empty($result['manual'])) {
                        $needs[] = array(
                            'id' => 'pkg_manual_' . $name,
                            'severity' => 'hard',
                            'message' => 'Install «' . $name . '» manually via Package Management (provider auth or paid package).',
                            'name' => $name,
                        );
                    }
                }
            } catch (Exception $e) {
                $failed[] = array('id' => 'pkg_fail_' . $name, 'message' => $e->getMessage(), 'item' => $item);
            } catch (Throwable $e) {
                $failed[] = array('id' => 'pkg_fail_' . $name, 'message' => $e->getMessage(), 'item' => $item);
            }
        }

        if ($apply) {
            try {
                if (isset($this->modx->cacheManager) && is_object($this->modx->cacheManager)) {
                    $this->modx->cacheManager->refresh();
                }
            } catch (Exception $e) {
            } catch (Throwable $e) {
            }
            $this->writeJournal($done, $failed);
        }

        return array(
            'ok' => empty($failed),
            'done' => $done,
            'failed' => $failed,
            'needs_attention' => $needs,
            'applied' => (bool)$apply,
            'plan' => $plan,
            'installed_ms3' => $this->isPackageInstalled('minishop3'),
        );
    }

    protected function queueInstall(array &$items, array &$seen, $name, $reason, array $fromPkg, array $kb)
    {
        $name = strtolower($name);
        if ($name === '' || isset($seen[$name])) {
            return;
        }
        $sig = '';
        if (!empty($fromPkg['alternatives'][0]['signature'])) {
            $sig = (string)$fromPkg['alternatives'][0]['signature'];
        }
        if (isset($kb[$name]['alternatives'][0]['signature'])) {
            $sig = (string)$kb[$name]['alternatives'][0]['signature'];
        }
        $items[] = array(
            'name' => $name,
            'action' => 'install',
            'reason' => $reason,
            'signature' => $sig,
            'from' => isset($fromPkg['name']) ? (string)$fromPkg['name'] : '',
            'to' => '',
            'source' => '',
        );
        $seen[$name] = true;
    }

    protected function kbRequires($name, array $kb)
    {
        $name = strtolower($name);
        if (empty($kb[$name]['requires']) || !is_array($kb[$name]['requires'])) {
            return array();
        }
        $out = array();
        foreach ($kb[$name]['requires'] as $r) {
            $r = strtolower(trim((string)$r));
            if ($r !== '') {
                $out[] = $r;
            }
        }
        return $out;
    }

    /**
     * @return array<int,object>|null
     */
    protected function ensureProviders()
    {
        $map = array(
            'modx.com' => rtrim($this->repoUrls['modx'], '/') . '/',
            'modstore.pro' => rtrim($this->repoUrls['modstore'], '/') . '/',
        );
        $out = array();
        foreach ($map as $name => $url) {
            $p = $this->getOrCreateProvider($name, $url);
            if ($p) {
                $out[] = $p;
            }
        }
        return $out ? $out : null;
    }

    protected function getOrCreateProvider($name, $serviceUrl)
    {
        $classes = $this->providerClasses();
        foreach ($classes as $cls) {
            try {
                $existing = $this->modx->getObject($cls, array('name' => $name));
                if (!$existing) {
                    $existing = $this->modx->getObject($cls, array('service_url' => $serviceUrl));
                }
                if ($existing) {
                    if (!(int)$existing->get('active')) {
                        $existing->set('active', 1);
                        $existing->save();
                    }
                    return $existing;
                }
                $obj = $this->modx->newObject($cls);
                if (!$obj) {
                    continue;
                }
                $obj->fromArray(array(
                    'name' => $name,
                    'description' => 'MigrateMODX auto provider',
                    'service_url' => $serviceUrl,
                    'username' => '',
                    'api_key' => '',
                    'active' => 1,
                    'priority' => ($name === 'modx.com') ? 1 : 10,
                ), '', true, true);
                if ($obj->save()) {
                    return $obj;
                }
            } catch (Exception $e) {
            } catch (Throwable $e) {
            }
        }
        return null;
    }

    /**
     * @param string $packageName logical name (minishop3)
     * @param string $preferredSignature optional full signature
     * @param array $providers
     * @return array
     */
    protected function downloadAndInstall($packageName, $preferredSignature, array $providers)
    {
        if ($this->isPackageInstalled($packageName) && $preferredSignature === '') {
            /* update path: still try to transfer newer signature from provider find */
        }

        $signature = $preferredSignature;
        $providerUsed = null;
        if ($signature === '' || strpos($signature, '-') === false) {
            $found = $this->findRemoteSignature($packageName, $providers);
            if (empty($found['signature'])) {
                return array(
                    'ok' => false,
                    'error' => 'Package not found on providers',
                    'manual' => true,
                );
            }
            $signature = $found['signature'];
            $providerUsed = $found['provider'];
        }

        /* Already downloaded? */
        $pkg = $this->getTransportPackage($signature);
        if (!$pkg) {
            if (!$providerUsed) {
                foreach ($providers as $prov) {
                    try {
                        $pkg = $prov->transfer($signature);
                        if ($pkg) {
                            $providerUsed = $prov;
                            break;
                        }
                    } catch (Exception $e) {
                    } catch (Throwable $e) {
                    }
                }
            } else {
                try {
                    $pkg = $providerUsed->transfer($signature);
                } catch (Exception $e) {
                    $pkg = null;
                } catch (Throwable $e) {
                    $pkg = null;
                }
            }
        }
        if (!$pkg) {
            return array(
                'ok' => false,
                'error' => 'Download/transfer failed for ' . $signature,
                'manual' => true,
                'signature' => $signature,
            );
        }

        /* Install if not installed */
        $installed = false;
        try {
            if (method_exists($pkg, 'get') && (int)$pkg->get('installed')) {
                $installed = true;
            }
        } catch (Exception $e) {
        } catch (Throwable $e) {
        }
        if (!$installed) {
            try {
                $ok = $pkg->install(array());
                if (!$ok) {
                    return array(
                        'ok' => false,
                        'error' => 'transport install() returned false for ' . $signature,
                        'signature' => $signature,
                        'manual' => true,
                    );
                }
            } catch (Exception $e) {
                return array(
                    'ok' => false,
                    'error' => $e->getMessage(),
                    'signature' => $signature,
                    'manual' => true,
                );
            } catch (Throwable $e) {
                return array(
                    'ok' => false,
                    'error' => $e->getMessage(),
                    'signature' => $signature,
                    'manual' => true,
                );
            }
        }

        return array(
            'ok' => true,
            'signature' => $signature,
            'already_installed' => $installed,
            'provider' => $providerUsed ? (string)$providerUsed->get('name') : '',
        );
    }

    /**
     * @param string $packageName
     * @param array $providers
     * @return array{signature?:string,provider?:object}
     */
    protected function findRemoteSignature($packageName, array $providers)
    {
        $best = null;
        $bestProv = null;
        foreach ($providers as $prov) {
            try {
                $res = $prov->find(array(
                    'query' => $packageName,
                    'limit' => 25,
                    'supports' => '3.0',
                ));
            } catch (Exception $e) {
                continue;
            } catch (Throwable $e) {
                continue;
            }
            $list = array();
            if (is_array($res) && isset($res[1]) && is_array($res[1])) {
                $list = $res[1];
            } elseif (is_array($res)) {
                $list = $res;
            }
            foreach ($list as $row) {
                $sig = isset($row['signature']) ? (string)$row['signature'] : '';
                if ($sig === '') {
                    continue;
                }
                $base = strtolower(preg_replace('/-.*$/', '', $sig));
                $want = strtolower($packageName);
                if ($base !== $want && stripos($sig, $want . '-') !== 0
                    && strtolower(isset($row['name']) ? $row['name'] : '') !== $want) {
                    continue;
                }
                if ($this->isPreferredSignature($best, $sig)) {
                    $best = $sig;
                    $bestProv = $prov;
                }
            }
            /* fallback without supports filter */
            if ($best) {
                break;
            }
            try {
                $res = $prov->find(array('query' => $packageName, 'limit' => 25));
            } catch (Exception $e) {
                continue;
            } catch (Throwable $e) {
                continue;
            }
            $list = (is_array($res) && isset($res[1])) ? $res[1] : array();
            foreach ((array)$list as $row) {
                $sig = isset($row['signature']) ? (string)$row['signature'] : '';
                if ($sig === '') {
                    continue;
                }
                if (stripos($sig, $packageName . '-') !== 0
                    && strtolower(preg_replace('/-.*$/', '', $sig)) !== strtolower($packageName)) {
                    continue;
                }
                if ($this->isPreferredSignature($best, $sig)) {
                    $best = $sig;
                    $bestProv = $prov;
                }
            }
        }
        if (!$best) {
            return array();
        }
        return array('signature' => $best, 'provider' => $bestProv);
    }

    /**
     * Prefer stable pl over beta/dev; then higher version.
     */
    protected function isPreferredSignature($current, $candidate)
    {
        if ($current === null || $current === '') {
            return true;
        }
        $cStab = (stripos($candidate, 'beta') === false && stripos($candidate, 'dev') === false
            && stripos($candidate, 'alpha') === false) ? 1 : 0;
        $curStab = (stripos($current, 'beta') === false && stripos($current, 'dev') === false
            && stripos($current, 'alpha') === false) ? 1 : 0;
        if ($cStab !== $curStab) {
            return $cStab > $curStab;
        }
        return strnatcasecmp($candidate, $current) > 0;
    }

    public function isPackageInstalled($name)
    {
        $name = strtolower($name);
        $classes = $this->packageClasses();
        foreach ($classes as $cls) {
            try {
                $c = $this->modx->newQuery($cls);
                $c->where(array('package_name:LIKE' => $name));
                $c->where(array('installed:!=' => null));
                $c->where(array('installed:!=' => '0000-00-00 00:00:00'));
                $obj = $this->modx->getObject($cls, $c);
                if ($obj) {
                    return true;
                }
                /* signature prefix */
                $c2 = $this->modx->newQuery($cls);
                $c2->where(array('signature:LIKE' => $name . '-%'));
                $c2->where(array('installed:!=' => null));
                $obj2 = $this->modx->getObject($cls, $c2);
                if ($obj2 && $obj2->get('installed') && $obj2->get('installed') !== '0000-00-00 00:00:00') {
                    return true;
                }
            } catch (Exception $e) {
            } catch (Throwable $e) {
            }
        }
        /* namespace / folder fallback */
        $core = defined('MODX_CORE_PATH') ? MODX_CORE_PATH : '';
        if ($core && is_dir($core . 'components/' . $name . '/')) {
            return true;
        }
        return false;
    }

    protected function getTransportPackage($signature)
    {
        foreach ($this->packageClasses() as $cls) {
            try {
                $obj = $this->modx->getObject($cls, array('signature' => $signature));
                if ($obj) {
                    return $obj;
                }
            } catch (Exception $e) {
            } catch (Throwable $e) {
            }
        }
        return null;
    }

    protected function providerClasses()
    {
        $out = array();
        if (class_exists('\\MODX\\Revolution\\Transport\\modTransportProvider')) {
            $out[] = '\\MODX\\Revolution\\Transport\\modTransportProvider';
        }
        $out[] = 'transport.modTransportProvider';
        return $out;
    }

    protected function packageClasses()
    {
        $out = array();
        if (class_exists('\\MODX\\Revolution\\Transport\\modTransportPackage')) {
            $out[] = '\\MODX\\Revolution\\Transport\\modTransportPackage';
        }
        $out[] = 'transport.modTransportPackage';
        return $out;
    }

    protected function writeJournal(array $done, array $failed)
    {
        $dir = $this->storePath . 'patches/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents(
            $dir . 'packages-' . date('Ymd-His') . '.json',
            json_encode(array(
                'created_at' => date('c'),
                'done' => $done,
                'failed' => $failed,
            ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }
}
