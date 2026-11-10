<?php
class MmxPackageInventory
{
    /** @var modX */
    protected $modx;
    /** @var MmxKnowledgeBase */
    protected $kb;
    /** @var MmxPackageRepoProbe|null */
    protected $repoProbe;
    /** @var array|null */
    protected $lastRepoReport;

    public function __construct($modx, MmxKnowledgeBase $kb, $repoProbe = null)
    {
        $this->modx = $modx;
        $this->kb = $kb;
        $this->repoProbe = $repoProbe instanceof MmxPackageRepoProbe ? $repoProbe : null;
    }

    public function getRepoReport()
    {
        return is_array($this->lastRepoReport) ? $this->lastRepoReport : array('packages' => array(), 'repos' => array());
    }

    public function run()
    {
        $rows = array();
        try {
            $this->modx->addPackage('modx.transport', MODX_CORE_PATH . 'model/');
            $loaded = $this->modx->loadClass('transport.modTransportPackage', '', false, true)
                || $this->modx->loadClass('modTransportPackage', MODX_CORE_PATH . 'model/modx/transport/', true, false);
            if ($loaded) {
                $c = $this->modx->newQuery('transport.modTransportPackage');
                if (!$c) {
                    $c = $this->modx->newQuery('modTransportPackage');
                }
                if ($c) {
                    try {
                        $c->where(array('installed:!=' => null));
                    } catch (Exception $e) {
                        /* ignore */
                    }
                    $c->sortby('package_name', 'ASC');
                    $class = 'transport.modTransportPackage';
                    $packages = $this->modx->getCollection($class, $c);
                    if (!$packages) {
                        $packages = $this->modx->getCollection('modTransportPackage', $c);
                    }
                    if (!$packages) {
                        $packages = $this->modx->getCollection('modTransportPackage');
                    }
                    if ($packages) {
                        foreach ($packages as $pkg) {
                            if (method_exists($pkg, 'get') && $pkg->get('installed') === null) {
                                continue;
                            }
                            $name = strtolower((string)$pkg->get('package_name'));
                            if ($name === '') {
                                continue;
                            }
                            $sig = (string)$pkg->get('signature');
                            $version = $this->formatVersion($pkg);
                            $kb = $this->kb->lookup($name);
                            if (!$kb) {
                                $kb = $this->kb->lookup($sig);
                            }
                            $rows[$name] = $this->enrich($name, $sig, $version, $kb);
                        }
                    }
                }
            }
        } catch (Exception $e) {
            /* namespaces-only fallback */
        } catch (Throwable $e) {
            /* namespaces-only fallback */
        }
        foreach ($this->fromNamespaces() as $row) {
            if (!isset($rows[$row['name']])) {
                $rows[$row['name']] = $row;
            }
        }
        $rows = $this->resolveInstalled($rows);
        $rows = $this->enrichFromRepos($rows);
        return array_values($rows);
    }

    /**
     * Cross-check installed packages against modx.com + modstore.pro (when reachable).
     */
    protected function enrichFromRepos(array $rows)
    {
        $this->lastRepoReport = array('packages' => array(), 'repos' => array());
        if (!$this->repoProbe) {
            return $rows;
        }
        try {
            $names = array();
            foreach ($rows as $r) {
                if (!empty($r['name']) && $r['name'] !== 'migratemodx') {
                    $names[] = $r['name'];
                }
            }
            $report = $this->repoProbe->probe($names);
            $this->lastRepoReport = $report;
            $probed = isset($report['packages']) && is_array($report['packages']) ? $report['packages'] : array();
            foreach ($rows as $key => $row) {
                $n = isset($row['name']) ? $row['name'] : '';
                if ($n !== '' && isset($probed[$n])) {
                    $rows[$key] = $this->repoProbe->applyToRow($row, $probed[$n]);
                }
            }
        } catch (Exception $e) {
            $this->lastRepoReport = array(
                'packages' => array(),
                'repos' => array('error' => array('ok' => false, 'error' => $e->getMessage())),
            );
        } catch (Throwable $e) {
            $this->lastRepoReport = array(
                'packages' => array(),
                'repos' => array('error' => array('ok' => false, 'error' => $e->getMessage())),
            );
        }
        return $rows;
    }

    protected function formatVersion($pkg)
    {
        $maj = (string)$pkg->get('version_major');
        $min = (string)$pkg->get('version_minor');
        $pat = (string)$pkg->get('version_patch');
        $rel = (string)$pkg->get('release');
        $v = trim($maj . '.' . $min . '.' . $pat, '.');
        if ($rel !== '' && $rel !== '0') {
            $v .= '-' . $rel;
        }
        return $v;
    }

    protected function fromNamespaces()
    {
        $rows = array();
        try {
            $nsClass = class_exists('\\MODX\\Revolution\\modNamespace') ? '\\MODX\\Revolution\\modNamespace' : 'modNamespace';
            $collection = $this->modx->getCollection($nsClass);
            if (!$collection) {
                return $rows;
            }
            foreach ($collection as $ns) {
                $name = strtolower((string)$ns->get('name'));
                if (in_array($name, array('core', ''), true)) {
                    continue;
                }
                $kb = $this->kb->lookup($name);
                $rows[] = $this->enrich($name, $name, '', $kb);
            }
        } catch (Exception $e) {
            return $rows;
        } catch (Throwable $e) {
            return $rows;
        }
        return $rows;
    }

    protected function enrich($name, $signature, $version, $kb)
    {
        $status = 'unknown';
        $notes = '';
        $alternatives = array();
        $actions = array();
        $evidence = 'unknown';
        $minVersion = '';
        $successor = '';
        $migration = '';
        $title = $name;
        if (is_array($kb)) {
            $status = isset($kb['status']) ? $kb['status'] : 'unknown';
            $notes = isset($kb['notes']) ? $kb['notes'] : '';
            $alternatives = isset($kb['alternatives']) && is_array($kb['alternatives']) ? $kb['alternatives'] : array();
            $actions = isset($kb['actions']) ? $kb['actions'] : array();
            $evidence = 'kb';
            $minVersion = isset($kb['min_version']) ? (string)$kb['min_version'] : '';
            if ($minVersion === '' && isset($kb['modx3_min_version'])) {
                $minVersion = (string)$kb['modx3_min_version'];
            }
            $successor = isset($kb['successor']) ? (string)$kb['successor'] : '';
            $migration = isset($kb['migration']) ? (string)$kb['migration'] : '';
            $title = isset($kb['title']) ? (string)$kb['title'] : $name;
            if ($successor !== '') {
                $has = false;
                foreach ($alternatives as $a) {
                    $sig = strtolower(isset($a['signature']) ? $a['signature'] : '');
                    if ($sig === strtolower($successor)) {
                        $has = true;
                        break;
                    }
                }
                if (!$has) {
                    array_unshift($alternatives, array(
                        'name' => isset($kb['successor_title']) ? $kb['successor_title'] : $successor,
                        'signature' => $successor,
                    ));
                }
            }
        }

        $verdict = 'unknown';
        switch ($status) {
            case 'compatible':
            case 'compatible_from':
                $verdict = 'ok';
                break;
            case 'replaced_by':
                $verdict = 'replace';
                break;
            case 'incompatible':
            case 'abandoned':
                $verdict = 'fail';
                break;
            default:
                $verdict = 'unknown';
        }

        $versionStatus = 'unknown';
        $requiredVersion = $minVersion;
        if ($minVersion !== '' && $version !== '') {
            if (version_compare($this->normalizeVersion($version), $this->normalizeVersion($minVersion), '<')) {
                $versionStatus = 'outdated';
                if ($verdict === 'ok') {
                    $verdict = 'update';
                }
            } else {
                $versionStatus = 'ok';
            }
        } elseif ($version !== '') {
            $versionStatus = 'ok';
        }

        return array(
            'name' => $name,
            'title' => $title,
            'signature' => $signature,
            'version' => $version,
            'status' => $status,
            'verdict' => $verdict,
            'evidence' => $evidence,
            'notes' => $notes,
            'migration' => $migration,
            'alternatives' => $alternatives,
            'successor' => $successor,
            'actions' => $actions,
            'resolution' => 'none',
            'resolution_package' => '',
            'resolution_version' => '',
            'has_migration_path' => !empty($alternatives) || $successor !== '',
            'version_status' => $versionStatus,
            'required_version' => $requiredVersion,
            'php' => (is_array($kb) && isset($kb['php'])) ? (string)$kb['php'] : '',
            'min_modx' => (is_array($kb) && isset($kb['min_modx'])) ? (string)$kb['min_modx'] : '',
            'risks' => (is_array($kb) && isset($kb['risks'])) ? $kb['risks'] : array(),
        );
    }

    /**
     * Detect installed successors (e.g. minishop3 while minishop2 still present)
     * and soft-resolve replace/fail packages.
     *
     * @param array $rows keyed by name
     * @return array
     */
    protected function resolveInstalled(array $rows)
    {
        $byName = array();
        foreach ($rows as $r) {
            $byName[$r['name']] = $r;
        }
        foreach ($rows as $name => &$r) {
            $targets = array();
            if (!empty($r['successor'])) {
                $targets[] = strtolower($r['successor']);
            }
            foreach ($r['alternatives'] as $a) {
                $sig = strtolower(isset($a['signature']) ? $a['signature'] : '');
                $sig = preg_replace('/[^a-z0-9_\-]/', '', $sig);
                if ($sig !== '') {
                    $targets[] = $sig;
                }
            }
            $targets = array_values(array_unique($targets));
            $found = null;
            foreach ($targets as $t) {
                if ($t === $name) {
                    continue;
                }
                if (isset($byName[$t])) {
                    $found = $byName[$t];
                    break;
                }
            }
            if ($found && in_array($r['verdict'], array('replace', 'fail'), true)) {
                $r['resolution'] = 'successor_installed';
                $r['resolution_package'] = $found['name'];
                $r['resolution_version'] = $found['version'];
                if ($r['migration'] === '') {
                    $r['migration'] = 'Successor package is already installed: ' . $found['name']
                        . ($found['version'] !== '' ? ' ' . $found['version'] : '')
                        . '. Finish data migration, then remove the legacy package.';
                }
            } elseif ($r['has_migration_path'] && in_array($r['verdict'], array('replace', 'fail'), true)) {
                $r['resolution'] = 'path_known';
            } elseif ($r['verdict'] === 'update') {
                $r['resolution'] = 'needs_update';
            } elseif ($r['verdict'] === 'ok') {
                $r['resolution'] = 'ok';
            }
        }
        unset($r);
        return $rows;
    }

    protected function normalizeVersion($v)
    {
        $v = strtolower(trim((string)$v));
        $v = preg_replace('/^v/', '', $v);
        if (preg_match('/^(\d+\.\d+\.\d+)/', $v, $m)) {
            return $m[1];
        }
        if (preg_match('/^(\d+\.\d+)/', $v, $m)) {
            return $m[1] . '.0';
        }
        if (preg_match('/^(\d+)/', $v, $m)) {
            return $m[1] . '.0.0';
        }
        return '0.0.0';
    }
}
