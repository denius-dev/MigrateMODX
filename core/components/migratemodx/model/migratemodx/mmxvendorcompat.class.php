<?php
require_once dirname(__FILE__) . '/mmxpaths.class.php';
require_once dirname(__FILE__) . '/mmxknowledgebase.class.php';

/**
 * Handle extras that "won't survive" MODX 3:
 * - try high-safety PHP + MODX3 string ports on component files;
 * - if still unsafe / KB fail → quarantine folder with suffix;
 * - if port applied cleanly → rename with .__mmx_ported and retarget namespace paths.
 *
 * Quarantine lists come from data/kb/packages.json (`quarantine`: prepare|post_ms3),
 * plus on-disk heuristics for unknown leftovers (miniShop2 maps, MODX 2 paths, …).
 */
class MmxVendorCompat
{
    const SUFFIX_DISABLED = '.__disabled_for_modx3';
    const SUFFIX_PORTED = '.__mmx_ported';

    /** @var modX|null */
    protected $modx;
    protected $siteRoot;
    protected $storePath;
    protected $phpRules = array();
    protected $modx3Safe = array();
    protected $blockerPatterns = array();
    /** @var MmxKnowledgeBase|null */
    protected $kb;

    public function __construct($modx, $siteRoot, $storePath, $dataDir)
    {
        $this->modx = $modx;
        $this->siteRoot = rtrim(str_replace('\\', '/', $siteRoot), '/') . '/';
        $this->storePath = rtrim(str_replace('\\', '/', $storePath), '/') . '/';
        $dataDir = rtrim(str_replace('\\', '/', $dataDir), '/') . '/';
        $rulesFile = dirname($dataDir) . '/php/rules.php';
        /* rules live under data/php or components/.../data/php */
        foreach (array(
            $dataDir . 'php/rules.php',
            dirname(__FILE__) . '/../../data/php/rules.php',
        ) as $cand) {
            if (is_file($cand)) {
                $loaded = include $cand;
                if (is_array($loaded)) {
                    $this->phpRules = $loaded;
                }
                break;
            }
        }
        $modx3 = $dataDir . 'rewrites/modx3.json';
        if (!is_file($modx3)) {
            $modx3 = dirname(__FILE__) . '/../../data/rewrites/modx3.json';
        }
        if (is_file($modx3)) {
            $j = json_decode((string)file_get_contents($modx3), true);
            if (!empty($j['safe_string_replacements']) && is_array($j['safe_string_replacements'])) {
                $this->modx3Safe = $j['safe_string_replacements'];
            }
            if (!empty($j['element_replacements']) && is_array($j['element_replacements'])) {
                foreach ($j['element_replacements'] as $er) {
                    if (!empty($er['detect'])) {
                        $this->blockerPatterns[] = $er['detect'];
                    }
                }
            }
        }
        /* Classic setup / manager killers */
        $this->blockerPatterns[] = 'modcachemanager\\.class\\.php';
        $this->blockerPatterns[] = 'model/modx/modcachemanager';
        $this->blockerPatterns[] = '\\bmodRestClient\\b';
        $this->blockerPatterns[] = '\\bmodAction\\b';
        /* Tickets (and clones): MODX 2 xPDO map key — breaks MODX 3 manager */
        $this->blockerPatterns[] = "map\\['modUser'\\]";
        $this->blockerPatterns[] = 'map\\["modUser"\\]';

        $kbPath = $dataDir . 'kb/packages.json';
        if (!is_file($kbPath)) {
            $kbPath = MmxKnowledgeBase::defaultPath();
        }
        $this->kb = new MmxKnowledgeBase($kbPath);
    }

    /**
     * @param array $packages inventory rows
     * @param bool $apply mutate disk
     * @return array
     */
    public function process(array $packages, $apply = true)
    {
        $ported = array();
        $quarantined = array();
        $skipped = array();
        $manual = array();

        foreach ($packages as $p) {
            if (!is_array($p) || empty($p['name'])) {
                continue;
            }
            $name = strtolower((string)$p['name']);
            if ($name === '' || $name === 'migratemodx' || $name === 'core') {
                continue;
            }

            $verdict = isset($p['verdict']) ? (string)$p['verdict'] : 'unknown';
            $status = isset($p['status']) ? (string)$p['status'] : '';
            $hasSuccessor = !empty($p['successor']) || (!empty($p['resolution']) && $p['resolution'] === 'successor_installed');

            /* Replaced-by packages: do not rewrite stock; user installs successor */
            if ($verdict === 'replace' || $status === 'replaced_by') {
                $manual[] = array(
                    'name' => $name,
                    'action' => 'replace',
                    'message' => 'Replace with successor'
                        . (!empty($p['successor']) ? ' (' . $p['successor'] . ')' : '')
                        . '; do not auto-port vendor stock.',
                    'successor' => isset($p['successor']) ? $p['successor'] : '',
                );
                continue;
            }

            $wontSurvive = in_array($verdict, array('fail'), true)
                || in_array($status, array('incompatible', 'abandoned'), true)
                || in_array($name, self::knownBlockerNames(), true);

            if (!$wontSurvive) {
                continue;
            }

            /* Already handled */
            if ($this->isAlreadyMarked($name)) {
                $skipped[] = array(
                    'name' => $name,
                    'action' => 'already_marked',
                    'message' => 'Already ported or quarantined on disk',
                );
                continue;
            }

            $dirs = $this->componentDirs($name);
            if (!$dirs) {
                $skipped[] = array(
                    'name' => $name,
                    'action' => 'missing_files',
                    'message' => 'No component folder under core/assets',
                );
                continue;
            }

            $port = $this->portPackageFiles($dirs, $apply);
            $stillBlocked = $this->scanBlockers($dirs);
            /* Port only when we changed files and no remaining hard patterns */
            $canPort = empty($stillBlocked) && $port['files_changed'] > 0;

            if ($apply && $canPort) {
                $ren = $this->renamePackage($name, self::SUFFIX_PORTED, true);
                if (!empty($ren['ok'])) {
                    $ported[] = array(
                        'name' => $name,
                        'action' => 'ported',
                        'marked_as' => $name . self::SUFFIX_PORTED,
                        'files_changed' => $port['files_changed'],
                        'php_hits' => $port['php_hits'],
                        'rewrite_hits' => $port['rewrite_hits'],
                        'message' => 'Ported with high-safety PHP/MODX3 fixes; renamed '
                            . $name . self::SUFFIX_PORTED,
                    );
                    continue;
                }
            }

            /* Cannot keep running safely → quarantine (disable) */
            if ($apply) {
                $ren = $this->renamePackage($name, self::SUFFIX_DISABLED, false);
                if (!empty($ren['ok'])) {
                    $quarantined[] = array(
                        'name' => $name,
                        'action' => 'quarantined',
                        'marked_as' => $name . self::SUFFIX_DISABLED,
                        'files_changed' => $port['files_changed'],
                        'blockers' => $stillBlocked,
                        'message' => 'Quarantined as ' . $name . self::SUFFIX_DISABLED
                            . ($stillBlocked
                                ? ' (remaining: ' . implode(', ', array_slice($stillBlocked, 0, 3)) . ')'
                                : ($port['files_changed'] ? '' : ' (no safe autofix)')),
                    );
                    continue;
                }
                $manual[] = array(
                    'name' => $name,
                    'action' => 'quarantine_failed',
                    'message' => 'Could not rename component folder — remove/disable manually',
                );
            } else {
                $manual[] = array(
                    'name' => $name,
                    'action' => $canPort ? 'would_port' : 'would_quarantine',
                    'files_changed' => $port['files_changed'],
                    'blockers' => $stillBlocked,
                    'message' => $canPort
                        ? 'Dry-run: would port and mark ' . $name . self::SUFFIX_PORTED
                        : 'Dry-run: would quarantine ' . $name . self::SUFFIX_DISABLED,
                );
            }
        }

        $result = array(
            'ok' => true,
            'ported' => $ported,
            'quarantined' => $quarantined,
            'skipped' => $skipped,
            'manual' => $manual,
            'applied' => (bool)$apply,
        );
        @file_put_contents(
            $this->storePath . 'vendor-compat.json',
            json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
        return $result;
    }

    /**
     * Packages that break /setup/ or leave an empty MODX 3 manager.
     * Sourced from KB `quarantine: prepare` (+ hard fallback).
     *
     * @return string[]
     */
    public static function knownBlockerNames()
    {
        $fromKb = self::kbNamesForQuarantine('prepare');
        $fallback = array('admintools', 'batcher', 'tickets');
        return array_values(array_unique(array_merge($fallback, $fromKb)));
    }

    /**
     * Extras that break MODX 3 front after MiniShop3 (or are obsolete ms2 ecosystem).
     * Sourced from KB `quarantine: post_ms3` (+ hard fallback).
     *
     * @return string[]
     */
    public static function postMs3BrokenNames()
    {
        $fromKb = self::kbNamesForQuarantine('post_ms3');
        $fallback = array(
            'looked',
            'xlike',
            'msminicartdynamic',
            'msdiscount',
            'msorderdiscount',
            'msoptionsprice',
            'msoptionsprice2',
            'ms2gallery',
            'minishop2',
            'msearch2',
            'loginfields',
            'mschart',
            'mstools',
        );
        return array_values(array_unique(array_merge($fallback, $fromKb)));
    }

    /**
     * @param string $phase
     * @return string[]
     */
    protected static function kbNamesForQuarantine($phase)
    {
        static $cache = array();
        if (isset($cache[$phase])) {
            return $cache[$phase];
        }
        try {
            $kb = new MmxKnowledgeBase(MmxKnowledgeBase::defaultPath());
            $cache[$phase] = $kb->namesForQuarantine($phase);
        } catch (Exception $e) {
            $cache[$phase] = array();
        } catch (Throwable $e) {
            $cache[$phase] = array();
        }
        return $cache[$phase];
    }

    /**
     * Quarantine post-MS3 broken extras, disable MS2 map plugins, drop dead namespaces.
     * Goal: no Fatal/Deprecated spam from leftovers after MODX 3 + MiniShop3.
     *
     * @param bool $apply
     * @return array
     */
    public function cleanupBrokenExtras($apply = true)
    {
        $quarantined = array();
        $plugins = array();
        $namespaces = array();
        $extensions = array();
        $heuristic = $this->discoverHeuristicBrokenExtras();

        $targets = array_values(array_unique(array_merge(
            self::knownBlockerNames(),
            self::postMs3BrokenNames(),
            $heuristic
        )));

        foreach ($targets as $name) {
            if ($apply) {
                $q = $this->forceQuarantinePackage($name);
                if (!empty($q['ok']) && empty($q['missing']) && empty($q['already'])) {
                    $quarantined[] = array(
                        'name' => $name,
                        'marked_as' => isset($q['marked_as']) ? $q['marked_as'] : $name,
                        'via' => in_array($name, $heuristic, true) ? 'heuristic' : 'kb',
                    );
                } elseif (!empty($q['already'])) {
                    $quarantined[] = array(
                        'name' => $name,
                        'marked_as' => isset($q['marked_as']) ? $q['marked_as'] : $name,
                        'already' => true,
                        'via' => in_array($name, $heuristic, true) ? 'heuristic' : 'kb',
                    );
                }
                if (!empty($q['plugins'])) {
                    $plugins = array_merge($plugins, $q['plugins']);
                }
            } else {
                if ($this->componentDirs($name) && !$this->isAlreadyMarked($name)) {
                    $quarantined[] = array(
                        'name' => $name,
                        'would' => true,
                        'via' => in_array($name, $heuristic, true) ? 'heuristic' : 'kb',
                    );
                }
            }
        }

        $mapPlugins = $this->disableMs2MapPlugins($apply);
        $plugins = array_merge($plugins, $mapPlugins);

        $scrubbedSetting = array();
        if ($apply) {
            $namespaces = $this->removeDeadNamespaces();
            $extensions = $this->removeDeadExtensionPackages();
            /* System setting extension_packages keeps addPackage() paths after folder quarantine —
               that is what floods error.log with "Path specified for package X is not valid" */
            $scrubbedSetting = $this->scrubExtensionPackagesSetting();
            $this->clearModxCache();
        } else {
            $scrubbedSetting = $this->scrubExtensionPackagesSetting(false);
        }

        $plugins = array_values(array_unique($plugins));
        $result = array(
            'ok' => true,
            'quarantined' => $quarantined,
            'disabled_plugins' => $plugins,
            'removed_namespaces' => $namespaces,
            'removed_extensions' => $extensions,
            'scrubbed_extension_packages' => $scrubbedSetting,
            'heuristic_targets' => $heuristic,
            'kb_targets' => array(
                'prepare' => self::knownBlockerNames(),
                'post_ms3' => self::postMs3BrokenNames(),
            ),
            'applied' => (bool)$apply,
        );
        if ($apply) {
            @file_put_contents(
                $this->storePath . 'broken-extras-cleanup.json',
                json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            );
        }
        return $result;
    }

    /**
     * Custom/site plugins that still inject MS2 short class maps (msProductData) break MiniShop3 listings.
     *
     * @param bool $apply
     * @return string[]
     */
    public function disableMs2MapPlugins($apply = true)
    {
        $disabled = array();
        if (!is_object($this->modx) || !method_exists($this->modx, 'getCollection')) {
            return $disabled;
        }
        try {
            $cls = class_exists('\\MODX\\Revolution\\modPlugin')
                ? '\\MODX\\Revolution\\modPlugin'
                : 'modPlugin';
            $plugins = $this->modx->getCollection($cls);
            if (!$plugins) {
                return $disabled;
            }
            foreach ($plugins as $plugin) {
                if ((int)$plugin->get('disabled') === 1) {
                    continue;
                }
                $code = (string)$plugin->get('plugincode');
                $name = (string)$plugin->get('name');
                $hit = false;
                /* MS2 short map keys — fatal/empty catalog on MS3 */
                if (preg_match('/[\'"]msProductData[\'"]\s*=>/', $code)
                    && stripos($code, 'MiniShop3\\Model\\msProductData') === false) {
                    $hit = true;
                }
                if (preg_match('/[\'"]msProduct[\'"]\s*=>\s*array\s*\(/', $code)
                    && stripos($code, 'MiniShop3\\Model\\msProduct') === false
                    && stripos($code, 'class_key') === false) {
                    $hit = true;
                }
                if (preg_match('/getService\s*\(\s*[\'"]miniShop2[\'"]/', $code)
                    || preg_match('/getService\s*\(\s*[\'"]msDiscount[\'"]/', $code)
                    || preg_match('/addPackage\s*\(\s*[\'"]minishop2[\'"]/i', $code)) {
                    $hit = true;
                }
                /* Custom MS2 product field maps (e.g. swords for mSearch2) break MS3 schema */
                if (preg_match('/[\'"]swords[\'"]\s*=>/', $code)
                    && preg_match('/msProductData|miniShop2|minishop2/i', $code)) {
                    $hit = true;
                }
                if (preg_match('/\$modx->xlike\b/', $code) || preg_match('/getService\s*\(\s*[\'"]xlike[\'"]/', $code)) {
                    $hit = true;
                }
                if (preg_match('/getService\s*\(\s*[\'"]looked[\'"]/', $code)) {
                    $hit = true;
                }
                /* MODX 3: short modUser map merges break xPDOMap (Undefined array key "modUser") */
                if (preg_match('/[\'"]modUser[\'"]\s*=>\s*array\s*\(/', $code)
                    && stripos($code, 'MODX\\Revolution\\modUser') === false) {
                    $hit = true;
                }
                if (!$hit) {
                    continue;
                }
                if ($apply) {
                    $plugin->set('disabled', 1);
                    if ($plugin->save()) {
                        $disabled[] = $name;
                    }
                } else {
                    $disabled[] = $name;
                }
            }
        } catch (Exception $e) {
        } catch (Throwable $e) {
        }
        return $disabled;
    }

    /**
     * Drop namespaces whose component folder is gone/quarantined (stops addPackage path errors).
     *
     * @return string[]
     */
    public function removeDeadNamespaces()
    {
        $removed = array();
        if (!is_object($this->modx)) {
            return $removed;
        }
        try {
            $cls = class_exists('\\MODX\\Revolution\\modNamespace')
                ? '\\MODX\\Revolution\\modNamespace'
                : 'modNamespace';
            $list = $this->modx->getCollection($cls);
            if (!$list) {
                return $removed;
            }
            $core = $this->siteRoot . 'core/components/';
            foreach ($list as $ns) {
                $name = strtolower((string)$ns->get('name'));
                if ($name === '' || $name === 'core' || $name === 'migratemodx' || $name === 'minishop3') {
                    continue;
                }
                $path = str_replace(
                    array('{core_path}', '{base_path}', '{assets_path}'),
                    array(
                        defined('MODX_CORE_PATH') ? MODX_CORE_PATH : $this->siteRoot . 'core/',
                        $this->siteRoot,
                        defined('MODX_ASSETS_PATH') ? MODX_ASSETS_PATH : $this->siteRoot . 'assets/',
                    ),
                    (string)$ns->get('path')
                );
                $path = rtrim(str_replace('\\', '/', $path), '/') . '/';
                $alive = is_dir($path) || is_dir($core . $name . '/');
                $force = in_array($name, self::knownBlockerNames(), true)
                    || in_array($name, self::postMs3BrokenNames(), true)
                    || in_array($name, array('minishop2', 'tickets'), true);
                if ($alive && !$force) {
                    continue;
                }
                if ($force && is_dir($core . $name . '/')) {
                    /* still live folder — leave namespace until quarantine rename */
                    if (!$this->isAlreadyMarked($name)) {
                        continue;
                    }
                }
                if (method_exists($ns, 'remove') && $ns->remove()) {
                    $removed[] = $name;
                }
            }
        } catch (Exception $e) {
        } catch (Throwable $e) {
        }
        return $removed;
    }

    /**
     * @return string[]
     */
    public function removeDeadExtensionPackages()
    {
        $removed = array();
        if (!is_object($this->modx)) {
            return $removed;
        }
        try {
            $cls = class_exists('\\MODX\\Revolution\\modExtensionPackage')
                ? '\\MODX\\Revolution\\modExtensionPackage'
                : 'modExtensionPackage';
            if (!class_exists(ltrim($cls, '\\')) && $cls !== 'modExtensionPackage') {
                /* class may not exist on all builds */
            }
            $list = @$this->modx->getCollection($cls);
            if (!$list) {
                return $removed;
            }
            $core = $this->siteRoot . 'core/components/';
            foreach ($list as $ep) {
                $ns = strtolower((string)$ep->get('namespace'));
                $path = str_replace('\\', '/', (string)$ep->get('path'));
                $path = str_replace(
                    array('{core_path}', '{base_path}'),
                    array(
                        defined('MODX_CORE_PATH') ? MODX_CORE_PATH : $this->siteRoot . 'core/',
                        $this->siteRoot,
                    ),
                    $path
                );
                $alive = ($path !== '' && is_dir($path)) || ($ns !== '' && is_dir($core . $ns . '/'));
                $force = in_array($ns, self::knownBlockerNames(), true)
                    || in_array($ns, self::postMs3BrokenNames(), true)
                    || $ns === 'minishop2'
                    || $ns === 'tickets';
                if ($alive && !$force) {
                    continue;
                }
                if ($force && is_dir($core . $ns . '/') && !$this->isAlreadyMarked($ns)) {
                    continue;
                }
                if (method_exists($ep, 'remove') && $ep->remove()) {
                    $removed[] = $ns !== '' ? $ns : (string)$ep->get('id');
                }
            }
        } catch (Exception $e) {
        } catch (Throwable $e) {
        }
        return $removed;
    }

    /**
     * Scan live core/components for extras that look unsafe on MODX 3 / after MiniShop3
     * even when missing from KB (custom forks, forgotten ms2 addons).
     *
     * @return string[] package folder names
     */
    public function discoverHeuristicBrokenExtras()
    {
        $found = array();
        $core = $this->siteRoot . 'core/components/';
        if (!is_dir($core)) {
            return $found;
        }
        $skip = array(
            'core', 'migratemodx', 'minishop3', 'pdotools', 'vuetools',
            'formit', 'login', 'ace', 'tinymcerte', 'translit',
        );
        $hardPatterns = array(
            '[\'"]msProductData[\'"]\\s*=>' => 'msProductData short map',
            "map\\['modUser'\\]" => 'modUser short map',
            'map\\["modUser"\\]' => 'modUser short map',
            'modcachemanager\\.class\\.php' => 'MODX2 cache manager path',
            'core/model/modx/modcachemanager' => 'MODX2 cache manager path',
        );
        $softPatterns = array(
            'getService\\s*\\(\\s*[\'"]miniShop2[\'"]' => 'miniShop2 service',
            'miniShop2\\\\Model\\\\' => 'miniShop2 model FQCN',
        );
        foreach (scandir($core) as $d) {
            if ($d === '.' || $d === '..' || $d[0] === '.') {
                continue;
            }
            $name = strtolower($d);
            if (strpos($name, self::SUFFIX_DISABLED) !== false
                || strpos($name, self::SUFFIX_PORTED) !== false) {
                continue;
            }
            if (in_array($name, $skip, true)) {
                continue;
            }
            $dir = $core . $d . '/';
            if (!is_dir($dir)) {
                continue;
            }
            $kbRow = ($this->kb instanceof MmxKnowledgeBase) ? $this->kb->lookup($name) : null;
            $kbStatus = (is_array($kbRow) && isset($kbRow['status'])) ? (string)$kbRow['status'] : '';
            $kbQuarantine = (is_array($kbRow) && !empty($kbRow['quarantine'])) ? (string)$kbRow['quarantine'] : '';
            /* Already scheduled by KB — skip rediscovery */
            if ($kbQuarantine === 'prepare' || $kbQuarantine === 'post_ms3') {
                continue;
            }
            /* Name heuristic: classic ms2 addon prefix still installed beside MiniShop3 */
            if (preg_match('/^ms(discount|optionsprice|minicart|gallery|order|chart|tools|promo|bonus|balance)/', $name)
                || $name === 'minishop2'
                || $name === 'msearch2'
                || $name === 'looked'
                || $name === 'xlike'
                || $name === 'tickets') {
                $found[] = $name;
                continue;
            }
            /* compatible* in KB: only hard MODX3 killers, not a stray miniShop2 string in a helper file */
            $patterns = $hardPatterns;
            if (!in_array($kbStatus, array('compatible', 'compatible_from'), true)) {
                $patterns = array_merge($hardPatterns, $softPatterns);
            }
            $hit = $this->scanDirForPatterns($dir, $patterns, 40);
            if ($hit) {
                $found[] = $name;
            }
        }
        $found = array_values(array_unique($found));
        sort($found);
        return $found;
    }

    /**
     * @param string $dir
     * @param array $patterns regex => label
     * @param int $maxFiles
     * @return bool
     */
    protected function scanDirForPatterns($dir, array $patterns, $maxFiles = 40)
    {
        $count = 0;
        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $f) {
                if (!$f->isFile()) {
                    continue;
                }
                $path = str_replace('\\', '/', $f->getPathname());
                if (!preg_match('/\\.(php|inc)$/i', $path)) {
                    continue;
                }
                if (strpos($path, '/vendor/') !== false || strpos($path, '/node_modules/') !== false) {
                    continue;
                }
                $count++;
                if ($count > $maxFiles) {
                    break;
                }
                $src = @file_get_contents($path);
                if ($src === false || $src === '') {
                    continue;
                }
                /* Skip huge binaries */
                if (strlen($src) > 500000) {
                    continue;
                }
                foreach ($patterns as $re => $label) {
                    if (@preg_match('/' . $re . '/i', $src)) {
                        return true;
                    }
                }
            }
        } catch (Exception $e) {
        } catch (Throwable $e) {
        }
        return false;
    }

    /**
     * Strip dead / known-broken entries from system setting extension_packages.
     * Quarantine renames folders but leaves this JSON — every request then logs path errors.
     *
     * @param bool $apply
     * @return string[] removed package keys
     */
    public function scrubExtensionPackagesSetting($apply = true)
    {
        $removed = array();
        if (!is_object($this->modx)) {
            return $removed;
        }
        $raw = null;
        $setting = null;
        try {
            $cls = class_exists('\\MODX\\Revolution\\modSystemSetting')
                ? '\\MODX\\Revolution\\modSystemSetting'
                : 'modSystemSetting';
            if (method_exists($this->modx, 'getObject')) {
                $setting = $this->modx->getObject($cls, array('key' => 'extension_packages'));
            }
            if ($setting) {
                $raw = $setting->get('value');
            } elseif (method_exists($this->modx, 'getOption')) {
                $raw = $this->modx->getOption('extension_packages');
            }
        } catch (Exception $e) {
            return $removed;
        } catch (Throwable $e) {
            return $removed;
        }
        if ($raw === null || $raw === '' || $raw === '[]' || $raw === '{}') {
            return $removed;
        }
        if (is_array($raw)) {
            $data = $raw;
        } else {
            $data = json_decode((string)$raw, true);
            if (!is_array($data)) {
                return $removed;
            }
        }
        $core = defined('MODX_CORE_PATH')
            ? MODX_CORE_PATH
            : $this->siteRoot . 'core/';
        $forceNames = array_merge(self::knownBlockerNames(), self::postMs3BrokenNames(), array('minishop2', 'tickets'));
        $forceNames = array_values(array_unique($forceNames));
        $changed = false;
        $out = array();
        foreach ($data as $idx => $entry) {
            if (!is_array($entry)) {
                $out[$idx] = $entry;
                continue;
            }
            $keepEntry = array();
            foreach ($entry as $pkg => $cfg) {
                $pkgLower = strtolower((string)$pkg);
                $path = '';
                if (is_array($cfg) && isset($cfg['path'])) {
                    $path = (string)$cfg['path'];
                } elseif (is_string($cfg)) {
                    $path = $cfg;
                }
                $resolved = str_replace(
                    array('[[++core_path]]', '{core_path}', '[[++base_path]]', '{base_path}'),
                    array($core, $core, $this->siteRoot, $this->siteRoot),
                    $path
                );
                $resolved = rtrim(str_replace('\\', '/', $resolved), '/') . '/';
                $drop = in_array($pkgLower, $forceNames, true)
                    || $this->isAlreadyMarked($pkgLower)
                    || ($resolved !== '/' && !is_dir($resolved));
                if ($drop) {
                    $removed[] = $pkgLower;
                    $changed = true;
                    continue;
                }
                $keepEntry[$pkg] = $cfg;
            }
            if ($keepEntry) {
                $out[$idx] = $keepEntry;
            } else {
                $changed = true;
            }
        }
        $removed = array_values(array_unique($removed));
        if (!$changed) {
            return $removed;
        }
        if (!$apply) {
            return $removed;
        }
        /* Re-index as MODX expects numeric string keys */
        $reindexed = array();
        $i = 0;
        foreach ($out as $entry) {
            if (is_array($entry) && $entry) {
                $reindexed[(string)$i] = $entry;
                $i++;
            }
        }
        $json = $reindexed ? json_encode($reindexed) : '[]';
        try {
            if ($setting) {
                $setting->set('value', $json);
                $setting->save();
            } elseif (method_exists($this->modx, 'setOption')) {
                $this->modx->setOption('extension_packages', $json);
            }
            if (isset($this->modx->cacheManager) && is_object($this->modx->cacheManager)) {
                $this->modx->cacheManager->refresh(array('system_settings' => array()));
            }
        } catch (Exception $e) {
        } catch (Throwable $e) {
        }
        return $removed;
    }

    /**
     * Quarantine known setup/manager killers without needing a full audit matrix.
     */
    public function quarantineKnownBlockers($apply = true)
    {
        $fake = array();
        foreach (self::knownBlockerNames() as $name) {
            $fake[] = array(
                'name' => $name,
                'verdict' => 'fail',
                'status' => 'incompatible',
            );
        }
        return $this->process($fake, $apply);
    }

    /**
     * Force-quarantine one package by rename (ignores replace/successor matrix).
     * Used to retire miniShop2 after MiniShop3 data migrate — MS2 Fatal's MODX 3 front.
     *
     * @return array{ok:bool,name:string,marked_as?:string,plugins?:string[],message?:string}
     */
    public function forceQuarantinePackage($name)
    {
        $name = strtolower(trim((string)$name));
        if ($name === '' || $name === 'migratemodx' || $name === 'core' || $name === 'minishop3') {
            return array('ok' => false, 'name' => $name, 'message' => 'refused');
        }
        if ($this->isAlreadyMarked($name)) {
            $plugins = $this->deactivatePackagePlugins($name);
            return array(
                'ok' => true,
                'name' => $name,
                'marked_as' => $name . self::SUFFIX_DISABLED,
                'already' => true,
                'plugins' => $plugins,
                'message' => 'already quarantined',
            );
        }
        if (!$this->componentDirs($name)) {
            return array('ok' => true, 'name' => $name, 'message' => 'no component folders', 'missing' => true);
        }
        $ren = $this->renamePackage($name, self::SUFFIX_DISABLED, false);
        $plugins = $this->deactivatePackagePlugins($name);
        return array(
            'ok' => !empty($ren['ok']),
            'name' => $name,
            'marked_as' => $name . self::SUFFIX_DISABLED,
            'renamed' => isset($ren['renamed']) ? $ren['renamed'] : array(),
            'plugins' => $plugins,
            'message' => !empty($ren['ok']) ? 'quarantined' : 'rename failed',
        );
    }

    /**
     * Force-quarantine (no port attempt) + discover map['modUser'] killers + disable plugins + clear cache.
     * Used so Apply/setup never leave an empty MODX 3 manager.
     *
     * @return array
     */
    public function hardenSite()
    {
        $quarantined = array();
        $disabledPlugins = array();
        $names = self::knownBlockerNames();
        foreach ($this->discoverMapModUserKillers() as $extra) {
            if (!in_array($extra, $names, true)) {
                $names[] = $extra;
            }
        }
        foreach ($names as $name) {
            if ($this->isAlreadyMarked($name)) {
                continue;
            }
            if (!$this->componentDirs($name)) {
                continue;
            }
            $ren = $this->renamePackage($name, self::SUFFIX_DISABLED, false);
            if (!empty($ren['ok'])) {
                $quarantined[] = array(
                    'name' => $name,
                    'marked_as' => $name . self::SUFFIX_DISABLED,
                    'renamed' => $ren['renamed'],
                );
                $disabledPlugins = array_merge($disabledPlugins, $this->deactivatePackagePlugins($name));
            }
        }
        /* Also disable plugins for already-quarantined names (folder may have been renamed earlier). */
        foreach (self::knownBlockerNames() as $name) {
            $disabledPlugins = array_merge($disabledPlugins, $this->deactivatePackagePlugins($name));
        }
        $disabledPlugins = array_values(array_unique($disabledPlugins));
        $cache = $this->clearModxCache();
        $remaining = $this->listPresentBlockers();
        $result = array(
            'ok' => empty($remaining),
            'quarantined' => $quarantined,
            'disabled_plugins' => $disabledPlugins,
            'cache' => $cache,
            'remaining' => $remaining,
            'scanned_moduser_killers' => $this->discoverMapModUserKillers(true),
        );
        @file_put_contents(
            $this->storePath . 'site-harden.json',
            json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
        return $result;
    }

    /**
     * Live component folders that still match known blockers (not yet renamed).
     *
     * @return string[]
     */
    public function listPresentBlockers()
    {
        $out = array();
        foreach (self::knownBlockerNames() as $name) {
            if ($this->componentDirs($name)) {
                $out[] = $name;
            }
        }
        foreach ($this->discoverMapModUserKillers() as $name) {
            if (!in_array($name, $out, true) && $this->componentDirs($name)) {
                $out[] = $name;
            }
        }
        return $out;
    }

    /**
     * Find extras whose metadata still mutates $this->map['modUser'] (MODX 2 style).
     *
     * @param bool $includeQuarantined also report already-renamed folders
     * @return string[] package folder basenames (without suffix)
     */
    public function discoverMapModUserKillers($includeQuarantined = false)
    {
        $found = array();
        $core = $this->siteRoot . 'core/components';
        if (!is_dir($core)) {
            return $found;
        }
        $dh = @opendir($core);
        if ($dh === false) {
            return $found;
        }
        while (($entry = readdir($dh)) !== false) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $dir = $core . '/' . $entry;
            if (!is_dir($dir)) {
                continue;
            }
            $base = $entry;
            foreach (array(self::SUFFIX_DISABLED, self::SUFFIX_PORTED) as $suf) {
                if (substr($entry, -strlen($suf)) === $suf) {
                    $base = substr($entry, 0, -strlen($suf));
                    if (!$includeQuarantined) {
                        continue 2;
                    }
                }
            }
            if ($base === 'migratemodx' || $base === 'core') {
                continue;
            }
            $hit = false;
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $name = strtolower($file->getFilename());
                if (strpos($name, 'metadata') === false || substr($name, -4) !== '.php') {
                    continue;
                }
                $code = @file_get_contents($file->getPathname());
                if ($code === false) {
                    continue;
                }
                if (preg_match('/map\s*\[\s*[\'"]modUser[\'"]\s*\]/', $code)) {
                    $hit = true;
                    break;
                }
            }
            if ($hit && !in_array($base, $found, true)) {
                $found[] = $base;
            }
        }
        closedir($dh);
        return $found;
    }

    /**
     * Disable plugins that load from components/{name}/ (best-effort; needs live modX).
     *
     * @return string[] plugin names disabled
     */
    public function deactivatePackagePlugins($name)
    {
        $disabled = array();
        if (!is_object($this->modx) || !method_exists($this->modx, 'getCollection')) {
            return $disabled;
        }
        $name = strtolower((string)$name);
        if ($name === '') {
            return $disabled;
        }
        try {
            $cls = class_exists('\\MODX\\Revolution\\modPlugin')
                ? '\\MODX\\Revolution\\modPlugin'
                : 'modPlugin';
            $plugins = $this->modx->getCollection($cls);
            if (!is_array($plugins) && !($plugins instanceof Traversable)) {
                return $disabled;
            }
            $needle = 'components/' . $name . '/';
            foreach ($plugins as $plugin) {
                if (!is_object($plugin) || !method_exists($plugin, 'get')) {
                    continue;
                }
                if ((int)$plugin->get('disabled') === 1) {
                    continue;
                }
                $pname = strtolower((string)$plugin->get('name'));
                $static = strtolower((string)$plugin->get('static_file'));
                $code = strtolower((string)$plugin->get('plugincode'));
                $match = (strpos($static, $needle) !== false)
                    || (strpos($code, $needle) !== false)
                    || (strpos($pname, $name) !== false);
                if (!$match) {
                    continue;
                }
                $plugin->set('disabled', 1);
                if (method_exists($plugin, 'save') && $plugin->save()) {
                    $disabled[] = (string)$plugin->get('name');
                }
            }
        } catch (Exception $e) {
            /* soft */
        } catch (Throwable $e) {
            /* soft */
        }
        return $disabled;
    }

    /**
     * Wipe MODX cache dirs so renamed extras stop loading after quarantine.
     *
     * @return array{ok:bool,cleared:string[]}
     */
    public function clearModxCache()
    {
        $cleared = array();
        $cacheRoot = $this->siteRoot . 'core/cache';
        if (!is_dir($cacheRoot)) {
            return array('ok' => true, 'cleared' => $cleared);
        }
        $skip = array('.', '..');
        $dh = @opendir($cacheRoot);
        if ($dh === false) {
            return array('ok' => false, 'cleared' => $cleared);
        }
        while (($entry = readdir($dh)) !== false) {
            if (in_array($entry, $skip, true)) {
                continue;
            }
            $path = $cacheRoot . '/' . $entry;
            if (is_dir($path)) {
                if ($this->rrmdir($path)) {
                    $cleared[] = $entry;
                }
            } elseif (@unlink($path)) {
                $cleared[] = $entry;
            }
        }
        closedir($dh);
        return array('ok' => true, 'cleared' => $cleared);
    }

    protected function rrmdir($dir)
    {
        if (!is_dir($dir)) {
            return @unlink($dir);
        }
        $ok = true;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $path = $file->getPathname();
            if ($file->isDir()) {
                if (!@rmdir($path)) {
                    $ok = false;
                }
            } elseif (!@unlink($path)) {
                $ok = false;
            }
        }
        if (!@rmdir($dir)) {
            $ok = false;
        }
        return $ok;
    }

    protected function isAlreadyMarked($name)
    {
        foreach (array(self::SUFFIX_DISABLED, self::SUFFIX_PORTED) as $suf) {
            foreach ($this->componentRoots() as $root) {
                if (is_dir($root . '/' . $name . $suf)) {
                    return true;
                }
            }
        }
        return false;
    }

    protected function componentRoots()
    {
        $roots = array();
        $core = $this->siteRoot . 'core/components';
        $assets = $this->siteRoot . 'assets/components';
        if (is_dir($core)) {
            $roots[] = $core;
        }
        if (is_dir($assets)) {
            $roots[] = $assets;
        }
        return $roots;
    }

    protected function componentDirs($name)
    {
        $dirs = array();
        foreach ($this->componentRoots() as $root) {
            $d = $root . '/' . $name;
            if (is_dir($d)) {
                $dirs[] = $d;
            }
        }
        return $dirs;
    }

    protected function portPackageFiles(array $dirs, $apply)
    {
        $filesChanged = 0;
        $phpHits = 0;
        $rewriteHits = 0;
        foreach ($dirs as $dir) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $path = str_replace('\\', '/', $file->getPathname());
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if (!in_array($ext, array('php', 'js', 'json', 'inc'), true)) {
                    continue;
                }
                $before = @file_get_contents($path);
                if ($before === false || $before === '') {
                    continue;
                }
                $after = $before;
                if ($ext === 'php' || $ext === 'inc') {
                    $transformed = $this->applyPhpRules($after);
                    if ($transformed !== $after) {
                        $phpHits++;
                        $after = $transformed;
                    }
                }
                foreach ($this->modx3Safe as $rep) {
                    if (empty($rep['from']) || strpos($after, $rep['from']) === false) {
                        continue;
                    }
                    $after = str_replace($rep['from'], $rep['to'], $after);
                    $rewriteHits++;
                }
                if ($after !== $before) {
                    $filesChanged++;
                    if ($apply) {
                        $bakDir = $this->storePath . 'patches/vendor/';
                        if (!is_dir($bakDir)) {
                            @mkdir($bakDir, 0755, true);
                        }
                        @file_put_contents($bakDir . md5($path) . '.bak', $before);
                        @file_put_contents($path, $after);
                    }
                }
            }
        }
        return array(
            'files_changed' => $filesChanged,
            'php_hits' => $phpHits,
            'rewrite_hits' => $rewriteHits,
        );
    }

    protected function applyPhpRules($code)
    {
        $out = $code;
        foreach ($this->phpRules as $rule) {
            if (empty($rule['autofix']) || !isset($rule['safety']) || $rule['safety'] !== 'high') {
                continue;
            }
            if ($rule['id'] === 'curly_offset') {
                $out = preg_replace(
                    '/(\$[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*(?:\[[^\]]+\])*)\{([\'"]?)([^}]+)\2\}/',
                    '$1[$2$3$2]',
                    $out
                );
            } elseif ($rule['id'] === 'dollar_brace_interp') {
                $out = preg_replace('/\\$\\{([a-zA-Z_][a-zA-Z0-9_]*)\\}/', '\$$1', $out);
            }
        }
        return $out;
    }

    protected function scanBlockers(array $dirs)
    {
        $hits = array();
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $path = $file->getPathname();
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if ($ext !== 'php' && $ext !== 'inc') {
                    continue;
                }
                $code = @file_get_contents($path);
                if ($code === false) {
                    continue;
                }
                foreach ($this->blockerPatterns as $re) {
                    if (@preg_match('/' . $re . '/i', $code)) {
                        $hits[] = $re;
                    }
                }
            }
        }
        return array_values(array_unique($hits));
    }

    /**
     * @param string $name
     * @param string $suffix
     * @param bool $retargetNamespace update modNamespace paths when porting
     */
    protected function renamePackage($name, $suffix, $retargetNamespace)
    {
        $renamed = array();
        $ok = false;
        foreach ($this->componentRoots() as $root) {
            $src = $root . '/' . $name;
            $dst = $root . '/' . $name . $suffix;
            if (!is_dir($src)) {
                continue;
            }
            if (is_dir($dst)) {
                $renamed[] = basename($dst) . ' (exists)';
                $ok = true;
                continue;
            }
            if (@rename($src, $dst)) {
                $renamed[] = basename($dst);
                $ok = true;
                if ($suffix === self::SUFFIX_PORTED) {
                    @file_put_contents(
                        $dst . '/.mmx-ported-modx3.json',
                        json_encode(array(
                            'package' => $name,
                            'marked_as' => $name . $suffix,
                            'ported_at' => date('c'),
                            'by' => 'MigrateMODX',
                        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                    );
                }
            }
        }
        if ($ok && $retargetNamespace && $suffix === self::SUFFIX_PORTED) {
            $this->retargetNamespace($name, $name . $suffix);
        }
        return array('ok' => $ok, 'renamed' => $renamed);
    }

    protected function retargetNamespace($oldName, $newFolder)
    {
        if (!is_object($this->modx) || !method_exists($this->modx, 'getObject')) {
            return;
        }
        try {
            $cls = class_exists('\\MODX\\Revolution\\modNamespace')
                ? '\\MODX\\Revolution\\modNamespace'
                : 'modNamespace';
            $ns = $this->modx->getObject($cls, array('name' => $oldName));
            if (!$ns) {
                return;
            }
            $path = (string)$ns->get('path');
            $assets = (string)$ns->get('assets_path');
            $path2 = preg_replace(
                '#components/' . preg_quote($oldName, '#') . '/?#',
                'components/' . $newFolder . '/',
                $path
            );
            $assets2 = preg_replace(
                '#components/' . preg_quote($oldName, '#') . '/?#',
                'components/' . $newFolder . '/',
                $assets
            );
            if ($path2 && $path2 !== $path) {
                $ns->set('path', $path2);
            }
            if ($assets2 && $assets2 !== $assets) {
                $ns->set('assets_path', $assets2);
            }
            $ns->save();
        } catch (Exception $e) {
            /* soft */
        } catch (Throwable $e) {
            /* soft */
        }
    }
}
