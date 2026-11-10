<?php
require_once dirname(__FILE__) . '/mmxpaths.class.php';
require_once dirname(__FILE__) . '/mmxstatejournal.class.php';
require_once dirname(__FILE__) . '/mmxenvironmentprobe.class.php';
require_once dirname(__FILE__) . '/mmxknowledgebase.class.php';
require_once dirname(__FILE__) . '/mmxpackageinventory.class.php';
require_once dirname(__FILE__) . '/mmxpackagerepoprobe.class.php';
require_once dirname(__FILE__) . '/mmxpackageownership.class.php';
require_once dirname(__FILE__) . '/mmxcodeheuristics.class.php';
require_once dirname(__FILE__) . '/mmxphpcompatprep.class.php';
require_once dirname(__FILE__) . '/mmxtemplatesyntaxscan.class.php';
require_once dirname(__FILE__) . '/mmxreadinessgate.class.php';
require_once dirname(__FILE__) . '/mmxsnapshotmanager.class.php';
require_once dirname(__FILE__) . '/mmxrollbackmanager.class.php';
require_once dirname(__FILE__) . '/mmxpostrollback.class.php';
require_once dirname(__FILE__) . '/mmxupgradeorchestrator.class.php';
require_once dirname(__FILE__) . '/mmxhealthchecker.class.php';
require_once dirname(__FILE__) . '/mmxrecovery.class.php';
require_once dirname(__FILE__) . '/mmxversioncatalog.class.php';
require_once dirname(__FILE__) . '/mmxvendorcompat.class.php';
require_once dirname(__FILE__) . '/mmxmigraterunner.class.php';

class MigrateMODX
{
    /** @var modX */
    public $modx;
    protected $corePath;
    protected $storePath;
    /** @var MmxStateJournal */
    protected $journal;
    protected $acceptedFile;

    public function __construct($modx)
    {
        $this->modx = $modx;
        $this->corePath = $modx->getOption('migratemodx_core_path', null, $modx->getOption('core_path') . 'components/migratemodx/');
        $base = defined('MODX_BASE_PATH') ? MODX_BASE_PATH : dirname(rtrim($modx->getOption('core_path'), '/\\')) . '/';
        $this->storePath = MmxPaths::resolveStorePath($base, $modx->getOption('migratemodx_store_path', null, ''));
        $this->journal = new MmxStateJournal($this->storePath);
        $this->acceptedFile = $this->storePath . 'accepted.json';
    }

    public function handle($action, array $data = array())
    {
        $action = preg_replace('/[^a-z0-9_\-]/', '', strtolower($action));
        try {
            switch ($action) {
                case 'audit':
                    return array('ok' => true, 'report' => $this->fullAudit());
                case 'accept':
                    return $this->acceptRisk(isset($data['key']) ? $data['key'] : '');
                case 'php_scan':
                    return array('ok' => true, 'findings' => $this->php()->scan(isset($data['scope']) ? $data['scope'] : 'all'));
                case 'php_dryrun':
                    return array(
                        'ok' => true,
                        'fixes' => $this->php()->dryRun(
                            isset($data['scope']) ? $data['scope'] : 'custom',
                            $data
                        ),
                    );
                case 'php_apply':
                    return array_merge(
                        array('ok' => true),
                        $this->php()->apply(
                            isset($data['scope']) ? $data['scope'] : 'custom',
                            $data
                        )
                    );
                case 'php_rollback':
                    return $this->php()->rollbackPatches(isset($data['journal']) ? $data['journal'] : null);
                case 'peek':
                    return $this->peekFinding($data);
                case 'snapshot_create':
                    return $this->snapshotCreate(isset($data['label']) ? $data['label'] : '');
                case 'snapshot_list':
                    return array('ok' => true, 'snapshots' => MmxSnapshotManager::fromModX($this->modx)->listSnapshots());
                case 'snapshot_verify':
                    return MmxSnapshotManager::fromModX($this->modx)->verify(isset($data['id']) ? $data['id'] : '');
                case 'rollback':
                    return $this->doRollback(isset($data['id']) ? $data['id'] : '');
                case 'recovery_activate':
                    return $this->recoveryActivate(isset($data['password']) ? $data['password'] : null);
                case 'recovery_deactivate':
                    return $this->recovery()->deactivate();
                case 'recovery_status':
                    return array('ok' => true, 'recovery' => $this->recovery()->status($this->siteUrl()));
                case 'upgrade_plan':
                    return array('ok' => true, 'plan' => $this->orchestrator()->plan());
                case 'upgrade_download':
                    return $this->orchestrator()->downloadTarget(
                        isset($data['version']) && $data['version'] !== ''
                            ? $data['version']
                            : null
                    );
                case 'upgrade_apply':
                    $zip = isset($data['zip']) ? $data['zip'] : '';
                    return $this->orchestrator()->applyDistro(
                        $zip,
                        !empty($data['confirm_staging']),
                        !empty($data['confirm_apply'])
                    );
                case 'health':
                    return array('ok' => true, 'health' => $this->health()->run());
                case 'journal':
                    return array('ok' => true, 'state' => $this->journal->read());
                case 'export_html':
                    return $this->exportHtml();
                case 'export_md':
                    return $this->exportMarkdown();
                case 'migrate_prepare':
                    return $this->runner()->prepare($data);
                case 'migrate_prepare_php':
                    return $this->runner()->preparePhp($data);
                case 'migrate_set_goal':
                    return $this->runner()->setGoal(isset($data['goal']) ? $data['goal'] : '');
                case 'migrate_status':
                    return $this->runner()->status();
                case 'migrate_report':
                    return $this->runner()->report();
                case 'migrate_apply_core':
                    return $this->runner()->applyCore($data);
                case 'migrate_apply_ms3_data':
                    return $this->runner()->applyMs3Data($data);
                case 'migrate_retire_ms2':
                    return $this->runner()->retireMs2($data);
                case 'migrate_repair_catalog':
                    return $this->runner()->repairCatalog($data);
                case 'migrate_heal_front':
                    return $this->runner()->healFront($data);
                case 'migrate_install_packages':
                    return $this->runner()->installPackages($data);
                case 'migrate_export_attention':
                    return $this->exportAttention();
                default:
                    return array('ok' => false, 'error' => 'unknown action: ' . $action);
            }
        } catch (Exception $e) {
            $this->journal->log('handle/' . $action . ': ' . $e->getMessage(), 'error');
            return array('ok' => false, 'error' => $e->getMessage());
        } catch (Throwable $e) {
            $this->journal->log('handle/' . $action . ': ' . $e->getMessage(), 'error');
            return array('ok' => false, 'error' => $e->getMessage());
        }
    }

    /**
     * Run a callback; on Exception/Error return $fallback and record a soft finding.
     *
     * @param string   $section
     * @param callable $fn
     * @param mixed    $fallback
     * @return mixed
     */
    protected function safeSection($section, $fn, $fallback)
    {
        try {
            return call_user_func($fn);
        } catch (Exception $e) {
            return $this->sectionFailed($section, $e->getMessage(), $fallback);
        } catch (Throwable $e) {
            return $this->sectionFailed($section, $e->getMessage(), $fallback);
        }
    }

    protected function sectionFailed($section, $message, $fallback)
    {
        $this->journal->log('audit/' . $section . ': ' . $message, 'error');
        if (!is_array($fallback)) {
            return $fallback;
        }
        $finding = array(
            'source' => 'system',
            'name' => $section,
            'id' => 0,
            'engine' => 'system',
            'severity' => 'warning',
            'code' => 'section_error',
            'message' => 'Section "' . $section . '" failed: ' . $message,
        );
        if (isset($fallback['issues']) && is_array($fallback['issues'])) {
            $fallback['issues'][] = array(
                'severity' => 'warning',
                'code' => 'section_error',
                'message' => $finding['message'],
            );
            return $fallback;
        }
        /* list-shaped findings arrays (packages/heuristics/php/templates) */
        $keys = array_keys($fallback);
        if ($fallback === array() || $keys === range(0, count($fallback) - 1)) {
            $fallback[] = $finding;
        }
        return $fallback;
    }

    /**
     * Resolve effective MODX/PHP targets from live catalogs + package floors.
     */
    public function resolveTargets(array $packages = array(), $kb = null)
    {
        if ($kb === null) {
            $kb = new MmxKnowledgeBase($this->corePath . 'data/kb/packages.json');
        }
        $catalog = new MmxVersionCatalog(array(
            'cache_dir' => $this->storePath . 'version-cache/',
            'timeout' => (int)$this->modx->getOption('migratemodx_repo_timeout', null, 10),
            'ttl' => (int)$this->modx->getOption('migratemodx_repo_cache_ttl', null, 21600),
        ));
        return $catalog->resolve(array(
            'setting_modx' => (string)$this->modx->getOption('migratemodx_target_modx', null, 'auto'),
            'setting_php' => (string)$this->modx->getOption('migratemodx_target_php', null, 'auto'),
            'packages' => $packages,
            'kb' => $kb,
        ));
    }

    public function fullAudit()
    {
        $self = $this;
        $kb = new MmxKnowledgeBase($this->corePath . 'data/kb/packages.json');
        $storePath = $this->storePath;

        /* Provisional targets for repo supports= filter (refined after inventory) */
        $preTargets = $this->safeSection('versions_pre', function () use ($self, $kb) {
            return $self->resolveTargets(array(), $kb);
        }, array(
            'effective_modx' => MmxPaths::DEFAULT_TARGET_MODX,
            'effective_php' => MmxPaths::DEFAULT_TARGET_PHP,
        ));
        $probeTarget = !empty($preTargets['effective_modx'])
            ? $preTargets['effective_modx']
            : MmxPaths::DEFAULT_TARGET_MODX;

        $repoReport = array('packages' => array(), 'repos' => array());
        $packages = $this->safeSection('packages', function () use ($self, $kb, $storePath, &$repoReport, $probeTarget) {
            $probe = null;
            $enabled = (bool)$self->modx->getOption('migratemodx_repo_check', null, true);
            if ($enabled && class_exists('MmxPackageRepoProbe')) {
                $modstore = (string)$self->modx->getOption(
                    'migratemodx_repo_modstore_url',
                    null,
                    'https://modstore.pro/extras/'
                );
                $modxRepo = (string)$self->modx->getOption(
                    'migratemodx_repo_modx_url',
                    null,
                    'https://rest.modx.com/extras/'
                );
                $probe = new MmxPackageRepoProbe(array(
                    'cache_dir' => $storePath . 'repo-cache/',
                    'timeout' => (int)$self->modx->getOption('migratemodx_repo_timeout', null, 8),
                    'ttl' => (int)$self->modx->getOption('migratemodx_repo_cache_ttl', null, 86400),
                    'target_modx' => $probeTarget,
                    'repos' => array(
                        'modx' => $modxRepo,
                        'modstore' => $modstore,
                    ),
                ));
            }
            $inv = new MmxPackageInventory($self->modx, $kb, $probe);
            $list = $inv->run();
            $repoReport = $inv->getRepoReport();
            return $list;
        }, array());

        $versions = $this->safeSection('versions', function () use ($self, $kb, $packages) {
            return $self->resolveTargets(is_array($packages) ? $packages : array(), $kb);
        }, is_array($preTargets) ? $preTargets : array(
            'effective_modx' => MmxPaths::DEFAULT_TARGET_MODX,
            'effective_php' => MmxPaths::DEFAULT_TARGET_PHP,
        ));
        $targetModx = !empty($versions['effective_modx']) ? $versions['effective_modx'] : MmxPaths::DEFAULT_TARGET_MODX;
        $targetPhp = !empty($versions['effective_php']) ? $versions['effective_php'] : MmxPaths::DEFAULT_TARGET_PHP;

        $env = $this->safeSection('environment', function () use ($self, $targetModx, $targetPhp, $versions) {
            $env = (new MmxEnvironmentProbe($self->modx))->run($targetModx, $targetPhp);
            $env['target_modx'] = $targetModx;
            $env['target_php'] = $targetPhp;
            $env['modx_latest_3'] = isset($versions['modx_latest_3']) ? $versions['modx_latest_3'] : '';
            $env['required_min_modx'] = isset($versions['required_min_modx']) ? $versions['required_min_modx'] : '';
            $env['required_min_php'] = isset($versions['required_min_php']) ? $versions['required_min_php'] : '';
            $env['target_modx_mode'] = isset($versions['modx_mode']) ? $versions['modx_mode'] : '';
            $env['target_php_mode'] = isset($versions['php_mode']) ? $versions['php_mode'] : '';
            return $env;
        }, array(
            'modx_version' => '',
            'php_version' => PHP_VERSION,
            'target_modx' => $targetModx,
            'target_php' => $targetPhp,
            'issues' => array(array(
                'severity' => 'warning',
                'code' => 'env_scan_failed',
                'message' => 'Environment probe failed',
            )),
        ));

        $pkgIndex = array();
        $replacedPackages = array();
        $vendorPackages = array();
        if (is_array($packages)) {
            foreach ($packages as $p) {
                if (!isset($p['name'])) {
                    continue;
                }
                $pkgIndex[$p['name']] = $p;
                $verdict = isset($p['verdict']) ? $p['verdict'] : '';
                if (in_array($verdict, array('replace', 'fail'), true)) {
                    $replacedPackages[] = $p['name'];
                }
                /* Every installed extra is vendor stock — not «custom code», even if KB unknown. */
                $vendorPackages[] = $p['name'];
            }
        }

        $heuristics = $this->safeSection('heuristics', function () use ($self) {
            return (new MmxCodeHeuristics($self->modx))->run();
        }, array());

        $php = $this->safeSection('php', function () use ($self) {
            $rows = $self->php()->scan('all');
            foreach ($rows as &$p) {
                unset($p['code_before']);
            }
            unset($p);
            return $rows;
        }, array());

        $templates = $this->safeSection('templates', function () use ($self) {
            return (new MmxTemplateSyntaxScan($self->modx))->run();
        }, array());

        $heuristics = $this->annotateFindings(is_array($heuristics) ? $heuristics : array(), $pkgIndex);
        $php = $this->annotateFindings(is_array($php) ? $php : array(), $pkgIndex);
        $templates = $this->annotateFindings(is_array($templates) ? $templates : array(), $pkgIndex);

        $snaps = $this->safeSection('snapshots', function () use ($self) {
            return MmxSnapshotManager::fromModX($self->modx)->listSnapshots();
        }, array());
        if (!is_array($snaps)) {
            $snaps = array();
        }

        $siteUrl = $this->siteUrl();
        $recovery = $this->safeSection('recovery', function () use ($self, $siteUrl) {
            return $self->recovery()->status($siteUrl);
        }, array('active' => false));

        $plan = $this->safeSection('plan', function () use ($self) {
            return $self->orchestrator()->plan();
        }, array('current' => '', 'steps' => array()));

        $report = array(
            'generated_at' => date('c'),
            'kb_version' => $kb->getVersion(),
            'versions' => is_array($versions) ? $versions : array(),
            'environment' => $env,
            'packages' => is_array($packages) ? $packages : array(),
            'repos' => is_array($repoReport) ? $repoReport : array('packages' => array(), 'repos' => array()),
            'replaced_packages' => $replacedPackages,
            'vendor_packages' => $vendorPackages,
            'heuristics' => $heuristics,
            'php' => $php,
            'templates' => $templates,
            'snapshots' => array(
                'latest' => $snaps ? $snaps[0] : null,
                'count' => count($snaps),
            ),
            'recovery' => is_array($recovery) ? $recovery : array('active' => false),
            'plan' => is_array($plan) ? $plan : array('current' => '', 'steps' => array()),
            'accepted' => $this->loadAccepted(),
        );
        $gate = (new MmxReadinessGate())->evaluate($report, $this->loadAccepted());
        $report['gate'] = $gate;
        @file_put_contents($this->storePath . 'last-audit.json', json_encode($report, JSON_UNESCAPED_UNICODE));
        return $report;
    }

    /**
     * Attach package verdict / keeper flags.
     * Keepers = hand-written custom elements + manual overrides inside extras.
     * Stock of ANY installed package (moddevtools, FormIt, …) is not a keeper —
     * even when KB verdict is unknown.
     */
    protected function annotateFindings(array $findings, array $pkgIndex)
    {
        foreach ($findings as &$f) {
            $pkg = isset($f['package']) ? strtolower((string)$f['package']) : '';
            if ($pkg === '' && !empty($f['path'])) {
                if (preg_match('#/components/([a-zA-Z0-9_\-]+)/#', str_replace('\\', '/', $f['path']), $m)) {
                    $pkg = strtolower($m[1]);
                    $f['package'] = $pkg;
                }
            }
            $manual = !empty($f['manual_override']);
            if ($pkg === '' || $pkg === 'custom') {
                $f['package'] = $pkg === '' ? 'custom' : $pkg;
                $f['package_verdict'] = 'custom';
                $f['will_be_replaced'] = false;
                $f['keeper'] = true;
                $f['manual_override'] = false;
            } elseif (isset($pkgIndex[$pkg])) {
                $p = $pkgIndex[$pkg];
                $f['package_verdict'] = isset($p['verdict']) ? $p['verdict'] : 'unknown';
                $f['package_resolution'] = isset($p['resolution']) ? $p['resolution'] : 'none';
                if ($manual) {
                    /* Developer edited vendor element/file — still needs attention. */
                    $f['will_be_replaced'] = false;
                    $f['keeper'] = true;
                    $f['manual_override'] = true;
                } else {
                    /* Installed extra stock — update/replace the package, do not monkey-patch. */
                    $f['will_be_replaced'] = true;
                    $f['keeper'] = false;
                    $f['manual_override'] = false;
                }
                if (!empty($p['successor'])) {
                    $f['successor'] = $p['successor'];
                }
            } else {
                /* Path under components/X but X is not an installed package —
                   treat as hand-rolled project extra (keeper), unless flagged manual. */
                $f['package_verdict'] = 'project_extra';
                $f['will_be_replaced'] = false;
                $f['keeper'] = true;
                if ($manual) {
                    $f['manual_override'] = true;
                }
            }
            if (empty($f['uid'])) {
                $f['uid'] = implode('|', array(
                    isset($f['source']) ? $f['source'] : '',
                    isset($f['id']) ? (string)$f['id'] : '0',
                    isset($f['path']) ? $f['path'] : '',
                    isset($f['rule']) ? $f['rule'] : (isset($f['code']) ? $f['code'] : (isset($f['engine']) ? $f['engine'] : '')),
                    isset($f['name']) ? $f['name'] : '',
                ));
            }
            unset($f['code_before']);
        }
        unset($f);
        return $findings;
    }

    protected function peekFinding(array $data)
    {
        $source = isset($data['source']) ? (string)$data['source'] : '';
        $id = isset($data['id']) ? (int)$data['id'] : 0;
        $path = isset($data['path']) ? str_replace('\\', '/', (string)$data['path']) : '';
        $max = 80000;

        if (in_array($source, array('snippet', 'plugin', 'chunk', 'template'), true) && $id > 0) {
            $map = array(
                'snippet' => array('modSnippet', 'snippet'),
                'plugin' => array('modPlugin', 'plugincode'),
                'chunk' => array('modChunk', 'snippet'),
                'template' => array('modTemplate', 'content'),
            );
            $cls = $map[$source][0];
            $field = $map[$source][1];
            $full = class_exists('\\MODX\\Revolution\\' . $cls) ? '\\MODX\\Revolution\\' . $cls : $cls;
            $el = $this->modx->getObject($full, $id);
            if (!$el) {
                return array('ok' => false, 'error' => 'Element not found');
            }
            $name = $source === 'template' ? (string)$el->get('templatename') : (string)$el->get('name');
            $content = (string)$el->get($field);
            $truncated = strlen($content) > $max;
            if ($truncated) {
                $content = substr($content, 0, $max) . "\n/* … truncated … */";
            }
            return array(
                'ok' => true,
                'source' => $source,
                'id' => $id,
                'name' => $name,
                'content' => $content,
                'truncated' => $truncated,
                'mgr_hint' => $source . ' #' . $id . ' — ' . $name,
            );
        }

        if (($source === 'file' || $source === 'component_file' || $path !== '') && $path !== '') {
            $base = str_replace('\\', '/', rtrim(MODX_BASE_PATH, '/\\'));
            $core = str_replace('\\', '/', rtrim(MODX_CORE_PATH, '/\\'));
            $real = realpath($path);
            if ($real === false || !is_file($real)) {
                return array('ok' => false, 'error' => 'File not found');
            }
            $real = str_replace('\\', '/', $real);
            if (strpos($real, $base) !== 0 && strpos($real, $core) !== 0) {
                return array('ok' => false, 'error' => 'Path outside site');
            }
            $content = (string)@file_get_contents($real);
            $truncated = strlen($content) > $max;
            if ($truncated) {
                $content = substr($content, 0, $max) . "\n/* … truncated … */";
            }
            return array(
                'ok' => true,
                'source' => 'file',
                'path' => $real,
                'name' => $real,
                'content' => $content,
                'truncated' => $truncated,
                'mgr_hint' => $real,
            );
        }

        return array('ok' => false, 'error' => 'Nothing to peek');
    }

    protected function acceptRisk($key)
    {
        if ($key === '') {
            return array('ok' => false, 'error' => 'key required');
        }
        $a = $this->loadAccepted();
        $a[$key] = array('at' => date('c'), 'user' => $this->modx->user ? $this->modx->user->get('username') : '');
        file_put_contents($this->acceptedFile, json_encode($a, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return array('ok' => true, 'accepted' => $a);
    }

    protected function loadAccepted()
    {
        if (!is_file($this->acceptedFile)) {
            return array();
        }
        $a = json_decode((string)file_get_contents($this->acceptedFile), true);
        return is_array($a) ? $a : array();
    }

    protected function snapshotCreate($label, array $opts = array())
    {
        $sm = MmxSnapshotManager::fromModX($this->modx);
        $manifest = $sm->create($label);
        $reactivate = !array_key_exists('reactivate_recovery', $opts) || !empty($opts['reactivate_recovery']);
        $status = $this->recovery()->status($this->siteUrl());
        if ($reactivate || empty($status['active'])) {
            $rec = $this->recoveryActivate(null);
        } else {
            /* Keep existing Recovery password; return URLs without rotating. */
            $rec = $status;
            $rec['ok'] = true;
            $fa = $this->recovery()->ensureFirstAidShortcut($this->siteUrl());
            if (!empty($fa['ok'])) {
                $rec['firstaid'] = $fa['firstaid'];
                $rec['firstaid_url'] = $fa['firstaid_url'];
            }
        }
        $this->journal->write(array('phase' => 'snapshot', 'last_error' => ''));
        $this->journal->log('Snapshot created: ' . $manifest['id']);
        return array('ok' => true, 'snapshot' => $manifest, 'recovery' => $rec);
    }

    protected function doRollback($id)
    {
        if ($id === '') {
            return array('ok' => false, 'error' => 'snapshot id required');
        }
        $sm = MmxSnapshotManager::fromModX($this->modx);
        $rb = new MmxRollbackManager($sm);
        $this->journal->write(array('phase' => 'rollback'));
        $res = $rb->restore($id);
        $this->journal->log('Rollback ' . ($res['ok'] ? 'ok' : 'failed') . ': ' . $id, $res['ok'] ? 'info' : 'error');
        if (!empty($res['ok'])) {
            $res['migrate_reset'] = MmxPostRollback::resetMigrateProgress(
                $this->storePath,
                $id,
                'manager_rollback'
            );
            try {
                $report = $this->fullAudit();
                MmxPostRollback::updateFlag($this->storePath, array(
                    'pending_audit' => false,
                    'audit_ok' => true,
                    'audit_at' => date('c'),
                    'audit_via' => 'manager_rollback',
                ));
                $res['audit'] = array('ok' => true, 'report' => $report);
                $res['report'] = $report;
                $this->journal->log('Post-rollback audit ok');
            } catch (Exception $e) {
                $res['audit'] = array('ok' => false, 'pending' => true, 'error' => $e->getMessage());
                $this->journal->log('Post-rollback audit deferred: ' . $e->getMessage(), 'error');
            } catch (Throwable $e) {
                $res['audit'] = array('ok' => false, 'pending' => true, 'error' => $e->getMessage());
                $this->journal->log('Post-rollback audit deferred: ' . $e->getMessage(), 'error');
            }
        }
        return $res;
    }

    protected function siteUrl()
    {
        $url = (string)$this->modx->getOption('site_url', null, '');
        if ($url === '' && defined('MODX_SITE_URL')) {
            $url = (string)MODX_SITE_URL;
        }
        return rtrim($url, '/') . '/';
    }

    protected function recoveryActivate($password)
    {
        $assets = $this->modx->getOption('assets_path') . 'components/migratemodx/';
        $r = new MmxRecovery($this->storePath, MODX_BASE_PATH, $assets);
        return $r->activate($password, $this->siteUrl());
    }

    protected function recovery()
    {
        $assets = $this->modx->getOption('assets_path') . 'components/migratemodx/';
        return new MmxRecovery($this->storePath, MODX_BASE_PATH, $assets);
    }

    protected function php()
    {
        return new MmxPhpCompatPrep($this->modx, $this->corePath . 'data/php/rules.php', $this->storePath);
    }

    protected function orchestrator()
    {
        return new MmxUpgradeOrchestrator($this->modx, $this->journal, $this->storePath);
    }

    protected function health()
    {
        return new MmxHealthChecker($this->modx, $this->journal);
    }

    protected function runner()
    {
        return new MmxMigrateRunner($this, $this->modx, $this->corePath, $this->storePath);
    }

    /** @internal used by MmxMigrateRunner */
    public function publicFullAudit()
    {
        return $this->fullAudit();
    }

    /** @internal used by MmxMigrateRunner */
    public function publicSnapshotCreate($label, array $opts = array())
    {
        return $this->snapshotCreate($label, $opts);
    }

    /** @internal used by MmxMigrateRunner */
    public function publicRecoveryStatus()
    {
        return $this->recovery()->status($this->siteUrl());
    }

    /** @internal used by MmxMigrateRunner */
    public function publicPhpApply($scope, array $opts)
    {
        return $this->php()->apply($scope, $opts);
    }

    /** @internal used by MmxMigrateRunner */
    public function publicOrchestrator()
    {
        return $this->orchestrator();
    }

    /** @internal used by MmxMigrateRunner */
    public function publicHealth()
    {
        return array('ok' => true, 'health' => $this->health()->run());
    }

    protected function exportAttention()
    {
        $path = $this->storePath . 'last-attention.md';
        if (!is_file($path)) {
            $json = $this->storePath . 'last-attention.json';
            if (!is_file($json)) {
                return array('ok' => false, 'error' => 'No Attention Report yet');
            }
            $att = json_decode((string)file_get_contents($json), true);
            $md = is_array($att) && !empty($att['agent_export'])
                ? $att['agent_export']
                : "# MigrateMODX Attention Report\n\n(empty)\n";
            file_put_contents($path, $md);
        }
        return array('ok' => true, '_file' => array($path, 'migratemodx-attention.md', 'text/markdown; charset=utf-8'));
    }

    protected function exportHtml()
    {
        $report = $this->fullAudit();
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>MigrateMODX Report</title>'
            . '<style>body{font-family:sans-serif;max-width:960px;margin:24px auto;padding:0 16px}'
            . 'table{border-collapse:collapse;width:100%}td,th{border:1px solid #ccc;padding:6px;text-align:left}'
            . '.red{color:#b00020}.yellow{color:#a67c00}.green{color:#0a7a32}</style></head><body>';
        $html .= '<h1>MigrateMODX readiness</h1>';
        $html .= '<p>Generated: ' . htmlspecialchars($report['generated_at']) . '</p>';
        $g = $report['gate'];
        $html .= '<p class="' . htmlspecialchars($g['traffic']) . '">Score: ' . (int)$g['score']
            . ' — upgrade_ready=' . (!empty($g['upgrade_ready']) ? 'yes' : 'no')
            . ' — php_ready=' . (!empty($g['php_ready']) ? 'yes' : 'no') . '</p>';
        $html .= '<h2>Blockers</h2><ul>';
        foreach ($g['blockers'] as $b) {
            $html .= '<li>' . htmlspecialchars($b['message']) . '</li>';
        }
        $html .= '</ul><h2>Packages</h2><table><tr><th>Name</th><th>Version</th><th>Verdict</th><th>Notes</th></tr>';
        foreach ($report['packages'] as $p) {
            $html .= '<tr><td>' . htmlspecialchars($p['name']) . '</td><td>' . htmlspecialchars($p['version'])
                . '</td><td>' . htmlspecialchars($p['verdict']) . '</td><td>' . htmlspecialchars($p['notes']) . '</td></tr>';
        }
        $html .= '</table></body></html>';
        $path = $this->storePath . 'report-' . date('Ymd-His') . '.html';
        file_put_contents($path, $html);
        return array('ok' => true, '_file' => array($path, basename($path), 'text/html; charset=utf-8'));
    }

    protected function exportMarkdown()
    {
        $report = $this->fullAudit();
        $md = "# MigrateMODX report\n\nGenerated: {$report['generated_at']}\n\n";
        $md .= "Score: {$report['gate']['score']} ({$report['gate']['traffic']})\n\n## Blockers\n\n";
        foreach ($report['gate']['blockers'] as $b) {
            $md .= '- ' . $b['message'] . "\n";
        }
        $md .= "\n## Packages\n\n";
        foreach ($report['packages'] as $p) {
            $md .= "- **{$p['name']}** {$p['version']}: {$p['verdict']} — {$p['notes']}\n";
        }
        $path = $this->storePath . 'report-' . date('Ymd-His') . '.md';
        file_put_contents($path, $md);
        return array('ok' => true, '_file' => array($path, basename($path), 'text/markdown; charset=utf-8'));
    }
}
