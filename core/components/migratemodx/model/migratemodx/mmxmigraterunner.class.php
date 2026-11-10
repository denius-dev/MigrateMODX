<?php
require_once dirname(__FILE__) . '/mmxmodx3rewrite.class.php';
require_once dirname(__FILE__) . '/mmxms2migrator.class.php';
require_once dirname(__FILE__) . '/mmxvendorcompat.class.php';
require_once dirname(__FILE__) . '/mmxpostrollback.class.php';
require_once dirname(__FILE__) . '/mmxms3frontcompat.class.php';
require_once dirname(__FILE__) . '/mmxpackageinstaller.class.php';

/**
 * Orchestrates prepare pipeline (no core apply) + Attention Report + gated core apply.
 */
class MmxMigrateRunner
{
    /** @var MigrateMODX */
    protected $app;
    /** @var modX */
    protected $modx;
    protected $storePath;
    protected $corePath;
    protected $runFile;
    protected $attentionFile;

    public function __construct(MigrateMODX $app, $modx, $corePath, $storePath)
    {
        $this->app = $app;
        $this->modx = $modx;
        $this->corePath = rtrim(str_replace('\\', '/', $corePath), '/') . '/';
        $this->storePath = rtrim(str_replace('\\', '/', $storePath), '/') . '/';
        $this->runFile = $this->storePath . 'migrate-run.json';
        $this->attentionFile = $this->storePath . 'last-attention.json';
        if (is_object($this->modx) && isset($this->modx->lexicon) && is_object($this->modx->lexicon)
            && method_exists($this->modx->lexicon, 'load')) {
            $this->modx->lexicon->load('migratemodx:default');
        }
    }

    /** Manager UI language is Russian? */
    protected function isRu()
    {
        if (!is_object($this->modx) || !method_exists($this->modx, 'getOption')) {
            return false;
        }
        $lang = (string)$this->modx->getOption(
            'manager_language',
            null,
            $this->modx->getOption('cultureKey', null, 'en')
        );
        return stripos($lang, 'ru') === 0;
    }

    /**
     * Lexicon helper: keys are migratemodx_<$key> (e.g. msg_run_prepare).
     *
     * @param string $key
     * @param string $fallback English fallback
     * @param array $ph
     * @return string
     */
    protected function lex($key, $fallback = '', array $ph = array())
    {
        $full = 'migratemodx_' . $key;
        if (!is_object($this->modx) || !method_exists($this->modx, 'lexicon')) {
            return $fallback !== '' ? $fallback : $key;
        }
        $s = $this->modx->lexicon($full, $ph);
        if ($s === $full || $s === '' || $s === null) {
            return $fallback !== '' ? $fallback : $key;
        }
        return $s;
    }

    /**
     * Phase 1 prepare (MODX 2): snapshot → audit → php keepers → rewrites → matrix → ms3 dry-run only → report.
     * Never applies ms3 data or core here (ignore apply_ms3_data / confirm_ms3).
     *
     * @param array $opts skip_snapshot
     * @return array
     */
    public function prepare(array $opts = array())
    {
        $run = array(
            'started_at' => date('c'),
            'finished_at' => null,
            'status' => 'running',
            'steps' => array(),
            'upgrade_ready' => false,
            'hard_blocked' => false,
            'phase' => 1,
            'goal' => 'modx3',
            'opts' => array(
                'skip_snapshot' => !empty($opts['skip_snapshot']),
            ),
        );
        $this->saveRun($run);

        $attention = array(
            'generated_at' => date('c'),
            'done' => array(),
            'failed' => array(),
            'needs_attention' => array(),
            'packages' => array(),
            'ms3' => array(),
            'steps' => array(),
            'gate' => null,
            'agent_export' => '',
        );

        // 1. Snapshot + Recovery
        $recoveryOut = null;
        $step = $this->beginStep($run, 'snapshot_recovery', 'Snapshot + Recovery');
        try {
            if (empty($opts['skip_snapshot'])) {
                $snap = $this->app->publicSnapshotCreate('migrate-prepare');
                $this->finishStep($run, $step, !empty($snap['ok']), $snap);
                if (!empty($snap['ok'])) {
                    $sid = '';
                    if (!empty($snap['snapshot']['id'])) {
                        $sid = $snap['snapshot']['id'];
                    } elseif (!empty($snap['id'])) {
                        $sid = $snap['id'];
                    }
                    $attention['done'][] = array(
                        'id' => 'snapshot',
                        'message' => $this->lex(
                            'msg_snapshot_created',
                            'Snapshot created' . ($sid !== '' ? ': ' . $sid : ''),
                            array('id' => $sid !== '' ? ': ' . $sid : '')
                        ),
                    );
                    if (!empty($snap['recovery']) && is_array($snap['recovery'])) {
                        $recoveryOut = $snap['recovery'];
                        $attention['needs_attention'][] = array(
                            'id' => 'recovery_credentials',
                            'message' => $this->lex(
                                'msg_recovery_save',
                                'Save Recovery URL and password now — shown once after activate.'
                            ),
                            'recovery' => array(
                                'url' => isset($snap['recovery']['url']) ? $snap['recovery']['url'] : '',
                                'shortcut_url' => isset($snap['recovery']['shortcut_url']) ? $snap['recovery']['shortcut_url'] : '',
                                'shortcut' => isset($snap['recovery']['shortcut']) ? $snap['recovery']['shortcut'] : 'migratemodx-recovery.php',
                            ),
                        );
                    }
                } else {
                    $attention['failed'][] = array(
                        'id' => 'snapshot',
                        'message' => isset($snap['error']) ? $snap['error'] : $this->lex('msg_snapshot_failed', 'Snapshot failed'),
                    );
                    return $this->abortHard($run, $attention, 'snapshot_recovery');
                }
            } else {
                $this->finishStep($run, $step, true, array('skipped' => true));
                $attention['done'][] = array(
                    'id' => 'snapshot_skipped',
                    'message' => $this->lex('msg_snapshot_skipped', 'Snapshot skipped by flag'),
                );
            }
        } catch (Exception $e) {
            $this->finishStep($run, $step, false, array('error' => $e->getMessage()));
            $attention['failed'][] = array('id' => 'snapshot', 'message' => $e->getMessage());
            return $this->abortHard($run, $attention, 'snapshot_recovery');
        } catch (Throwable $e) {
            $this->finishStep($run, $step, false, array('error' => $e->getMessage()));
            $attention['failed'][] = array('id' => 'snapshot', 'message' => $e->getMessage());
            return $this->abortHard($run, $attention, 'snapshot_recovery');
        }

        // 2. Full audit
        $step = $this->beginStep($run, 'full_audit', 'Full audit');
        try {
            $report = $this->app->publicFullAudit();
            $this->finishStep($run, $step, true, array(
                'score' => isset($report['gate']['score']) ? $report['gate']['score'] : null,
                'upgrade_ready' => !empty($report['gate']['upgrade_ready']),
            ));
            $attention['done'][] = array(
                'id' => 'audit',
                'message' => $this->lex('msg_audit_done', 'Full audit completed'),
            );
            $attention['gate'] = isset($report['gate']) ? $report['gate'] : null;
        } catch (Exception $e) {
            $this->finishStep($run, $step, false, array('error' => $e->getMessage()));
            $attention['failed'][] = array('id' => 'audit', 'message' => $e->getMessage());
            return $this->abortHard($run, $attention, 'full_audit');
        } catch (Throwable $e) {
            $this->finishStep($run, $step, false, array('error' => $e->getMessage()));
            $attention['failed'][] = array('id' => 'audit', 'message' => $e->getMessage());
            return $this->abortHard($run, $attention, 'full_audit');
        }

        // 3. PHP autofix keepers
        $step = $this->beginStep($run, 'php_keepers', 'PHP autofix (keepers)');
        try {
            $vendor = isset($report['vendor_packages']) ? $report['vendor_packages'] : array();
            $phpResult = $this->app->publicPhpApply('all', array(
                'mode' => 'keepers',
                'vendor_packages' => $vendor,
            ));
            $applied = isset($phpResult['applied']) ? (int)$phpResult['applied'] : 0;
            $this->finishStep($run, $step, true, $phpResult);
            $attention['done'][] = array(
                'id' => 'php_keepers',
                'message' => $this->lex(
                    'msg_php_keepers',
                    'PHP keepers autofix applied: ' . $applied,
                    array('n' => $applied)
                ),
            );
        } catch (Exception $e) {
            $this->finishStep($run, $step, false, array('error' => $e->getMessage()));
            $attention['failed'][] = array('id' => 'php_keepers', 'message' => $e->getMessage());
            return $this->abortHard($run, $attention, 'php_keepers');
        } catch (Throwable $e) {
            $this->finishStep($run, $step, false, array('error' => $e->getMessage()));
            $attention['failed'][] = array('id' => 'php_keepers', 'message' => $e->getMessage());
            return $this->abortHard($run, $attention, 'php_keepers');
        }

        // 4. MODX 3 / ms2 code rewrites
        $step = $this->beginStep($run, 'modx3_rewrites', 'MODX 3 / ms2 code rewrites');
        try {
            $rewriter = new MmxModx3Rewrite(
                $this->modx,
                $this->corePath . 'data/rewrites',
                $this->storePath
            );
            $rw = $rewriter->run(true);
            $this->finishStep($run, $step, !empty($rw['ok']), $rw);
            $attention['done'] = array_merge($attention['done'], isset($rw['done']) ? $rw['done'] : array());
            $attention['needs_attention'] = array_merge(
                $attention['needs_attention'],
                isset($rw['needs_attention']) ? $rw['needs_attention'] : array()
            );
            if (!empty($rw['failed'])) {
                $attention['failed'] = array_merge($attention['failed'], $rw['failed']);
                return $this->abortHard($run, $attention, 'modx3_rewrites');
            }
        } catch (Exception $e) {
            $this->finishStep($run, $step, false, array('error' => $e->getMessage()));
            $attention['failed'][] = array('id' => 'modx3_rewrites', 'message' => $e->getMessage());
            return $this->abortHard($run, $attention, 'modx3_rewrites');
        } catch (Throwable $e) {
            $this->finishStep($run, $step, false, array('error' => $e->getMessage()));
            $attention['failed'][] = array('id' => 'modx3_rewrites', 'message' => $e->getMessage());
            return $this->abortHard($run, $attention, 'modx3_rewrites');
        }

        // 5. Package compatibility matrix
        $step = $this->beginStep($run, 'package_matrix', 'Package compatibility matrix');
        try {
            $matrix = $this->buildPackageMatrix(isset($report['packages']) ? $report['packages'] : array());
            $attention['packages'] = $matrix;
            foreach ($matrix as $row) {
                if (!empty($row['conflicts']) || !empty($row['needs_update']) || empty($row['modx3_ok'])) {
                    $attention['needs_attention'][] = array(
                        'id' => 'pkg_' . $row['name'],
                        'message' => $this->packageAttentionMessage($row),
                        'package' => $row['name'],
                    );
                }
            }
            $this->finishStep($run, $step, true, array('count' => count($matrix)));
            $attention['done'][] = array(
                'id' => 'package_matrix',
                'message' => $this->lex(
                    'msg_package_matrix',
                    'Package matrix built: ' . count($matrix) . ' packages',
                    array('n' => count($matrix))
                ),
            );
        } catch (Exception $e) {
            $this->finishStep($run, $step, false, array('error' => $e->getMessage()));
            $attention['failed'][] = array('id' => 'package_matrix', 'message' => $e->getMessage());
            return $this->abortHard($run, $attention, 'package_matrix');
        } catch (Throwable $e) {
            $this->finishStep($run, $step, false, array('error' => $e->getMessage()));
            $attention['failed'][] = array('id' => 'package_matrix', 'message' => $e->getMessage());
            return $this->abortHard($run, $attention, 'package_matrix');
        }

        // 5b. Vendor compat: port or quarantine «won't survive» extras + hard site harden
        $step = $this->beginStep($run, 'vendor_compat', 'Vendor port / quarantine');
        try {
            $vc = $this->vendorCompat();
            $pkgs = isset($report['packages']) ? $report['packages'] : array();
            $vendorRes = $vc->process($pkgs, true);
            /* Always force-quarantine known manager killers (Tickets…) regardless of matrix verdict. */
            $harden = $vc->hardenSite();
            $vendorRes['harden'] = $harden;
            $attention['vendor_compat'] = $vendorRes;
            foreach ((array)$vendorRes['ported'] as $row) {
                $attention['done'][] = array(
                    'id' => 'vendor_ported_' . $row['name'],
                    'bucket' => 'known',
                    'message' => $this->lex(
                        'msg_vendor_ported',
                        'Ported «won\'t survive» package: ' . $row['marked_as'],
                        array('name' => $row['marked_as'])
                    ),
                    'package' => $row['name'],
                );
            }
            foreach ((array)$vendorRes['quarantined'] as $row) {
                $attention['done'][] = array(
                    'id' => 'vendor_quarantine_' . $row['name'],
                    'bucket' => 'known',
                    'message' => $this->lex(
                        'msg_vendor_quarantined',
                        'Quarantined incompatible package: ' . $row['marked_as'],
                        array('name' => $row['marked_as'])
                    ),
                    'package' => $row['name'],
                );
            }
            foreach ((array)$harden['quarantined'] as $row) {
                $attention['done'][] = array(
                    'id' => 'harden_quarantine_' . $row['name'],
                    'bucket' => 'known',
                    'message' => $this->lex(
                        'msg_harden_quarantined',
                        'Hardened (force quarantine): ' . $row['marked_as'],
                        array('name' => $row['marked_as'])
                    ),
                    'package' => $row['name'],
                );
            }
            foreach ((array)$vendorRes['manual'] as $row) {
                $attention['needs_attention'][] = array(
                    'id' => 'vendor_manual_' . $row['name'],
                    'bucket' => 'known',
                    'severity' => 'soft',
                    'message' => isset($row['message']) ? $row['message'] : ('Manual action for ' . $row['name']),
                    'package' => $row['name'],
                );
            }
            $remaining = isset($harden['remaining']) ? $harden['remaining'] : $vc->listPresentBlockers();
            $this->finishStep($run, $step, empty($remaining), array(
                'ported' => count($vendorRes['ported']),
                'quarantined' => count($vendorRes['quarantined']),
                'harden' => count($harden['quarantined']),
                'manual' => count($vendorRes['manual']),
                'remaining' => $remaining,
            ));
            $attention['done'][] = array(
                'id' => 'vendor_compat',
                'message' => $this->lex(
                    'msg_vendor_compat_done',
                    'Vendor compat: ported '
                        . count($vendorRes['ported'])
                        . ', quarantined '
                        . count($vendorRes['quarantined'])
                        . ', manual '
                        . count($vendorRes['manual']),
                    array(
                        'ported' => count($vendorRes['ported']),
                        'quarantined' => count($vendorRes['quarantined']),
                        'manual' => count($vendorRes['manual']),
                    )
                ),
            );
            if (!empty($remaining)) {
                $run['hard_blocked'] = true;
                $this->saveRun($run);
                $attention['failed'][] = array(
                    'id' => 'manager_killers_present',
                    'bucket' => 'known',
                    'severity' => 'hard',
                    'message' => $this->lex(
                        'msg_manager_killers_present',
                        'Manager/setup killers still on disk: '
                            . implode(', ', $remaining)
                            . '. Rename failed — fix permissions or open /migratemodx-firstaid.php, then re-run Prepare.',
                        array('list' => implode(', ', $remaining))
                    ),
                    'packages' => $remaining,
                );
                return $this->abortHard($run, $attention, 'vendor_compat');
            }
            $attention['needs_attention'][] = array(
                'id' => 'firstaid_ready',
                'bucket' => 'known',
                'severity' => 'soft',
                'message' => $this->lex(
                    'msg_firstaid_ready',
                    'If the manager is empty after /setup/, open /migratemodx-firstaid.php — one click quarantines Tickets/AdminTools/Batcher and clears cache. No FTP.'
                ),
            );
        } catch (Exception $e) {
            $this->finishStep($run, $step, false, array('error' => $e->getMessage()));
            $attention['failed'][] = array('id' => 'vendor_compat', 'message' => $e->getMessage());
            return $this->abortHard($run, $attention, 'vendor_compat');
        } catch (Throwable $e) {
            $this->finishStep($run, $step, false, array('error' => $e->getMessage()));
            $attention['failed'][] = array('id' => 'vendor_compat', 'message' => $e->getMessage());
            return $this->abortHard($run, $attention, 'vendor_compat');
        }

        // 6. ms2→ms3 schema/code dry-run only (apply is Phase 3 after MODX 3 + MiniShop3)
        $step = $this->beginStep($run, 'ms2_ms3_data', 'ms2→ms3 dry-run (Phase 1)');
        try {
            $migrator = new MmxMs2Migrator(
                $this->modx,
                $this->corePath . 'data/rewrites',
                $this->storePath
            );
            $ms = $migrator->run(false, false);
            $attention['ms3'] = isset($ms['ms3']) ? $ms['ms3'] : array();
            $attention['has_ms2'] = !empty($ms['has_ms2']) || $this->packageInstalled('minishop2');
            $attention['done'] = array_merge($attention['done'], isset($ms['done']) ? $ms['done'] : array());
            $attention['needs_attention'] = array_merge(
                $attention['needs_attention'],
                isset($ms['needs_attention']) ? $ms['needs_attention'] : array()
            );
            if (!empty($ms['failed'])) {
                $attention['failed'] = array_merge($attention['failed'], $ms['failed']);
            }
            $hardMs = false;
            foreach ((array)(isset($ms['needs_attention']) ? $ms['needs_attention'] : array()) as $n) {
                if (isset($n['severity']) && $n['severity'] === 'hard') {
                    $hardMs = true;
                    break;
                }
            }
            $okMs = !empty($ms['ok']) && !$hardMs && empty($ms['failed']);
            $this->finishStep($run, $step, $okMs || (!empty($ms['blocked'])), $ms);
            if (!$okMs && empty($ms['blocked']) && !empty($ms['failed'])) {
                return $this->abortHard($run, $attention, 'ms2_ms3_data');
            }
            if ($hardMs && empty($ms['blocked'])) {
                $run['hard_blocked'] = true;
                $this->saveRun($run);
            }
            if (!empty($attention['has_ms2'])) {
                $attention['needs_attention'][] = array(
                    'id' => 'next_phase3_ms3',
                    'message' => $this->lex(
                        'msg_next_phase3_ms3',
                        'Next (after core): install MiniShop3 on MODX 3, then Phase 3 Apply ms2→ms3 data. Uninstall miniShop2 after verify.'
                    ),
                    'severity' => 'soft',
                );
            }
        } catch (Exception $e) {
            $this->finishStep($run, $step, false, array('error' => $e->getMessage()));
            $attention['failed'][] = array('id' => 'ms2_ms3_data', 'message' => $e->getMessage());
            return $this->abortHard($run, $attention, 'ms2_ms3_data');
        } catch (Throwable $e) {
            $this->finishStep($run, $step, false, array('error' => $e->getMessage()));
            $attention['failed'][] = array('id' => 'ms2_ms3_data', 'message' => $e->getMessage());
            return $this->abortHard($run, $attention, 'ms2_ms3_data');
        }

        // 7. Schema notes (MODX 2↔3) + re-audit + Attention Report
        $step = $this->beginStep($run, 're_audit_report', 'Re-audit + Attention Report');
        try {
            $attention['schema_notes'] = $this->loadSchemaNotes();
            if (!empty($attention['schema_notes']['advice'])) {
                $attention['needs_attention'][] = array(
                    'id' => 'db_refresh_advice',
                    'bucket' => 'known',
                    'message' => $attention['schema_notes']['advice'],
                    'severity' => 'soft',
                );
            }
            $report2 = $this->app->publicFullAudit();
            $attention['gate'] = isset($report2['gate']) ? $report2['gate'] : null;
            $attention = $this->finalizeAttention($attention, 'modx3');
            $mdPath = $this->exportAttentionMarkdown($attention);
            $upgradeReady = !empty($report2['gate']['upgrade_ready']) && empty($attention['failed']) && empty($run['hard_blocked']);
            $run['upgrade_ready'] = $upgradeReady;
            $this->finishStep($run, $step, true, array(
                'upgrade_ready' => $upgradeReady,
                'md' => $mdPath,
            ));
            $attention['done'][] = array(
                'id' => 'attention_report',
                'bucket' => 'known',
                'message' => $this->lex('msg_attention_written', 'Attention Report written'),
                'path' => $this->attentionFile,
                'md' => $mdPath,
            );
            $attention = $this->finalizeAttention($attention, 'modx3');
        } catch (Exception $e) {
            $this->finishStep($run, $step, false, array('error' => $e->getMessage()));
            $attention['failed'][] = array('id' => 're_audit', 'message' => $e->getMessage());
            return $this->abortHard($run, $attention, 're_audit_report');
        } catch (Throwable $e) {
            $this->finishStep($run, $step, false, array('error' => $e->getMessage()));
            $attention['failed'][] = array('id' => 're_audit', 'message' => $e->getMessage());
            return $this->abortHard($run, $attention, 're_audit_report');
        }

        $run['status'] = !empty($attention['failed']) || !empty($run['hard_blocked']) ? 'completed_with_issues' : 'completed';
        $run['finished_at'] = date('c');
        $run['has_ms2'] = !empty($attention['has_ms2']);
        $run['goal'] = 'modx3';
        $this->saveRun($run);

        $guide = $this->buildGuide($run, $attention, isset($report2) ? $report2 : null);

        return array(
            'ok' => empty($attention['failed']) && empty($run['hard_blocked']),
            'run' => $run,
            'attention' => $attention,
            'upgrade_ready' => !empty($run['upgrade_ready']),
            'report' => isset($report2) ? $report2 : null,
            'recovery' => $recoveryOut,
            'goal' => 'modx3',
            'phase' => $guide['phase'],
            'next_actions' => $guide['next_actions'],
            'has_ms2' => $guide['has_ms2'],
            'modx_major' => $guide['modx_major'],
        );
    }

    /**
     * Persist selected goal: php_on_2 | modx3
     */
    public function setGoal($goal)
    {
        $goal = $goal === 'php_on_2' ? 'php_on_2' : 'modx3';
        $run = $this->loadRun();
        if (!is_array($run)) {
            $run = array('started_at' => date('c'), 'steps' => array());
        }
        $run['goal'] = $goal;
        $this->saveRun($run);
        $guide = $this->buildGuide($run, $this->loadAttention(), null);
        return array(
            'ok' => true,
            'goal' => $goal,
            'run' => $run,
            'phase' => $guide['phase'],
            'next_actions' => $guide['next_actions'],
            'has_ms2' => $guide['has_ms2'],
            'modx_major' => $guide['modx_major'],
            'ms3_installed' => $guide['ms3_installed'],
            'attention' => $this->loadAttention(),
        );
    }

    /**
     * Track A: stay on MODX 2, prepare PHP 8.x (keepers autofix + remaining report).
     */
    public function preparePhp(array $opts = array())
    {
        $run = array(
            'started_at' => date('c'),
            'finished_at' => null,
            'status' => 'running',
            'steps' => array(),
            'goal' => 'php_on_2',
            'phase' => 1,
            'upgrade_ready' => false,
            'hard_blocked' => false,
            'opts' => array('skip_snapshot' => !empty($opts['skip_snapshot'])),
        );
        $this->saveRun($run);

        $attention = array(
            'generated_at' => date('c'),
            'goal' => 'php_on_2',
            'done' => array(),
            'failed' => array(),
            'needs_attention' => array(),
            'known' => array(),
            'unknown' => array(),
            'packages' => array(),
            'ms3' => array(),
            'php' => array(),
            'gate' => null,
            'agent_export' => '',
        );

        $recoveryOut = null;
        $step = $this->beginStep($run, 'snapshot_recovery', 'Snapshot + Recovery');
        try {
            if (empty($opts['skip_snapshot'])) {
                $snap = $this->app->publicSnapshotCreate('php-prepare');
                $this->finishStep($run, $step, !empty($snap['ok']), $snap);
                if (empty($snap['ok'])) {
                    $attention['failed'][] = array(
                        'id' => 'snapshot',
                        'message' => isset($snap['error']) ? $snap['error'] : $this->lex('msg_snapshot_failed', 'Snapshot failed'),
                    );
                    return $this->abortHard($run, $attention, 'snapshot_recovery');
                }
                if (!empty($snap['recovery']) && is_array($snap['recovery'])) {
                    $recoveryOut = $snap['recovery'];
                }
                $attention['done'][] = array(
                    'id' => 'snapshot',
                    'bucket' => 'known',
                    'message' => $this->lex('msg_snapshot_created', 'Snapshot created', array('id' => '')),
                );
                if ($recoveryOut) {
                    $attention['needs_attention'][] = array(
                        'id' => 'recovery_credentials',
                        'message' => $this->lex(
                            'msg_recovery_save',
                            'Save Recovery URL and password now — shown once after activate.'
                        ),
                        'recovery' => array(
                            'url' => isset($recoveryOut['url']) ? $recoveryOut['url'] : '',
                            'shortcut_url' => isset($recoveryOut['shortcut_url']) ? $recoveryOut['shortcut_url'] : '',
                            'shortcut' => isset($recoveryOut['shortcut']) ? $recoveryOut['shortcut'] : 'migratemodx-recovery.php',
                        ),
                    );
                }
            } else {
                $this->finishStep($run, $step, true, array('skipped' => true));
            }
        } catch (Exception $e) {
            $this->finishStep($run, $step, false, array('error' => $e->getMessage()));
            $attention['failed'][] = array('id' => 'snapshot', 'message' => $e->getMessage());
            return $this->abortHard($run, $attention, 'snapshot_recovery');
        } catch (Throwable $e) {
            $this->finishStep($run, $step, false, array('error' => $e->getMessage()));
            $attention['failed'][] = array('id' => 'snapshot', 'message' => $e->getMessage());
            return $this->abortHard($run, $attention, 'snapshot_recovery');
        }

        $step = $this->beginStep($run, 'full_audit', 'Full audit');
        try {
            $report = $this->app->publicFullAudit();
            $this->finishStep($run, $step, true, array('score' => isset($report['gate']['score']) ? $report['gate']['score'] : null));
            $attention['done'][] = array(
                'id' => 'audit',
                'bucket' => 'known',
                'message' => $this->lex('msg_audit_done', 'Full audit completed'),
            );
            $attention['gate'] = isset($report['gate']) ? $report['gate'] : null;
        } catch (Exception $e) {
            $this->finishStep($run, $step, false, array('error' => $e->getMessage()));
            $attention['failed'][] = array('id' => 'audit', 'message' => $e->getMessage());
            return $this->abortHard($run, $attention, 'full_audit');
        } catch (Throwable $e) {
            $this->finishStep($run, $step, false, array('error' => $e->getMessage()));
            $attention['failed'][] = array('id' => 'audit', 'message' => $e->getMessage());
            return $this->abortHard($run, $attention, 'full_audit');
        }

        $step = $this->beginStep($run, 'php_keepers', 'PHP autofix (keepers)');
        try {
            $vendor = isset($report['vendor_packages']) ? $report['vendor_packages'] : array();
            $phpResult = $this->app->publicPhpApply('all', array(
                'mode' => 'keepers',
                'vendor_packages' => $vendor,
            ));
            $applied = isset($phpResult['applied']) ? (int)$phpResult['applied'] : 0;
            $this->finishStep($run, $step, true, $phpResult);
            $attention['done'][] = array(
                'id' => 'php_keepers',
                'bucket' => 'known',
                'message' => $this->lex(
                    'msg_php_keepers',
                    'PHP keepers autofix applied: ' . $applied,
                    array('n' => $applied)
                ),
            );
        } catch (Exception $e) {
            $this->finishStep($run, $step, false, array('error' => $e->getMessage()));
            $attention['failed'][] = array('id' => 'php_keepers', 'message' => $e->getMessage());
            return $this->abortHard($run, $attention, 'php_keepers');
        } catch (Throwable $e) {
            $this->finishStep($run, $step, false, array('error' => $e->getMessage()));
            $attention['failed'][] = array('id' => 'php_keepers', 'message' => $e->getMessage());
            return $this->abortHard($run, $attention, 'php_keepers');
        }

        $step = $this->beginStep($run, 'php_rescan', 'PHP remaining scan');
        try {
            $report2 = $this->app->publicFullAudit();
            $phpRows = isset($report2['php']) && is_array($report2['php']) ? $report2['php'] : array();
            $remaining = 0;
            foreach ($phpRows as $row) {
                $remaining++;
                $bucket = !empty($row['autofixable']) ? 'known' : 'unknown';
                $attention['needs_attention'][] = array(
                    'id' => 'php_' . (isset($row['uid']) ? $row['uid'] : $remaining),
                    'bucket' => $bucket === 'known' ? 'known' : 'needs_agent_review',
                    'message' => (isset($row['name']) ? $row['name'] : 'php') . ': '
                        . (isset($row['rule']) ? $row['rule'] : (isset($row['message']) ? $row['message'] : 'issue')),
                    'severity' => 'soft',
                );
            }
            $phpReady = !empty($report2['gate']['php_ready']) || $remaining === 0;
            $attention['php'] = array(
                'remaining' => $remaining,
                'php_ready' => $phpReady,
                'applied' => isset($applied) ? $applied : 0,
            );
            $attention['gate'] = isset($report2['gate']) ? $report2['gate'] : null;
            $attention['done'][] = array(
                'id' => 'php_rescan',
                'bucket' => 'known',
                'message' => $this->lex(
                    'msg_php_rescan',
                    'PHP remaining findings: ' . $remaining . '; php_ready=' . ($phpReady ? 'yes' : 'no'),
                    array(
                        'n' => $remaining,
                        'ready' => $phpReady
                            ? $this->lex('msg_yes', 'yes')
                            : $this->lex('msg_no', 'no'),
                    )
                ),
            );
            $run['php_ready'] = $phpReady;
            $this->finishStep($run, $step, true, $attention['php']);
            $attention = $this->finalizeAttention($attention, 'php_on_2');
        } catch (Exception $e) {
            $this->finishStep($run, $step, false, array('error' => $e->getMessage()));
            $attention['failed'][] = array('id' => 'php_rescan', 'message' => $e->getMessage());
            return $this->abortHard($run, $attention, 'php_rescan');
        } catch (Throwable $e) {
            $this->finishStep($run, $step, false, array('error' => $e->getMessage()));
            $attention['failed'][] = array('id' => 'php_rescan', 'message' => $e->getMessage());
            return $this->abortHard($run, $attention, 'php_rescan');
        }

        $run['status'] = !empty($attention['failed']) ? 'completed_with_issues' : 'completed';
        $run['finished_at'] = date('c');
        $run['goal'] = 'php_on_2';
        $this->saveRun($run);
        $guide = $this->buildGuide($run, $attention, isset($report2) ? $report2 : null);

        return array(
            'ok' => empty($attention['failed']),
            'run' => $run,
            'attention' => $attention,
            'report' => isset($report2) ? $report2 : null,
            'recovery' => $recoveryOut,
            'goal' => 'php_on_2',
            'php_ready' => !empty($run['php_ready']),
            'phase' => $guide['phase'],
            'next_actions' => $guide['next_actions'],
            'has_ms2' => $guide['has_ms2'],
            'modx_major' => $guide['modx_major'],
        );
    }

    public function status()
    {
        $postRollback = $this->reconcileAfterRollback();
        $run = $this->loadRun();
        $attention = $this->localizeAttentionForUi($this->loadAttention());
        $guide = $this->buildGuide($run, $attention, null);
        $report = null;
        $prePath = $this->storePath . 'last-audit.json';
        if (is_file($prePath)) {
            $decoded = json_decode((string)file_get_contents($prePath), true);
            if (is_array($decoded)) {
                $report = $decoded;
            }
        }
        /* Keep cached audit for UI, but never let stale env.version hide a live MODX 3 (after /setup/). */
        $liveMajor = $guide['modx_major'];
        if (is_array($report)) {
            if (!isset($report['environment']) || !is_array($report['environment'])) {
                $report['environment'] = array();
            }
            if ($liveMajor >= 3 && empty($guide['setup_pending'])) {
                $cur = isset($report['environment']['modx_version'])
                    ? (string)$report['environment']['modx_version']
                    : '';
                if ($cur === '' || preg_match('/^2\b/', $cur)) {
                    try {
                        $vd = $this->modx->getVersionData();
                        $report['environment']['modx_version'] = !empty($vd['full_version'])
                            ? $vd['full_version']
                            : ('3.x (live major ' . $liveMajor . ')');
                    } catch (Exception $e) {
                        $report['environment']['modx_version'] = '3.x';
                    } catch (Throwable $e) {
                        $report['environment']['modx_version'] = '3.x';
                    }
                    $report['environment']['modx_major_live'] = $liveMajor;
                }
            }
        }
        $recovery = $this->app->publicRecoveryStatus();
        return array(
            'ok' => true,
            'run' => $run,
            'attention' => $attention,
            'report' => $report,
            'recovery' => $recovery,
            'goal' => $guide['goal'],
            'phase' => $guide['phase'],
            'next_actions' => $guide['next_actions'],
            'has_ms2' => $guide['has_ms2'],
            'modx_major' => $guide['modx_major'],
            'modx_file_major' => isset($guide['modx_file_major']) ? $guide['modx_file_major'] : $guide['modx_major'],
            'modx_db_major' => isset($guide['modx_db_major']) ? $guide['modx_db_major'] : 0,
            'setup_pending' => !empty($guide['setup_pending']),
            'ms3_installed' => $guide['ms3_installed'],
            'php_ready' => is_array($run) && !empty($run['php_ready']),
            'post_rollback' => $postRollback,
        );
    }

    /**
     * After Recovery/manager restore: clear stale phase markers and run audit once.
     *
     * @return array|null
     */
    protected function reconcileAfterRollback()
    {
        $out = array('reset' => false, 'audit' => null);
        $run = $this->loadRun();
        $liveMajor = $this->modxMajor();
        if (MmxPostRollback::looksStaleAfterRollback($run, $liveMajor)) {
            MmxPostRollback::resetMigrateProgress(
                $this->storePath,
                '',
                'stale_modx2_after_modx3_run'
            );
            $out['reset'] = true;
            $out['reason'] = 'stale_modx2_after_modx3_run';
        }

        $flag = MmxPostRollback::readFlag($this->storePath);
        if (!is_array($flag) || empty($flag['pending_audit'])) {
            return $out['reset'] ? $out : ($flag ? array_merge($out, array('flag' => $flag)) : null);
        }

        try {
            $report = $this->app->publicFullAudit();
            MmxPostRollback::updateFlag($this->storePath, array(
                'pending_audit' => false,
                'audit_ok' => true,
                'audit_at' => date('c'),
                'audit_via' => 'migrate_status',
            ));
            $out['audit'] = array('ok' => true);
            $out['flag'] = MmxPostRollback::readFlag($this->storePath);
            if (is_array($run = $this->loadRun())) {
                $run['status'] = 'audited_after_rollback';
                $run['last_audit_at'] = date('c');
                $this->saveRun($run);
            }
            return $out;
        } catch (Exception $e) {
            $out['audit'] = array('ok' => false, 'error' => $e->getMessage());
            return $out;
        } catch (Throwable $e) {
            $out['audit'] = array('ok' => false, 'error' => $e->getMessage());
            return $out;
        }
    }

    /**
     * Refresh schema notes language for already-saved Attention without re-running prepare.
     *
     * @param array|null $attention
     * @return array|null
     */
    protected function localizeAttentionForUi($attention)
    {
        if (!is_array($attention)) {
            return $attention;
        }
        $notes = $this->loadSchemaNotes();
        if (!empty($notes['advice']) || !empty($notes['notes'])) {
            $attention['schema_notes'] = $notes;
        }
        foreach (array('needs_attention', 'known', 'unknown', 'done') as $bucket) {
            if (empty($attention[$bucket]) || !is_array($attention[$bucket])) {
                continue;
            }
            foreach ($attention[$bucket] as &$item) {
                if (!is_array($item) || empty($item['id'])) {
                    continue;
                }
                if ($item['id'] === 'db_refresh_advice' && !empty($notes['advice'])) {
                    $item['message'] = $notes['advice'];
                }
                if (strpos($item['id'], 'schema_') === 0 && !empty($notes['notes'])) {
                    $nid = substr($item['id'], 7);
                    foreach ($notes['notes'] as $n) {
                        if (isset($n['id']) && $n['id'] === $nid && !empty($n['message'])) {
                            $item['message'] = $n['message'];
                            break;
                        }
                    }
                }
            }
            unset($item);
        }
        return $attention;
    }

    /**
     * Phase 3: apply ms2→ms3 data. Requires MODX 3+ and confirm_ms3.
     */
    public function applyMs3Data(array $opts = array())
    {
        if (!$this->isModx3Live()) {
            return array(
                'ok' => false,
                'error' => $this->lex(
                    'msg_err_ms3_need_modx3',
                    'Apply ms2→ms3 data only after MODX 3. Complete Phase 2 (core + /setup/) first.'
                ),
                'phase' => 2,
            );
        }
        if (empty($opts['confirm_ms3'])) {
            return array(
                'ok' => false,
                'error' => $this->lex(
                    'msg_err_confirm_ms3',
                    'confirm_ms3 required: install MiniShop3 in Package Management, then confirm.'
                ),
            );
        }
        $migrator = new MmxMs2Migrator(
            $this->modx,
            $this->corePath . 'data/rewrites',
            $this->storePath
        );
        /* Keep pages: convert class_key to MS3 BEFORE copying rows / retiring MS2. */
        $convert = $migrator->convertCatalogClassKeys();
        $ms = $migrator->run(true, true);
        $ensure = $migrator->ensureMs3ProductData();
        $att = $this->loadAttention();
        if (!is_array($att)) {
            $att = array(
                'done' => array(),
                'failed' => array(),
                'needs_attention' => array(),
                'packages' => array(),
                'ms3' => array(),
            );
        }
        $att['generated_at'] = date('c');
        $att['ms3'] = isset($ms['ms3']) ? $ms['ms3'] : array();
        $att['done'] = array_merge($att['done'], isset($ms['done']) ? $ms['done'] : array());
        $att['needs_attention'] = array_merge(
            $att['needs_attention'],
            isset($ms['needs_attention']) ? $ms['needs_attention'] : array()
        );
        if (!empty($ms['failed'])) {
            $att['failed'] = array_merge($att['failed'], $ms['failed']);
        }
        if (!empty($convert['ok'])) {
            $att['done'][] = array(
                'id' => 'ms3_class_keys',
                'bucket' => 'known',
                'message' => $this->lex(
                    'msg_ms3_class_keys',
                    'Catalog class_key converted to MiniShop3\\Model\\msProduct|msCategory (pages kept).'
                ),
                'detail' => $convert,
            );
        }
        if (!empty($ensure['created'])) {
            $att['done'][] = array(
                'id' => 'ms3_ensure_data',
                'message' => $this->lex(
                    'msg_ms3_ensure_data',
                    'Created/filled ms3_products for ' . (int)$ensure['created'] . ' product resources',
                    array('n' => (int)$ensure['created'])
                ),
            );
        }
        $retire = null;
        $front = null;
        if (!empty($ms['ok']) && empty($opts['skip_retire_ms2'])) {
            $retire = $this->retireMiniShop2Internal($migrator);
            if (!empty($retire['ok'])) {
                $att['done'][] = array(
                    'id' => 'ms2_retired',
                    'bucket' => 'known',
                    'message' => $this->lex(
                        'msg_ms2_retired',
                        'miniShop2 retired: class_key→MiniShop3\\Model\\*, folders quarantined, cache cleared. Front should no longer load MS2 modprocessor.'
                    ),
                    'detail' => $retire,
                );
            } else {
                $att['needs_attention'][] = array(
                    'id' => 'ms2_retire_failed',
                    'bucket' => 'known',
                    'severity' => 'hard',
                    'message' => $this->lex(
                        'msg_ms2_retire_failed',
                        'Could not fully retire miniShop2 — front may Fatal on modprocessor.class.php. Use Phase 3 «Retire miniShop2» or FTP-rename core/assets/components/minishop2.'
                    ),
                    'detail' => $retire,
                );
            }
            $att['needs_attention'][] = array(
                'id' => 'ms3_cleanup',
                'message' => $this->lex(
                    'msg_ms3_cleanup',
                    'Verify catalog/orders, rebuild ms3 chunks/theme. Never uninstall miniShop2 via Package Management — use MigrateMODX Retire only. Then remove the PM package entry when ready.'
                ),
                'severity' => 'soft',
            );
            $att['needs_attention'][] = array(
                'id' => 'ms2_never_pm_uninstall',
                'bucket' => 'known',
                'severity' => 'hard',
                'message' => $this->lex(
                    'msg_ms2_never_pm_uninstall',
                    'Do not uninstall miniShop2 via Package Management — it can break or hide catalog resources. MigrateMODX Retire quarantines folders after class_key convert.'
                ),
            );
        }
        if (!empty($ms['ok']) && empty($opts['skip_front_compat'])) {
            $cleanup = $this->cleanupBrokenExtrasInternal(true);
            $att['done'][] = array(
                'id' => 'broken_extras_cleanup',
                'bucket' => 'known',
                'message' => $this->lex(
                    'msg_broken_extras_cleanup',
                    'Broken extras cleanup after MS3: quarantined '
                    . count((array)$cleanup['quarantined'])
                    . ', disabled plugins '
                    . count((array)$cleanup['disabled_plugins'])
                    . '.',
                    array(
                        'q' => count((array)$cleanup['quarantined']),
                        'p' => count((array)$cleanup['disabled_plugins']),
                    )
                ),
                'detail' => $cleanup,
            );
            $front = $this->applyMs3FrontCompatInternal(true);
            $this->mergeFrontCompatIntoAttention($att, $front);
        }
        $att['agent_export'] = $this->buildAgentExport($att);
        $this->saveAttention($att);
        $this->exportAttentionMarkdown($att);

        $run = $this->loadRun();
        if (!is_array($run)) {
            $run = array();
        }
        $run['ms3_data_applied_at'] = date('c');
        $run['ms3_data_ok'] = !empty($ms['ok']);
        if ($retire && !empty($retire['ok'])) {
            $run['ms2_retired_at'] = date('c');
        }
        if ($front && !empty($front['ok'])) {
            $run['ms3_front_healed_at'] = date('c');
            $run['ms3_front_changes'] = isset($front['changes']) ? (int)$front['changes'] : 0;
        }
        $this->saveRun($run);

        $guide = $this->buildGuide($run, $att, null);
        $error = null;
        if (empty($ms['ok'])) {
            if (!empty($ms['error'])) {
                $error = $ms['error'];
            } elseif (!empty($ms['failed'][0]['message'])) {
                $error = $ms['failed'][0]['message'];
                if (count($ms['failed']) > 1 && !empty($ms['failed'][1]['message'])) {
                    $error .= ' | ' . $ms['failed'][1]['message'];
                }
            } elseif (!empty($ms['blocked'])) {
                $error = (string)$ms['blocked'];
            } else {
                $error = $this->lex('msg_ms3_apply_failed', 'ms2→ms3 data apply failed — see Attention Report and migratemodx-data/ms3-migrate-*.json');
            }
            if (!empty($ms['report_file'])) {
                $error .= ' [' . basename((string)$ms['report_file']) . ']';
            }
        }
        return array(
            'ok' => !empty($ms['ok']),
            'ms3' => isset($ms['ms3']) ? $ms['ms3'] : array(),
            'catalog_convert' => $convert,
            'catalog_ensure' => $ensure,
            'catalog_inventory' => $migrator->inventoryCatalog(),
            'retire_ms2' => $retire,
            'front_compat' => $front,
            'attention' => $att,
            'run' => $run,
            'failed' => isset($ms['failed']) ? $ms['failed'] : array(),
            'report_file' => isset($ms['report_file']) ? $ms['report_file'] : null,
            'error' => $error,
            'phase' => $guide['phase'],
            'next_actions' => $guide['next_actions'],
            'modx_major' => $guide['modx_major'],
            'has_ms2' => $guide['has_ms2'],
            'ms3_installed' => $guide['ms3_installed'],
        );
    }

    /**
     * Repair catalog after PM uninstall / missing class_keys / empty ms3_products.
     */
    public function repairCatalog(array $opts = array())
    {
        if (!$this->isModx3Live()) {
            return array(
                'ok' => false,
                'error' => $this->lex(
                    'msg_err_ms3_need_modx3',
                    'Repair catalog only on MODX 3 with MiniShop3 installed.'
                ),
            );
        }
        $migrator = new MmxMs2Migrator(
            $this->modx,
            $this->corePath . 'data/rewrites',
            $this->storePath
        );
        $repair = $this->repairCatalogInternal($migrator);
        $front = $this->applyMs3FrontCompatInternal(true);
        $att = $this->loadAttention();
        if (!is_array($att)) {
            $att = array('done' => array(), 'failed' => array(), 'needs_attention' => array());
        }
        $att['generated_at'] = date('c');
        if (!empty($repair['ok'])) {
            $att['done'][] = array(
                'id' => 'ms3_catalog_repaired',
                'message' => $this->lex(
                    'msg_ms3_catalog_repaired',
                    'Catalog repaired: class_keys + ms3_products synced, cache cleared.'
                ),
                'detail' => $repair,
            );
        } else {
            $att['failed'][] = array(
                'id' => 'ms3_catalog_repair_failed',
                'message' => $this->lex(
                    'msg_ms3_catalog_repair_failed',
                    'Catalog repair incomplete — see inventory/advice (may need snapshot restore).'
                ),
                'detail' => $repair,
            );
            foreach ((array)$repair['advice'] as $advice) {
                $att['needs_attention'][] = array(
                    'id' => 'ms3_catalog_advice',
                    'severity' => 'hard',
                    'message' => $advice,
                );
            }
        }
        $this->mergeFrontCompatIntoAttention($att, $front);
        $att['agent_export'] = $this->buildAgentExport($att);
        $this->saveAttention($att);
        $this->exportAttentionMarkdown($att);
        $run = $this->loadRun();
        if (!is_array($run)) {
            $run = array();
        }
        $run['catalog_repaired_at'] = date('c');
        $run['catalog_repair_ok'] = !empty($repair['ok']);
        if (!empty($front['ok'])) {
            $run['ms3_front_healed_at'] = date('c');
            $run['ms3_front_changes'] = isset($front['changes']) ? (int)$front['changes'] : 0;
        }
        $this->saveRun($run);
        $guide = $this->buildGuide($run, $att, null);
        return array(
            'ok' => !empty($repair['ok']),
            'repair' => $repair,
            'front_compat' => $front,
            'attention' => $att,
            'run' => $run,
            'error' => empty($repair['ok'])
                ? $this->lex('msg_ms3_catalog_repair_failed', 'Catalog repair incomplete — see inventory/advice.')
                : null,
            'phase' => $guide['phase'],
            'next_actions' => $guide['next_actions'],
            'modx_major' => $guide['modx_major'],
            'has_ms2' => $guide['has_ms2'],
            'ms3_installed' => $guide['ms3_installed'],
        );
    }

    /**
     * @param MmxMs2Migrator $migrator
     * @return array
     */
    protected function repairCatalogInternal(MmxMs2Migrator $migrator)
    {
        if (defined('MODX_BASE_PATH')) {
            $base = MODX_BASE_PATH;
        } else {
            $cp = rtrim(str_replace('\\', '/', $this->corePath), '/');
            $base = dirname(dirname(dirname($cp))) . '/';
        }
        return $migrator->repairCatalog($base, $this->corePath . 'data');
    }

    /**
     * Phase 3: heal theme front after MS3 (counts, thumbs, minicart, phpThumbOn, class_key queries).
     */
    public function healFront(array $opts = array())
    {
        if (!$this->isModx3Live()) {
            return array(
                'ok' => false,
                'error' => $this->lex(
                    'msg_err_ms3_need_modx3',
                    'Heal front only on MODX 3 after MiniShop3 is installed.'
                ),
            );
        }
        $apply = !isset($opts['apply']) || !empty($opts['apply']);
        /* First: quarantine broken extras / disable MS2 map plugins — otherwise catalog stays empty */
        $cleanup = $this->cleanupBrokenExtrasInternal($apply);
        $front = $this->applyMs3FrontCompatInternal($apply);
        /* Catalog may have been soft-deleted by PM uninstall of miniShop2 — repair class_keys + data */
        $repair = null;
        if ($apply && $this->packageInstalled('minishop3')) {
            try {
                $migrator = new MmxMs2Migrator(
                    $this->modx,
                    $this->corePath . 'data/rewrites',
                    $this->storePath
                );
                $repair = $this->repairCatalogInternal($migrator);
            } catch (Exception $e) {
                $repair = array('ok' => false, 'error' => $e->getMessage());
            } catch (Throwable $e) {
                $repair = array('ok' => false, 'error' => $e->getMessage());
            }
        }
        $att = $this->loadAttention();
        if (!is_array($att)) {
            $att = array('done' => array(), 'failed' => array(), 'needs_attention' => array());
        }
        $att['generated_at'] = date('c');
        $att['done'][] = array(
            'id' => 'broken_extras_cleanup',
            'bucket' => 'known',
            'message' => $this->lex(
                'msg_broken_extras_cleanup',
                'Broken extras cleanup: quarantined '
                . count((array)(isset($cleanup['quarantined']) ? $cleanup['quarantined'] : array()))
                . ', disabled plugins '
                . count((array)(isset($cleanup['disabled_plugins']) ? $cleanup['disabled_plugins'] : array()))
                . '.',
                array(
                    'q' => count((array)(isset($cleanup['quarantined']) ? $cleanup['quarantined'] : array())),
                    'p' => count((array)(isset($cleanup['disabled_plugins']) ? $cleanup['disabled_plugins'] : array())),
                )
            ),
            'detail' => $cleanup,
        );
        if (is_array($repair)) {
            $att['done'][] = array(
                'id' => 'heal_catalog_repair',
                'message' => !empty($repair['ok'])
                    ? $this->lex('msg_ms3_catalog_repaired', 'Catalog repaired during Heal front.')
                    : $this->lex('msg_ms3_catalog_repair_failed', 'Catalog repair during Heal front incomplete.'),
                'detail' => $repair,
            );
        }
        $this->mergeFrontCompatIntoAttention($att, $front);
        $att['agent_export'] = $this->buildAgentExport($att);
        $this->saveAttention($att);
        $this->exportAttentionMarkdown($att);
        $run = $this->loadRun();
        if (!is_array($run)) {
            $run = array();
        }
        if ($apply && !empty($front['ok'])) {
            $run['ms3_front_healed_at'] = date('c');
            $run['ms3_front_changes'] = isset($front['changes']) ? (int)$front['changes'] : 0;
        }
        $this->saveRun($run);
        $guide = $this->buildGuide($run, $att, null);
        return array(
            'ok' => !empty($front['ok']),
            'front_compat' => $front,
            'broken_extras' => $cleanup,
            'repair' => $repair,
            'attention' => $att,
            'run' => $run,
            'error' => empty($front['ok'])
                ? $this->lex('msg_ms3_front_heal_failed', 'Front heal incomplete — see Attention / failed items.')
                : null,
            'phase' => $guide['phase'],
            'next_actions' => $guide['next_actions'],
            'modx_major' => $guide['modx_major'],
            'has_ms2' => $guide['has_ms2'],
            'ms3_installed' => $guide['ms3_installed'],
        );
    }

    /**
     * @param bool $apply
     * @return array
     */
    protected function cleanupBrokenExtrasInternal($apply = true)
    {
        if (defined('MODX_BASE_PATH')) {
            $base = MODX_BASE_PATH;
        } else {
            $cp = rtrim(str_replace('\\', '/', $this->corePath), '/');
            $base = dirname(dirname(dirname($cp))) . '/';
        }
        $vc = new MmxVendorCompat($this->modx, $base, $this->storePath, $this->corePath . 'data');
        return $vc->cleanupBrokenExtras($apply);
    }

    /**
     * @param bool $apply
     * @return array
     */
    protected function applyMs3FrontCompatInternal($apply = true)
    {
        if (defined('MODX_BASE_PATH')) {
            $base = MODX_BASE_PATH;
        } else {
            $cp = rtrim(str_replace('\\', '/', $this->corePath), '/');
            $base = dirname(dirname(dirname($cp))) . '/';
        }
        $compat = new MmxMs3FrontCompat($this->modx, $this->storePath, $base);
        return $compat->run($apply);
    }

    /**
     * @param array $att
     * @param array $front
     */
    protected function mergeFrontCompatIntoAttention(array &$att, array $front)
    {
        if (!isset($att['done']) || !is_array($att['done'])) {
            $att['done'] = array();
        }
        if (!isset($att['failed']) || !is_array($att['failed'])) {
            $att['failed'] = array();
        }
        if (!isset($att['needs_attention']) || !is_array($att['needs_attention'])) {
            $att['needs_attention'] = array();
        }
        $n = isset($front['changes']) ? (int)$front['changes'] : 0;
        $att['done'][] = array(
            'id' => 'ms3_front_compat',
            'bucket' => 'known',
            'message' => $this->lex(
                'msg_ms3_front_compat',
                'MS3 front heal: class_key/queries in elements, shop snippet remap, minicart bridge, listing thumbs, phpThumbOn ('
                . $n . ' change(s)).',
                array('n' => $n)
            ),
            'detail' => array(
                'changes' => $n,
                'done_count' => isset($front['done']) ? count((array)$front['done']) : 0,
                'applied' => !empty($front['applied']),
            ),
        );
        foreach ((array)(isset($front['done']) ? $front['done'] : array()) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $att['done'][] = array_merge(array('bucket' => 'front'), $row);
        }
        foreach ((array)(isset($front['failed']) ? $front['failed'] : array()) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $att['failed'][] = array_merge(array('bucket' => 'front'), $row);
        }
        foreach ((array)(isset($front['needs_attention']) ? $front['needs_attention'] : array()) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $att['needs_attention'][] = array_merge(array('bucket' => 'front'), $row);
        }
    }

    /**
     * Standalone Phase 3 action: retire miniShop2 after data is on MiniShop3.
     */
    public function retireMs2(array $opts = array())
    {
        if (!$this->isModx3Live()) {
            return array(
                'ok' => false,
                'error' => $this->lex(
                    'msg_err_ms3_need_modx3',
                    'Retire miniShop2 only on MODX 3 after MiniShop3 is installed.'
                ),
            );
        }
        if (!$this->packageInstalled('minishop3')) {
            return array(
                'ok' => false,
                'error' => $this->lex('msg_ms3_not_installed', 'MiniShop3 not installed'),
            );
        }
        $migrator = new MmxMs2Migrator(
            $this->modx,
            $this->corePath . 'data/rewrites',
            $this->storePath
        );
        $retire = $this->retireMiniShop2Internal($migrator);
        $att = $this->loadAttention();
        if (!is_array($att)) {
            $att = array('done' => array(), 'failed' => array(), 'needs_attention' => array());
        }
        $att['generated_at'] = date('c');
        if (!empty($retire['ok'])) {
            $att['done'][] = array(
                'id' => 'ms2_retired',
                'message' => $this->lex(
                    'msg_ms2_retired',
                    'miniShop2 retired: class_key→MiniShop3\\Model\\*, folders quarantined, cache cleared.'
                ),
                'detail' => $retire,
            );
        } else {
            $att['failed'][] = array(
                'id' => 'ms2_retire_failed',
                'message' => $this->lex(
                    'msg_ms2_retire_failed',
                    'Could not fully retire miniShop2.'
                ),
                'detail' => $retire,
            );
        }
        $att['agent_export'] = $this->buildAgentExport($att);
        $this->saveAttention($att);
        $this->exportAttentionMarkdown($att);
        $run = $this->loadRun();
        if (!is_array($run)) {
            $run = array();
        }
        if (!empty($retire['ok'])) {
            $run['ms2_retired_at'] = date('c');
        }
        $this->saveRun($run);
        $guide = $this->buildGuide($run, $att, null);
        return array(
            'ok' => !empty($retire['ok']),
            'retire_ms2' => $retire,
            'attention' => $att,
            'run' => $run,
            'error' => empty($retire['ok'])
                ? $this->lex('msg_ms2_retire_failed', 'Could not fully retire miniShop2.')
                : null,
            'phase' => $guide['phase'],
            'next_actions' => $guide['next_actions'],
            'modx_major' => $guide['modx_major'],
            'has_ms2' => $guide['has_ms2'],
            'ms3_installed' => $guide['ms3_installed'],
        );
    }

    /**
     * @param MmxMs2Migrator $migrator
     * @return array
     */
    protected function retireMiniShop2Internal(MmxMs2Migrator $migrator)
    {
        if (defined('MODX_BASE_PATH')) {
            $base = MODX_BASE_PATH;
        } else {
            $cp = rtrim(str_replace('\\', '/', $this->corePath), '/');
            $base = dirname(dirname(dirname($cp))) . '/';
        }
        return $migrator->retireMiniShop2($base, $this->corePath . 'data');
    }

    /**
     * Compute current phase (1–3) and short next_actions for the UI guide.
     */
    public function buildGuide($run, $attention, $report = null)
    {
        $goal = 'modx3';
        if (is_array($run) && !empty($run['goal'])) {
            $goal = $run['goal'] === 'php_on_2' ? 'php_on_2' : 'modx3';
        } elseif (is_array($attention) && !empty($attention['goal'])) {
            $goal = $attention['goal'] === 'php_on_2' ? 'php_on_2' : 'modx3';
        }

        $fileMajor = $this->modxMajor();
        $dbMajor = $this->settingsMajor();
        /* Phase 3 / MiniShop3 only after /setup/ updates settings_version — file major alone is not enough. */
        $major = ($dbMajor >= 3) ? 3 : (($dbMajor > 0) ? 2 : $fileMajor);
        $setupPending = ($dbMajor > 0 && $dbMajor < 3 && ($fileMajor >= 3 || (is_array($run) && !empty($run['core_applied_at']))))
            || ($dbMajor === 0 && is_array($run) && !empty($run['core_applied_at']) && $fileMajor >= 3);
        $hasMs2 = $this->packageInstalled('minishop2');
        if (!$hasMs2 && is_array($run) && !empty($run['has_ms2'])) {
            $hasMs2 = true;
        }
        if (!$hasMs2 && is_array($attention) && !empty($attention['has_ms2'])) {
            $hasMs2 = true;
        }
        $ms3Installed = $this->packageInstalled('minishop3');
        $runGoal = is_array($run) && !empty($run['goal']) ? $run['goal'] : '';
        $prepareDone = is_array($run) && !empty($run['finished_at']) && $runGoal === $goal;
        $upgradeReady = is_array($run) && !empty($run['upgrade_ready']) && $runGoal === 'modx3';
        $failed = is_array($attention) && !empty($attention['failed']);
        $hard = is_array($run) && !empty($run['hard_blocked']);
        $coreApplied = is_array($run) && !empty($run['core_applied_at']);
        $filesOn3 = $fileMajor >= 3 || $coreApplied;
        $ms3DataOk = is_array($run) && !empty($run['ms3_data_ok']);
        $phpReady = is_array($run) && !empty($run['php_ready']) && $runGoal === 'php_on_2';

        if ($goal === 'php_on_2') {
            $next = array();
            if (!$prepareDone) {
                $next[] = array(
                    'id' => 'run_prepare_php',
                    'message' => $this->lex(
                        'msg_next_run_prepare_php',
                        'Run PHP track: snapshot, audit, keepers autofix, remaining deprecates report.'
                    ),
                );
            } elseif ($failed) {
                $next[] = array(
                    'id' => 'fix_php',
                    'message' => $this->lex('msg_next_fix_php', 'Fix failed PHP items, then re-run PHP prepare.'),
                );
            } elseif (!$phpReady) {
                $next[] = array(
                    'id' => 'manual_deprecates',
                    'message' => $this->lex(
                        'msg_next_manual_deprecates',
                        'Clear remaining PHP findings (see Attention known/unknown), raise host PHP when ready.'
                    ),
                );
            } else {
                $next[] = array(
                    'id' => 'php_done',
                    'message' => $this->lex(
                        'msg_next_php_done',
                        'PHP track clean enough — raise host PHP to 8.x when hosting allows. MODX 3 not required for this goal.'
                    ),
                );
            }
            return array(
                'goal' => $goal,
                'phase' => 1,
                'next_actions' => $next,
                'has_ms2' => $hasMs2,
                'ms3_installed' => $ms3Installed,
                'modx_major' => $major,
                'modx_file_major' => $fileMajor,
                'modx_db_major' => $dbMajor,
                'setup_pending' => false,
            );
        }

        $phase = 1;
        if ($dbMajor >= 3) {
            $phase = 3;
        } elseif ($setupPending || $coreApplied || ($prepareDone && $upgradeReady && !$failed && !$hard)) {
            $phase = 2;
        }

        $next = array();
        if ($phase === 1) {
            if (!$prepareDone) {
                $next[] = array(
                    'id' => 'run_prepare',
                    'message' => $this->lex(
                        'msg_next_run_prepare',
                        'Run Phase 1 Prepare (snapshot, audit, PHP, rewrites, package matrix, ms2 dry-run).'
                    ),
                );
            } elseif ($failed || $hard) {
                $next[] = array(
                    'id' => 'fix_attention',
                    'message' => $this->lex(
                        'msg_next_fix_attention',
                        'Fix Attention Report failed/hard items, then re-run Prepare.'
                    ),
                );
            } else {
                $next[] = array(
                    'id' => 'go_phase2',
                    'message' => $this->lex(
                        'msg_next_go_phase2',
                        'Phase 1 done — proceed to Phase 2 Apply core 3.0.3 (staging).'
                    ),
                );
            }
        } elseif ($phase === 2) {
            $needPhpGuide = '8.1';
            if (is_array($run) && !empty($run['php_for_setup'])) {
                $needPhpGuide = $run['php_for_setup'];
            } elseif (is_object($this->app) && method_exists($this->app, 'resolveTargets')) {
                $tv = $this->app->resolveTargets();
                if (!empty($tv['effective_php'])) {
                    $needPhpGuide = $tv['effective_php'];
                }
            }
            if (version_compare($needPhpGuide, '8.1', '<')) {
                $needPhpGuide = '8.1';
            }
            if (version_compare(PHP_VERSION, $needPhpGuide, '<')) {
                $next[] = array(
                    'id' => 'switch_php_before_setup',
                    'message' => $this->lex(
                        'msg_switch_php_before_setup',
                        'Switch host PHP to ≥'
                            . $needPhpGuide
                            . ' (now '
                            . PHP_VERSION
                            . ') before opening /setup/ — Composer platform_check will Fatal otherwise.',
                        array(
                            'need' => $needPhpGuide,
                            'php' => PHP_VERSION,
                            'version' => is_array($run) && !empty($run['core_version'])
                                ? (string)$run['core_version']
                                : '',
                        )
                    ),
                );
            }
            if (!$filesOn3) {
                $next[] = array(
                    'id' => 'apply_core',
                    'message' => $this->lex(
                        'msg_next_apply_core',
                        'Apply core on staging. If host PHP is still 7.4, switch to ≥'
                            . $needPhpGuide
                            . ' before /setup/.',
                        array('need' => $needPhpGuide)
                    ),
                );
            }
            if ($dbMajor < 3) {
                $next[] = array(
                    'id' => 'setup',
                    'message' => $this->lex(
                        'msg_next_setup',
                        'On PHP ≥'
                            . $needPhpGuide
                            . ': finish MODX /setup/ wizard before installing MiniShop3.',
                        array('need' => $needPhpGuide)
                    ),
                );
            } else {
                $next[] = array(
                    'id' => 'go_phase3',
                    'message' => $this->lex('msg_next_go_phase3', 'Core is MODX 3 — continue with Phase 3.'),
                );
            }
        }
        if ($phase === 3) {
            if ($hasMs2 && !$ms3Installed) {
                $next[] = array(
                    'id' => 'install_ms3',
                    'message' => $this->lex(
                        'msg_next_install_ms3',
                        'Phase 3 «Install/update packages» — downloads MiniShop3 (+ deps) and outdated MODX-3-ready extras from providers.'
                    ),
                );
            }
            if ($hasMs2 && $ms3Installed && !$ms3DataOk) {
                $next[] = array(
                    'id' => 'apply_ms3_data',
                    'message' => $this->lex(
                        'msg_next_apply_ms3_data',
                        'Confirm MiniShop3, then Apply ms2→ms3 data.'
                    ),
                );
            }
            if ($hasMs2 && $ms3DataOk) {
                $next[] = array(
                    'id' => 'cleanup_ms2',
                    'message' => $this->lex(
                        'msg_next_cleanup_ms2',
                        'Verify shop data, rebuild theme/chunks, uninstall miniShop2.'
                    ),
                );
            }
            if (!$hasMs2) {
                $next[] = array(
                    'id' => 'post_upgrade',
                    'message' => $this->lex(
                        'msg_next_post_upgrade',
                        'No miniShop2 — finish package updates, health check, deactivate Recovery.'
                    ),
                );
            }
        }

        return array(
            'goal' => $goal,
            'phase' => $phase,
            'next_actions' => $next,
            'has_ms2' => $hasMs2,
            'ms3_installed' => $ms3Installed,
            'modx_major' => $major,
            'modx_file_major' => $fileMajor,
            'modx_db_major' => $dbMajor,
            'setup_pending' => $setupPending || ($phase === 2 && $filesOn3 && $dbMajor < 3),
        );
    }

    /**
     * DB-reported MODX major from settings_version (stays 2.x until /setup/ finishes).
     *
     * @return int 0 unknown, 2, or 3
     */
    protected function settingsMajor()
    {
        $ver = '';
        try {
            if (is_object($this->modx) && method_exists($this->modx, 'getOption')) {
                $ver = (string)$this->modx->getOption('settings_version', null, '');
            }
        } catch (Exception $e) {
            $ver = '';
        } catch (Throwable $e) {
            $ver = '';
        }
        if ($ver === '' && is_object($this->modx) && method_exists($this->modx, 'getObject')) {
            $classes = array('modSystemSetting');
            if (class_exists('\\MODX\\Revolution\\modSystemSetting')) {
                array_unshift($classes, '\\MODX\\Revolution\\modSystemSetting');
            }
            foreach ($classes as $cls) {
                try {
                    $obj = $this->modx->getObject($cls, array('key' => 'settings_version'));
                    if ($obj && method_exists($obj, 'get')) {
                        $ver = (string)$obj->get('value');
                        if ($ver !== '') {
                            break;
                        }
                    }
                } catch (Exception $e) {
                    /* try next */
                } catch (Throwable $e) {
                    /* try next */
                }
            }
        }
        if ($ver !== '' && preg_match('/(\d+)/', $ver, $m)) {
            return ((int)$m[1] >= 3) ? 3 : 2;
        }
        return 0;
    }

    /**
     * True when the site has finished MODX 3 upgrade (DB settings_version), not merely file apply.
     */
    protected function isModx3Live()
    {
        $db = $this->settingsMajor();
        if ($db >= 3) {
            return true;
        }
        if ($db === 2) {
            return false;
        }
        return $this->modxMajor() >= 3;
    }

    protected function modxMajor()
    {
        $candidates = array();
        try {
            $ver = $this->modx->getVersionData();
            if (is_array($ver)) {
                foreach (array('version', 'full_version', 'code_name') as $k) {
                    if (!empty($ver[$k]) && preg_match('/(\d+)/', (string)$ver[$k], $m)) {
                        $candidates[] = (int)$m[1];
                        break;
                    }
                }
            }
        } catch (Exception $e) {
            /* ignore */
        } catch (Throwable $e) {
            /* ignore */
        }
        if (class_exists('\\MODX\\Revolution\\modX', false) || class_exists('MODX\\Revolution\\modX', false)) {
            $candidates[] = 3;
        }
        if (defined('MODX_API_VERSION') && preg_match('/^(\d+)/', (string)MODX_API_VERSION, $m)) {
            $candidates[] = (int)$m[1];
        }
        $core = defined('MODX_CORE_PATH') ? MODX_CORE_PATH : '';
        foreach (array(
            $core . 'docs/version.inc.php',
            $core . 'version.inc.php',
        ) as $vf) {
            if ($vf === '' || !is_file($vf)) {
                continue;
            }
            $raw = (string)@file_get_contents($vf);
            if (preg_match("/\\\$v\\['(?:full_version|version)'\\]\\s*=\\s*'(\\d+)/", $raw, $m)
                || preg_match('/"version"\\s*=>\\s*"(\\d+)/', $raw, $m)
            ) {
                $candidates[] = (int)$m[1];
                break;
            }
        }
        $major = 2;
        foreach ($candidates as $c) {
            if ($c > $major) {
                $major = $c;
            }
        }
        return $major >= 3 ? 3 : 2;
    }

    protected function packageInstalled($name)
    {
        $name = strtolower($name);
        $classes = array('modNamespace');
        if (class_exists('\\MODX\\Revolution\\modNamespace')) {
            array_unshift($classes, '\\MODX\\Revolution\\modNamespace');
        }
        foreach ($classes as $cls) {
            try {
                $ns = $this->modx->getObject($cls, array('name' => $name));
                if ($ns) {
                    return true;
                }
            } catch (Exception $e) {
                /* try next */
            } catch (Throwable $e) {
                /* try next */
            }
        }
        if (defined('MODX_CORE_PATH') && is_dir(MODX_CORE_PATH . 'components/' . $name . '/')) {
            return true;
        }
        if (defined('MODX_ASSETS_PATH') && is_dir(MODX_ASSETS_PATH . 'components/' . $name . '/')) {
            return true;
        }
        return false;
    }

    public function report()
    {
        $att = $this->loadAttention();
        if (!$att) {
            return array('ok' => false, 'error' => 'No Attention Report yet — run Prepare first');
        }
        return array('ok' => true, 'attention' => $att, 'run' => $this->loadRun());
    }

    /**
     * Explicit staging core apply (1A) + post-upgrade audit diff.
     */
    public function applyCore(array $opts = array())
    {
        if (empty($opts['confirm_staging']) || empty($opts['confirm_apply'])) {
            return array(
                'ok' => false,
                'error' => 'confirm_staging and confirm_apply required',
            );
        }
        $run = $this->loadRun();
        if (!$run || empty($run['upgrade_ready'])) {
            $accepted = !empty($opts['force']);
            if (!$accepted) {
                return array(
                    'ok' => false,
                    'error' => 'Prepare did not mark upgrade_ready. Fix Attention Report items or pass force after review.',
                );
            }
        }

        $prePath = $this->storePath . 'last-audit.json';
        $pre = is_file($prePath) ? json_decode((string)file_get_contents($prePath), true) : null;

        /* Pre-apply snapshot (keep existing Recovery password if already active). */
        $preSnap = $this->app->publicSnapshotCreate(
            'migrate-pre-apply',
            array('reactivate_recovery' => false)
        );
        if (empty($preSnap['ok'])) {
            return array(
                'ok' => false,
                'error' => isset($preSnap['error'])
                    ? $preSnap['error']
                    : $this->lex('msg_snapshot_failed', 'Snapshot failed'),
                'snapshot' => isset($preSnap['snapshot']) ? $preSnap['snapshot'] : null,
            );
        }
        $recoveryOut = (!empty($preSnap['recovery']) && is_array($preSnap['recovery']))
            ? $preSnap['recovery']
            : $this->app->publicRecoveryStatus();

        $quarantine = $this->quarantineSetupBlockers();
        $vc = $this->vendorCompat();
        $harden = $vc->hardenSite();
        $remaining = isset($harden['remaining']) ? $harden['remaining'] : $vc->listPresentBlockers();
        if (!empty($remaining) && empty($opts['force'])) {
            return array(
                'ok' => false,
                'error' => $this->lex(
                    'msg_manager_killers_block_apply',
                    'Refusing Apply core: manager killers still on disk ('
                        . implode(', ', $remaining)
                        . '). Open /migratemodx-firstaid.php or fix permissions, then retry. Pass force only after manual review.',
                    array('list' => implode(', ', $remaining))
                ),
                'remaining' => $remaining,
                'harden' => $harden,
                'quarantined' => isset($quarantine['renamed']) ? $quarantine['renamed'] : array(),
            );
        }

        $orch = $this->app->publicOrchestrator();
        $resolved = $this->app->resolveTargets();
        $version = !empty($resolved['effective_modx']) ? $resolved['effective_modx'] : MmxPaths::DEFAULT_TARGET_MODX;
        /* Allow explicit pin from UI/opts only when not auto/legacy */
        if (isset($opts['version']) && $opts['version'] !== '') {
            $req = strtolower(trim((string)$opts['version']));
            if ($req !== 'auto' && $req !== 'latest' && !in_array($req, array('3.0.0', '3.0.0-pl'), true)) {
                $version = preg_replace('/-pl$/', '', (string)$opts['version']);
            }
        }
        $dl = $orch->downloadTarget($version);
        if (empty($dl['ok'])) {
            return $dl;
        }
        $apply = $orch->applyDistro($dl['zip'], true, true);
        if (empty($apply['ok'])) {
            return $apply;
        }

        /* Distro may restore paths; harden again before user opens /setup/ or manager. */
        $hardenAfter = $vc->hardenSite();
        $quarantine['harden_after'] = $hardenAfter;
        $quarantine['renamed'] = array_merge(
            isset($quarantine['renamed']) ? $quarantine['renamed'] : array(),
            isset($harden['quarantined']) ? array_map(function ($r) {
                return $r['name'] . ' → ' . $r['marked_as'];
            }, $harden['quarantined']) : array(),
            isset($hardenAfter['quarantined']) ? array_map(function ($r) {
                return $r['name'] . ' → ' . $r['marked_as'];
            }, $hardenAfter['quarantined']) : array()
        );

        $post = null;
        $diff = array();
        try {
            $post = $this->app->publicFullAudit();
            $diff = $this->diffAudits(is_array($pre) ? $pre : array(), $post);
            @file_put_contents(
                $this->storePath . 'post-upgrade-diff.json',
                json_encode($diff, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            );
        } catch (Exception $e) {
            $diff = array('error' => $e->getMessage());
        } catch (Throwable $e) {
            $diff = array('error' => $e->getMessage());
        }

        $health = $this->app->publicHealth();

        $att = $this->loadAttention();
        if (!is_array($att)) {
            $att = array(
                'done' => array(),
                'failed' => array(),
                'needs_attention' => array(),
                'packages' => array(),
                'ms3' => array(),
            );
        }
        $att['generated_at'] = date('c');
        $sid = !empty($preSnap['snapshot']['id']) ? $preSnap['snapshot']['id'] : '';
        $att['done'][] = array(
            'id' => 'pre_apply_snapshot',
            'message' => $this->lex(
                'msg_pre_apply_snapshot',
                'Pre-apply snapshot created' . ($sid !== '' ? ': ' . $sid : ''),
                array('id' => $sid !== '' ? ': ' . $sid : '')
            ),
        );
        $att['done'][] = array(
            'id' => 'core_applied',
            'message' => $this->lex(
                'msg_core_applied',
                'Core ' . $version . ' applied (staging)',
                array('version' => $version)
            ),
        );
        $att['needs_attention'][] = array(
            'id' => 'recovery_emergency_exit',
            'bucket' => 'known',
            'severity' => 'soft',
            'message' => $this->lex(
                'msg_recovery_emergency',
                'Emergency exit: open migratemodx-recovery.php (site root) or the Recovery URL, enter the password saved at Prepare. Snapshot tab can rotate the password if it was lost.'
            ),
            'recovery' => array(
                'url' => isset($recoveryOut['url']) ? $recoveryOut['url'] : '',
                'shortcut_url' => isset($recoveryOut['shortcut_url']) ? $recoveryOut['shortcut_url'] : '',
                'shortcut' => isset($recoveryOut['shortcut']) ? $recoveryOut['shortcut'] : 'migratemodx-recovery.php',
                'active' => !empty($recoveryOut['active']) || !empty($recoveryOut['password']),
            ),
        );
        $att['needs_attention'][] = array(
            'id' => 'firstaid_ready',
            'bucket' => 'known',
            'severity' => 'soft',
            'message' => $this->lex(
                'msg_firstaid_after_core',
                'After /setup/: if manager shows only the sidebar (Tickets/modUser), open /migratemodx-firstaid.php — one click, no FTP. Killers are also force-quarantined before Apply; first aid is the safety net.'
            ),
            'firstaid_url' => isset($recoveryOut['firstaid_url'])
                ? $recoveryOut['firstaid_url']
                : 'migratemodx-firstaid.php',
        );
        $att['post_upgrade_diff'] = $diff;
        $att['health'] = isset($health['health']) ? $health['health'] : $health;
        $att['agent_export'] = $this->buildAgentExport($att);
        $this->saveAttention($att);
        $this->exportAttentionMarkdown($att);

        if (is_array($run)) {
            $run['core_applied_at'] = date('c');
            $run['core_version'] = $version;
            $run['phase'] = 2;
            if (!empty($preSnap['snapshot']['id'])) {
                $run['pre_apply_snapshot_id'] = $preSnap['snapshot']['id'];
            }
            $this->saveRun($run);
        }

        $needPhp = !empty($resolved['effective_php'])
            ? $resolved['effective_php']
            : (!empty($resolved['required_min_php']) ? $resolved['required_min_php'] : '8.1');
        /* MODX 3.1+/3.2 composer platform_check needs >=8.1 even before MiniShop3's 8.2 floor */
        if (version_compare($needPhp, '8.1.0', '<')) {
            $needPhp = '8.1.0';
        }
        $phpNow = PHP_VERSION;
        $phpOkForSetup = version_compare($phpNow, $needPhp, '>=');
        if (!$phpOkForSetup) {
            $att['needs_attention'][] = array(
                'id' => 'switch_php_before_setup',
                'bucket' => 'known',
                'severity' => 'hard',
                'message' => $this->lex(
                    'msg_switch_php_before_setup',
                    'Host PHP is '
                        . $phpNow
                        . '. MODX '
                        . $version
                        . ' /setup/ will Fatal until you switch the site to PHP ≥'
                        . $needPhp
                        . ' in the hosting panel, then reopen /setup/.',
                    array(
                        'php' => $phpNow,
                        'version' => $version,
                        'need' => $needPhp,
                    )
                ),
            );
            $att['needs_attention'][] = array(
                'id' => 'open_setup',
                'message' => $this->lex(
                    'msg_open_setup_after_php',
                    'After PHP ≥'
                        . $needPhp
                        . ' is active on this site: open /setup/, finish the wizard, then Phase 3.',
                    array('need' => $needPhp)
                ),
                'severity' => 'soft',
            );
            if (is_array($run)) {
                $run['php_for_setup'] = $needPhp;
                $run['php_current'] = $phpNow;
                $run['setup_blocked_by_php'] = true;
                $run['setup_pending'] = true;
                $this->saveRun($run);
            }
        } else {
            $att['needs_attention'][] = array(
                'id' => 'open_setup',
                'message' => $this->lex(
                    'msg_open_setup',
                    'Open /setup/ in the browser and finish the MODX upgrade wizard. Then continue Phase 3 (MiniShop3 only after MODX 3). Setup-breaking extras should already be auto-quarantined.'
                ),
                'severity' => 'soft',
            );
            if (is_array($run)) {
                $run['php_for_setup'] = $needPhp;
                $run['setup_pending'] = true;
                $run['setup_blocked_by_php'] = false;
                $this->saveRun($run);
            }
        }
        if (!empty($quarantine['renamed'])) {
            $att['needs_attention'][] = array(
                'id' => 'setup_blockers_quarantined',
                'bucket' => 'known',
                'message' => $this->lex(
                    'msg_setup_blockers_quarantined',
                    'Quarantined extras that break /setup/ (e.g. AdminTools): '
                        . implode(', ', $quarantine['renamed'])
                        . '. Folders renamed with .__disabled_for_modx3 — restore or replace after upgrade.',
                    array('list' => implode(', ', $quarantine['renamed']))
                ),
                'severity' => 'soft',
                'quarantined' => $quarantine['renamed'],
            );
            $att['done'][] = array(
                'id' => 'setup_blockers_quarantined',
                'bucket' => 'known',
                'message' => $this->lex(
                    'msg_setup_blockers_quarantined',
                    'Quarantined: ' . implode(', ', $quarantine['renamed']),
                    array('list' => implode(', ', $quarantine['renamed']))
                ),
            );
        }
        $att['agent_export'] = $this->buildAgentExport($att);
        $this->saveAttention($att);
        $this->exportAttentionMarkdown($att);

        $guide = $this->buildGuide(is_array($run) ? $run : array(), $att, $post);

        return array(
            'ok' => true,
            'quarantined' => isset($quarantine['renamed']) ? $quarantine['renamed'] : array(),
            'apply' => $apply,
            'download' => $dl,
            'diff' => $diff,
            'health' => $health,
            'attention' => $att,
            'report' => $post,
            'recovery' => $recoveryOut,
            'snapshot' => isset($preSnap['snapshot']) ? $preSnap['snapshot'] : null,
            'phase' => $guide['phase'],
            'next_actions' => $guide['next_actions'],
            'modx_major' => $guide['modx_major'],
            'setup_pending' => !empty($guide['setup_pending']),
            'run' => is_array($run) ? $run : null,
        );
    }

    /**
     * Quarantine known setup/manager-breaking extras before /setup/ (AdminTools, Batcher, Tickets…).
     *
     * @return array{renamed:string[],skipped:string[]}
     */
    protected function quarantineSetupBlockers()
    {
        $vc = $this->vendorCompat();
        $res = $vc->quarantineKnownBlockers(true);
        $harden = $vc->hardenSite();
        $renamed = array();
        $skipped = array();
        foreach ((array)$res['quarantined'] as $row) {
            $renamed[] = $row['name'] . ' → ' . $row['marked_as'];
        }
        foreach ((array)$harden['quarantined'] as $row) {
            $mark = $row['name'] . ' → ' . $row['marked_as'];
            if (!in_array($mark, $renamed, true)) {
                $renamed[] = $mark;
            }
        }
        foreach ((array)$res['skipped'] as $row) {
            $skipped[] = $row['name'] . ' (' . $row['action'] . ')';
        }
        foreach ((array)$res['ported'] as $row) {
            $renamed[] = $row['name'] . ' → ' . $row['marked_as'] . ' (ported)';
        }
        return array(
            'renamed' => $renamed,
            'skipped' => $skipped,
            'detail' => $res,
            'harden' => $harden,
            'remaining' => isset($harden['remaining']) ? $harden['remaining'] : array(),
        );
    }

    /**
     * @return MmxVendorCompat
     */
    protected function vendorCompat()
    {
        if (defined('MODX_BASE_PATH')) {
            $base = MODX_BASE_PATH;
        } else {
            /* .../core/components/migratemodx → site root */
            $cp = rtrim(str_replace('\\', '/', $this->corePath), '/');
            $base = dirname(dirname(dirname($cp))) . '/';
        }
        return new MmxVendorCompat(
            $this->modx,
            $base,
            $this->storePath,
            $this->corePath . 'data'
        );
    }

    /**
     * Build package compatibility matrix rows.
     */
    public function buildPackageMatrix(array $packages)
    {
        $names = array();
        foreach ($packages as $p) {
            if (!empty($p['name'])) {
                $names[strtolower($p['name'])] = true;
            }
        }
        $rows = array();
        foreach ($packages as $p) {
            $name = isset($p['name']) ? strtolower($p['name']) : '';
            if ($name === '') {
                continue;
            }
            $verdict = isset($p['verdict']) ? $p['verdict'] : 'unknown';
            $successor = isset($p['successor']) ? $p['successor'] : '';
            $repoLatest = '';
            if (!empty($p['repo_best']['version'])) {
                $repoLatest = $p['repo_best']['version'];
            } elseif (!empty($p['repo']['best']['version'])) {
                $repoLatest = $p['repo']['best']['version'];
            } elseif (!empty($p['latest_version'])) {
                $repoLatest = $p['latest_version'];
            }
            $conflicts = array();
            if ($successor && isset($names[strtolower($successor)]) && isset($names[$name])) {
                $conflicts[] = 'both_' . $name . '_and_' . $successor;
            }
            if ($verdict === 'replace' && $successor === '') {
                $conflicts[] = 'replace_without_successor';
            }
            if (isset($p['version_status']) && $p['version_status'] === 'outdated') {
                $conflicts[] = 'outdated';
            }
            $modx3Ok = in_array($verdict, array('ok', 'update'), true)
                || (!empty($p['resolution']) && $p['resolution'] === 'successor_installed');
            if ($verdict === 'fail' || $verdict === 'incompatible') {
                $modx3Ok = false;
            }
            $rows[] = array(
                'name' => $name,
                'installed' => isset($p['version']) ? $p['version'] : '',
                'repo_latest' => $repoLatest,
                'required' => isset($p['required_version']) ? $p['required_version'] : (isset($p['min_version']) ? $p['min_version'] : ''),
                'modx3_ok' => $modx3Ok,
                'needs_update' => ($verdict === 'update' || (isset($p['version_status']) && $p['version_status'] === 'outdated')),
                'successor' => $successor,
                'verdict' => $verdict,
                'conflicts' => $conflicts,
                'notes' => isset($p['notes']) ? $p['notes'] : '',
            );
        }
        return $rows;
    }

    protected function packageAttentionMessage(array $row)
    {
        $parts = array($this->lex(
            'msg_pkg_verdict',
            $row['name'] . ': verdict=' . $row['verdict'],
            array('name' => $row['name'], 'verdict' => $row['verdict'])
        ));
        if (!empty($row['needs_update'])) {
            if ($row['repo_latest'] !== '') {
                $parts[] = $this->lex(
                    'msg_pkg_needs_update_repo',
                    'needs update (repo ' . $row['repo_latest'] . ')',
                    array('repo' => $row['repo_latest'])
                );
            } else {
                $parts[] = $this->lex('msg_pkg_needs_update', 'needs update');
            }
        }
        if (!empty($row['successor'])) {
            $parts[] = $this->lex(
                'msg_pkg_successor',
                'successor=' . $row['successor'],
                array('successor' => $row['successor'])
            );
        }
        if (!empty($row['conflicts'])) {
            $parts[] = $this->lex(
                'msg_pkg_conflicts',
                'conflicts: ' . implode(', ', $row['conflicts']),
                array('list' => implode(', ', $row['conflicts']))
            );
        }
        if (empty($row['modx3_ok'])) {
            $parts[] = $this->lex(
                'msg_pkg_not_modx3',
                'not MODX 3 ready — use Phase 3 «Install/update packages» or Package Management'
            );
        }
        return implode('; ', $parts);
    }

    /**
     * Phase 3: download + install successors (e.g. MiniShop3) and update outdated MODX-3-ready extras.
     */
    public function installPackages(array $opts = array())
    {
        if (!$this->isModx3Live()) {
            return array(
                'ok' => false,
                'error' => $this->lex(
                    'msg_err_ms3_need_modx3',
                    'Install/update extras only after MODX 3 is live (Phase 2 + /setup/).'
                ),
                'phase' => 2,
            );
        }
        if (empty($opts['confirm_packages'])) {
            return array(
                'ok' => false,
                'error' => $this->lex(
                    'msg_err_confirm_packages',
                    'confirm_packages required: review the plan, then confirm download/install from providers.'
                ),
            );
        }
        $apply = !isset($opts['apply']) || !empty($opts['apply']);
        $packages = $this->loadAuditPackages();
        $kb = $this->loadKbPackagesMap();
        $repoUrls = array(
            'modx' => (string)$this->modx->getOption(
                'migratemodx_repo_modx_url',
                null,
                'https://rest.modx.com/extras/'
            ),
            'modstore' => (string)$this->modx->getOption(
                'migratemodx_repo_modstore_url',
                null,
                'https://modstore.pro/extras/'
            ),
        );
        $installer = new MmxPackageInstaller($this->modx, $this->storePath, $repoUrls);
        $plan = $installer->buildPlan($packages, $kb);
        $result = $installer->run($plan, $apply);
        $front = null;
        if ($apply) {
            /* After new extras land, heal theme (forms, orphan Tickets stubs, minicart, …) */
            $front = $this->applyMs3FrontCompatInternal(true);
        }

        $att = $this->loadAttention();
        if (!is_array($att)) {
            $att = array('done' => array(), 'failed' => array(), 'needs_attention' => array());
        }
        $att['generated_at'] = date('c');
        $sum = isset($plan['summary']) ? $plan['summary'] : array();
        $att['done'][] = array(
            'id' => 'packages_install',
            'bucket' => 'known',
            'message' => $this->lex(
                'msg_packages_install',
                'Package install/update: '
                . (int)(isset($sum['installs']) ? $sum['installs'] : 0) . ' install(s), '
                . (int)(isset($sum['updates']) ? $sum['updates'] : 0) . ' update(s).',
                array(
                    'installs' => (int)(isset($sum['installs']) ? $sum['installs'] : 0),
                    'updates' => (int)(isset($sum['updates']) ? $sum['updates'] : 0),
                )
            ),
            'detail' => array(
                'summary' => $sum,
                'applied' => !empty($result['applied']),
                'installed_ms3' => !empty($result['installed_ms3']),
            ),
        );
        if (is_array($front)) {
            $this->mergeFrontCompatIntoAttention($att, $front);
        }
        foreach ((array)(isset($result['done']) ? $result['done'] : array()) as $row) {
            if (is_array($row)) {
                $att['done'][] = array_merge(array('bucket' => 'packages'), $row);
            }
        }
        foreach ((array)(isset($result['failed']) ? $result['failed'] : array()) as $row) {
            if (is_array($row)) {
                $att['failed'][] = array_merge(array('bucket' => 'packages'), $row);
            }
        }
        foreach ((array)(isset($result['needs_attention']) ? $result['needs_attention'] : array()) as $row) {
            if (is_array($row)) {
                $att['needs_attention'][] = array_merge(array('bucket' => 'packages'), $row);
            }
        }
        $att['agent_export'] = $this->buildAgentExport($att);
        $this->saveAttention($att);
        $this->exportAttentionMarkdown($att);

        $run = $this->loadRun();
        if (!is_array($run)) {
            $run = array();
        }
        if ($apply) {
            $run['packages_installed_at'] = date('c');
            $run['packages_install_ok'] = !empty($result['ok']);
            if (!empty($result['installed_ms3'])) {
                $run['ms3_detected_at'] = date('c');
            }
        }
        $this->saveRun($run);
        $guide = $this->buildGuide($run, $att, null);
        return array(
            'ok' => !empty($result['ok']),
            'packages' => $result,
            'plan' => $plan,
            'attention' => $att,
            'run' => $run,
            'ms3_installed' => !empty($result['installed_ms3']) || !empty($guide['ms3_installed']),
            'error' => empty($result['ok'])
                ? $this->lex(
                    'msg_packages_install_failed',
                    'Some packages failed to download/install — see Attention (paid/auth packages need Package Management).'
                )
                : null,
            'phase' => $guide['phase'],
            'next_actions' => $guide['next_actions'],
            'modx_major' => $guide['modx_major'],
            'has_ms2' => $guide['has_ms2'],
        );
    }

    /**
     * @return array
     */
    protected function loadAuditPackages()
    {
        $path = $this->storePath . 'last-audit.json';
        if (!is_file($path)) {
            return array();
        }
        $data = json_decode((string)file_get_contents($path), true);
        if (!is_array($data)) {
            return array();
        }
        if (!empty($data['packages']) && is_array($data['packages'])) {
            return $data['packages'];
        }
        if (!empty($data['audit']['packages']) && is_array($data['audit']['packages'])) {
            return $data['audit']['packages'];
        }
        return array();
    }

    /**
     * @return array
     */
    protected function loadKbPackagesMap()
    {
        $path = $this->corePath . 'data/kb/packages.json';
        if (!is_file($path)) {
            return array();
        }
        $data = json_decode((string)file_get_contents($path), true);
        if (!is_array($data) || empty($data['packages']) || !is_array($data['packages'])) {
            return array();
        }
        return $data['packages'];
    }

    protected function diffAudits(array $pre, array $post)
    {
        $preGate = isset($pre['gate']) ? $pre['gate'] : array();
        $postGate = isset($post['gate']) ? $post['gate'] : array();
        $prePkgs = array();
        foreach ((array)(isset($pre['packages']) ? $pre['packages'] : array()) as $p) {
            if (!empty($p['name'])) {
                $prePkgs[$p['name']] = $p;
            }
        }
        $postPkgs = array();
        foreach ((array)(isset($post['packages']) ? $post['packages'] : array()) as $p) {
            if (!empty($p['name'])) {
                $postPkgs[$p['name']] = $p;
            }
        }
        $pkgChanges = array();
        foreach ($postPkgs as $name => $p) {
            $before = isset($prePkgs[$name]) ? $prePkgs[$name] : null;
            $bv = $before ? (isset($before['verdict']) ? $before['verdict'] : '') : '(new)';
            $av = isset($p['verdict']) ? $p['verdict'] : '';
            if ($bv !== $av) {
                $pkgChanges[] = array('name' => $name, 'before' => $bv, 'after' => $av);
            }
        }
        return array(
            'score' => array(
                'before' => isset($preGate['score']) ? $preGate['score'] : null,
                'after' => isset($postGate['score']) ? $postGate['score'] : null,
            ),
            'upgrade_ready' => array(
                'before' => !empty($preGate['upgrade_ready']),
                'after' => !empty($postGate['upgrade_ready']),
            ),
            'blockers_before' => count(isset($preGate['blockers']) ? $preGate['blockers'] : array()),
            'blockers_after' => count(isset($postGate['blockers']) ? $postGate['blockers'] : array()),
            'php_findings' => array(
                'before' => count(isset($pre['php']) ? $pre['php'] : array()),
                'after' => count(isset($post['php']) ? $post['php'] : array()),
            ),
            'package_verdict_changes' => $pkgChanges,
            'modx_version' => array(
                'before' => isset($pre['environment']['modx_version']) ? $pre['environment']['modx_version'] : '',
                'after' => isset($post['environment']['modx_version']) ? $post['environment']['modx_version'] : '',
            ),
        );
    }

    /**
     * Split items into known / unknown buckets and rebuild agent_export.
     */
    protected function finalizeAttention(array $attention, $goal)
    {
        $attention['goal'] = $goal;
        $attention['generated_at'] = date('c');
        $classified = $this->classifyAttentionBuckets($attention);
        $attention['known'] = $classified['known'];
        $attention['unknown'] = $classified['unknown'];
        $attention['agent_export'] = $this->buildAgentExport($attention);
        $this->saveAttention($attention);
        $this->exportAttentionMarkdown($attention);
        return $attention;
    }

    protected function classifyAttentionBuckets(array $attention)
    {
        $known = array();
        $unknown = array();
        $pools = array(
            isset($attention['done']) ? $attention['done'] : array(),
            isset($attention['needs_attention']) ? $attention['needs_attention'] : array(),
            isset($attention['failed']) ? $attention['failed'] : array(),
        );
        foreach ($pools as $pool) {
            foreach ((array)$pool as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $bucket = isset($item['bucket']) ? $item['bucket'] : $this->inferBucket($item);
                $item['bucket'] = $bucket;
                if ($bucket === 'known') {
                    $known[] = $item;
                } else {
                    $unknown[] = $item;
                }
            }
        }
        if (!empty($attention['packages']) && is_array($attention['packages'])) {
            foreach ($attention['packages'] as $p) {
                if (empty($p['modx3_ok']) || !empty($p['conflicts']) || !empty($p['needs_update'])) {
                    $known[] = array(
                        'id' => 'pkg_' . (isset($p['name']) ? $p['name'] : 'x'),
                        'bucket' => 'known',
                        'message' => $this->lex(
                            'msg_pkg_matrix_flag',
                            'Package ' . (isset($p['name']) ? $p['name'] : '?')
                                . ' verdict=' . (isset($p['verdict']) ? $p['verdict'] : '')
                                . ' — deterministic matrix flag (not a full custom-code analysis).',
                            array(
                                'name' => isset($p['name']) ? $p['name'] : '?',
                                'verdict' => isset($p['verdict']) ? $p['verdict'] : '',
                            )
                        ),
                    );
                }
            }
        }
        if (!empty($attention['schema_notes']['notes']) && is_array($attention['schema_notes']['notes'])) {
            foreach ($attention['schema_notes']['notes'] as $n) {
                $known[] = array(
                    'id' => 'schema_' . (isset($n['id']) ? $n['id'] : 'note'),
                    'bucket' => 'known',
                    'message' => isset($n['message']) ? $n['message'] : '',
                );
            }
        }
        return array('known' => $known, 'unknown' => $unknown);
    }

    protected function inferBucket(array $item)
    {
        $id = isset($item['id']) ? (string)$item['id'] : '';
        if (isset($item['bucket'])) {
            return $item['bucket'] === 'known' ? 'known' : 'needs_agent_review';
        }
        $knownIds = array(
            'snapshot', 'audit', 'php_keepers', 'php_rescan', 'package_matrix', 'vendor_compat', 'attention_report',
            'ms2_absent', 'ms3_data_deferred', 'ms3_phase3', 'next_phase3_ms3', 'db_refresh_advice',
            'core_applied', 'open_setup', 'snapshot_skipped', 'setup_blockers_quarantined',
        );
        if (in_array($id, $knownIds, true) || strpos($id, 'pkg_') === 0 || strpos($id, 'schema_') === 0
            || strpos($id, 'ms3_') === 0 || strpos($id, 'php_') === 0) {
            if (strpos($id, 'custom_ms_plugin') === 0) {
                return 'needs_agent_review';
            }
            if (isset($item['severity']) && $item['severity'] === 'hard' && strpos($id, 'schema_gap_') === 0) {
                return 'known';
            }
            return 'known';
        }
        if (strpos($id, 'custom_') === 0 || strpos($id, 'mod_action') === 0 || strpos($id, 'ms2_') === 0) {
            return 'needs_agent_review';
        }
        return 'needs_agent_review';
    }

    protected function loadSchemaNotes()
    {
        $path = $this->corePath . 'data/rewrites/modx2-modx3-schema-notes.json';
        if (!is_file($path)) {
            return array('advice' => '', 'notes' => array());
        }
        $data = json_decode((string)file_get_contents($path), true);
        if (!is_array($data)) {
            return array('advice' => '', 'notes' => array());
        }
        $ru = $this->isRu();
        if ($ru && !empty($data['advice_ru'])) {
            $data['advice'] = $data['advice_ru'];
        }
        if (!empty($data['notes']) && is_array($data['notes'])) {
            foreach ($data['notes'] as &$note) {
                if ($ru && !empty($note['message_ru'])) {
                    $note['message'] = $note['message_ru'];
                }
            }
            unset($note);
        }
        return $data;
    }

    protected function buildAgentExport(array $attention)
    {
        $md = "# MigrateMODX Attention Report\n\n";
        $md .= "> DISCLAIMER: This report is an inventory and set of flags (known scanners + KB). "
            . "It is NOT a full analysis of custom code. Treat «done» as «scanner finished a step», "
            . "not «site understood». Agent/human must still review unknown/needs_agent_review items "
            . "and re-check packages/custom logic. Prefight ≠ few-click migration.\n\n";
        $md .= 'Generated: ' . (isset($attention['generated_at']) ? $attention['generated_at'] : date('c')) . "\n";
        $md .= 'Goal: ' . (isset($attention['goal']) ? $attention['goal'] : 'modx3') . "\n\n";
        if (!empty($attention['gate'])) {
            $g = $attention['gate'];
            $md .= '## Gate
score=' . (isset($g['score']) ? $g['score'] : '?')
                . ' upgrade_ready=' . (!empty($g['upgrade_ready']) ? 'yes' : 'no')
                . ' php_ready=' . (!empty($g['php_ready']) ? 'yes' : 'no') . "\n\n";
        }
        if (!empty($attention['php'])) {
            $md .= '## PHP track
remaining=' . (isset($attention['php']['remaining']) ? $attention['php']['remaining'] : '?')
                . ' php_ready=' . (!empty($attention['php']['php_ready']) ? 'yes' : 'no') . "\n\n";
        }
        $md .= "## Known (deterministic)\n";
        if (empty($attention['known'])) {
            $md .= "- (none)\n";
        } else {
            foreach ($attention['known'] as $d) {
                $md .= '- **' . (isset($d['id']) ? $d['id'] : '?') . '**: '
                    . (isset($d['message']) ? $d['message'] : '') . "\n";
            }
        }
        $md .= "\n## Unknown / needs agent review\n";
        if (empty($attention['unknown'])) {
            $md .= "- (none)\n";
        } else {
            foreach ($attention['unknown'] as $d) {
                $md .= '- **' . (isset($d['id']) ? $d['id'] : '?') . '**: '
                    . (isset($d['message']) ? $d['message'] : '') . "\n";
            }
        }
        $md .= "\n## Failed\n";
        if (empty($attention['failed'])) {
            $md .= "- (none)\n";
        } else {
            foreach ($attention['failed'] as $d) {
                $md .= '- **' . (isset($d['id']) ? $d['id'] : '?') . '**: '
                    . (isset($d['message']) ? $d['message'] : '') . "\n";
            }
        }
        $md .= "\n## Packages\n";
        foreach ((array)$attention['packages'] as $p) {
            $md .= sprintf(
                "- %s installed=%s repo=%s modx3_ok=%s successor=%s conflicts=%s\n",
                $p['name'],
                $p['installed'],
                $p['repo_latest'],
                !empty($p['modx3_ok']) ? 'yes' : 'no',
                $p['successor'],
                $p['conflicts'] ? implode('|', $p['conflicts']) : '-'
            );
        }
        if (!empty($attention['schema_notes']['advice'])) {
            $md .= "\n## DB / schema advice\n" . $attention['schema_notes']['advice'] . "\n";
        }
        if (!empty($attention['ms3'])) {
            $md .= "\n## ms3 data\n```\n" . json_encode($attention['ms3'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n```\n";
        }
        if (!empty($attention['post_upgrade_diff'])) {
            $md .= "\n## Post-upgrade diff\n```\n"
                . json_encode($attention['post_upgrade_diff'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
                . "\n```\n";
        }
        return $md;
    }

    protected function exportAttentionMarkdown(array $attention)
    {
        $md = isset($attention['agent_export']) ? $attention['agent_export'] : $this->buildAgentExport($attention);
        $path = $this->storePath . 'last-attention.md';
        file_put_contents($path, $md);
        return $path;
    }

    protected function beginStep(array &$run, $id, $label)
    {
        $step = array(
            'id' => $id,
            'label' => $label,
            'status' => 'running',
            'started_at' => date('c'),
            'finished_at' => null,
            'detail' => null,
        );
        $run['steps'][] = $step;
        $run['current_step'] = $id;
        $this->saveRun($run);
        return count($run['steps']) - 1;
    }

    protected function finishStep(array &$run, $idx, $ok, $detail = null)
    {
        if (!isset($run['steps'][$idx])) {
            return;
        }
        $run['steps'][$idx]['status'] = $ok ? 'ok' : 'failed';
        $run['steps'][$idx]['finished_at'] = date('c');
        $run['steps'][$idx]['detail'] = $detail;
        $this->saveRun($run);
    }

    protected function abortHard(array &$run, array &$attention, $stepId)
    {
        $run['status'] = 'failed';
        $run['hard_blocked'] = true;
        $run['failed_step'] = $stepId;
        $run['finished_at'] = date('c');
        $attention['steps'] = isset($run['steps']) ? $run['steps'] : array();
        $goal = !empty($run['goal']) ? $run['goal'] : 'modx3';
        $attention = $this->finalizeAttention($attention, $goal);
        $this->saveRun($run);
        return array(
            'ok' => false,
            'error' => 'Hard stop at step: ' . $stepId,
            'run' => $run,
            'attention' => $attention,
            'upgrade_ready' => false,
            'goal' => $goal,
        );
    }

    protected function saveRun(array $run)
    {
        file_put_contents($this->runFile, json_encode($run, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    protected function loadRun()
    {
        if (!is_file($this->runFile)) {
            return null;
        }
        $data = json_decode((string)file_get_contents($this->runFile), true);
        return is_array($data) ? $data : null;
    }

    protected function saveAttention(array $att)
    {
        file_put_contents($this->attentionFile, json_encode($att, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    protected function loadAttention()
    {
        if (!is_file($this->attentionFile)) {
            return null;
        }
        $data = json_decode((string)file_get_contents($this->attentionFile), true);
        return is_array($data) ? $data : null;
    }
}
