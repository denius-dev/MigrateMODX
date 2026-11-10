<?php
/**
 * ms2 → ms3 code scan + catalog/orders data migrate (dry-run / apply).
 */
class MmxMs2Migrator
{
    /** @var modX */
    protected $modx;
    protected $storePath;
    protected $schema = array();
    protected $rewriteRules = array();

    public function __construct($modx, $dataDir, $storePath)
    {
        $this->modx = $modx;
        $this->storePath = rtrim(str_replace('\\', '/', $storePath), '/') . '/';
        $dataDir = rtrim(str_replace('\\', '/', $dataDir), '/') . '/';
        $this->schema = $this->loadJson($dataDir . 'ms2-ms3-schema.json');
        $this->rewriteRules = $this->loadJson($dataDir . 'ms2-ms3.json');
        if (is_object($this->modx) && isset($this->modx->lexicon) && is_object($this->modx->lexicon)
            && method_exists($this->modx->lexicon, 'load')) {
            $this->modx->lexicon->load('migratemodx:default');
        }
    }

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
     * @param bool $apply When false — dry-run counters only
     * @param bool $confirmMs3Install UI confirmed ms3 is installed / ready
     * @return array
     */
    public function run($apply = false, $confirmMs3Install = false)
    {
        $done = array();
        $failed = array();
        $needs = array();
        $ms3 = array(
            'mode' => $apply ? 'apply' : 'dry-run',
            'copied' => array(),
            'skipped' => array(),
            'failed' => array(),
            'unmapped' => array(),
            'verify' => array(),
            'id_map' => array(),
        );

        $codeScan = $this->scanCustomMsPlugins();
        $needs = array_merge($needs, $codeScan['needs_attention']);
        $done = array_merge($done, $codeScan['done']);

        $ms3Installed = $this->isPackageInstalled('minishop3');
        $ms2Installed = $this->isPackageInstalled('minishop2');

        if (!$ms2Installed) {
            $done[] = array(
                'id' => 'ms2_absent',
                'message' => $this->lex('msg_ms2_absent', 'miniShop2 not installed — data migrate skipped'),
            );
            return array(
                'ok' => true,
                'done' => $done,
                'failed' => $failed,
                'needs_attention' => $needs,
                'ms3' => $ms3,
            );
        }

        if (!$ms3Installed) {
            if ($apply) {
                $needs[] = array(
                    'id' => 'ms3_not_installed',
                    'message' => $this->lex(
                        'msg_ms3_install_then_apply',
                        'Install MiniShop3 (MODX 3 only) from Package Management, confirm in UI, then apply data migrate.'
                    ),
                    'severity' => 'hard',
                );
                return array(
                    'ok' => false,
                    'done' => $done,
                    'failed' => array(array(
                        'id' => 'ms3_not_installed',
                        'message' => $this->lex('msg_ms3_not_installed', 'MiniShop3 not installed'),
                    )),
                    'needs_attention' => $needs,
                    'ms3' => $ms3,
                    'blocked' => 'ms3_not_installed',
                );
            }
            /* Phase 1 prepare: soft next-step, do not hard-fail the whole pipeline */
            $needs[] = array(
                'id' => 'ms3_phase3',
                'message' => $this->lex(
                    'msg_ms3_phase3',
                    'After MODX 3.0.3 + /setup/: install MiniShop3, then use Phase 3 «Apply ms2→ms3 data». Do not install MiniShop3 on MODX 2.'
                ),
                'severity' => 'soft',
            );
            $done[] = array(
                'id' => 'ms3_data_deferred',
                'message' => $this->lex(
                    'msg_ms3_deferred',
                    'ms2→ms3 data apply deferred to Phase 3 (after MODX 3 + MiniShop3)'
                ),
            );
            return array(
                'ok' => true,
                'done' => $done,
                'failed' => $failed,
                'needs_attention' => $needs,
                'ms3' => $ms3,
                'blocked' => 'ms3_deferred_phase3',
                'has_ms2' => true,
            );
        }

        if ($apply && !$confirmMs3Install) {
            $needs[] = array(
                'id' => 'ms3_confirm_required',
                'message' => $this->lex(
                    'msg_ms3_confirm_required',
                    'Confirm MiniShop3 install in UI before applying data migrate.'
                ),
                'severity' => 'hard',
            );
            return array(
                'ok' => false,
                'done' => $done,
                'failed' => array(array(
                    'id' => 'ms3_confirm_required',
                    'message' => $this->lex('msg_ms3_confirm_flag', 'confirm_ms3 flag required for apply'),
                )),
                'needs_attention' => $needs,
                'ms3' => $ms3,
            );
        }

        $tables = isset($this->schema['tables']) && is_array($this->schema['tables'])
            ? $this->schema['tables'] : array();
        if (!$tables) {
            $failed[] = array(
                'id' => 'schema_empty',
                'message' => 'ms2-ms3-schema.json has no tables',
            );
            return array(
                'ok' => false,
                'done' => $done,
                'failed' => $failed,
                'needs_attention' => $needs,
                'ms3' => $ms3,
            );
        }

        $prefix = $this->tablePrefix();
        if ($apply) {
            $ms3['orders_prepare'] = $this->prepareOrderTables($prefix);
        }
        foreach ($tables as $spec) {
            $result = $this->migrateTable($spec, $prefix, $apply);
            if (!empty($result['hard'])) {
                $needs[] = array(
                    'id' => 'schema_gap_' . $spec['id'],
                    'message' => $result['message'],
                    'severity' => 'hard',
                );
                if (!empty($result['message'])) {
                    $failed[] = array(
                        'id' => 'table_' . $spec['id'],
                        'message' => $result['message'],
                    );
                }
            }
            if (!empty($result['copied'])) {
                $ms3['copied'][$spec['id']] = $result['copied'];
            }
            if (!empty($result['skipped'])) {
                $ms3['skipped'][$spec['id']] = $result['skipped'];
            }
            /* result['failed'] is a row-failure COUNT (int), not a list */
            if (!empty($result['failed']) && is_numeric($result['failed'])) {
                $ms3['failed'][$spec['id']] = (int)$result['failed'];
                if (empty($result['hard'])) {
                    $failed[] = array(
                        'id' => 'table_' . $spec['id'],
                        'message' => !empty($result['message'])
                            ? $result['message']
                            : ($spec['id'] . ': ' . (int)$result['failed'] . ' row failures'),
                    );
                }
            }
            if (!empty($result['unmapped'])) {
                $ms3['unmapped'] = array_merge($ms3['unmapped'], $result['unmapped']);
            }
            if (!empty($result['verify'])) {
                $ms3['verify'][$spec['id']] = $result['verify'];
            }
            if (!empty($result['id_map'])) {
                $ms3['id_map'][$spec['id']] = $result['id_map'];
            }
            if (!empty($result['ok_msg'])) {
                $done[] = array(
                    'id' => 'ms3_' . $spec['id'],
                    'message' => $result['ok_msg'],
                );
            }
        }

        if ($apply) {
            $ms3['orders_finalize'] = $this->finalizeOrderTables($prefix);
        }

        $mapFile = $this->storePath . 'ms3-id-map.json';
        file_put_contents($mapFile, json_encode($ms3['id_map'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $reportFile = $this->storePath . 'ms3-migrate-' . ($apply ? 'apply' : 'dry') . '-' . date('Ymd-His') . '.json';
        file_put_contents($reportFile, json_encode($ms3, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        /* Only blocking schema gaps (missing dest table / required src / zero mapped cols).
         * Optional ms3 column renames are soft — migrate what we can. */
        $hardUnmapped = false;
        $hardReasons = array();
        foreach ($ms3['unmapped'] as $u) {
            if (empty($u['hard'])) {
                continue;
            }
            $reason = isset($u['reason']) ? $u['reason'] : '';
            if (in_array($reason, array('ms3_table_missing', 'required_missing', 'no_mappable'), true)) {
                $hardUnmapped = true;
                $hardReasons[] = (isset($u['table']) ? $u['table'] : '') . '.' . (isset($u['column']) ? $u['column'] : '') . ' (' . $reason . ')';
            }
        }
        if ($hardUnmapped) {
            $msg = $this->lex(
                'msg_ms3_unmapped_schema',
                'Blocking ms2→ms3 schema gaps: ' . implode('; ', array_slice($hardReasons, 0, 8)),
                array('list' => implode('; ', array_slice($hardReasons, 0, 8)))
            );
            $needs[] = array(
                'id' => 'ms3_unmapped_schema',
                'message' => $msg,
                'severity' => 'hard',
            );
            $failed[] = array(
                'id' => 'ms3_unmapped_schema',
                'message' => $msg,
            );
        } elseif (!empty($ms3['unmapped'])) {
            $soft = 0;
            foreach ($ms3['unmapped'] as $u) {
                if (empty($u['hard']) || (isset($u['reason']) && $u['reason'] === 'ms3_column_missing')) {
                    $soft++;
                }
            }
            if ($soft > 0) {
                $needs[] = array(
                    'id' => 'ms3_soft_unmapped',
                    'message' => $this->lex(
                        'msg_ms3_soft_unmapped',
                        'Some columns were skipped (soft): see ms3 migrate report. Mapped columns were still copied.',
                        array('n' => $soft)
                    ),
                    'severity' => 'soft',
                );
            }
        }

        $errorSummary = '';
        if (!empty($failed)) {
            $parts = array();
            foreach ($failed as $f) {
                if (!empty($f['message'])) {
                    $parts[] = $f['message'];
                }
            }
            $errorSummary = implode(' | ', array_slice($parts, 0, 5));
        }

        return array(
            'ok' => empty($failed) && !$hardUnmapped,
            'done' => $done,
            'failed' => $failed,
            'needs_attention' => $needs,
            'ms3' => $ms3,
            'report_file' => $reportFile,
            'error' => $errorSummary !== '' ? $errorSummary : null,
        );
    }

    protected function scanCustomMsPlugins()
    {
        $done = array();
        $needs = array();
        $cls = class_exists('\\MODX\\Revolution\\modPlugin') ? '\\MODX\\Revolution\\modPlugin' : 'modPlugin';
        try {
            $plugins = $this->modx->getCollection($cls);
        } catch (Exception $e) {
            $plugins = null;
        } catch (Throwable $e) {
            $plugins = null;
        }
        if ($plugins) {
            foreach ($plugins as $p) {
                $name = (string)$p->get('name');
                $code = (string)$p->get('plugincode');
                if ($name === '' && $code === '') {
                    continue;
                }
                if (preg_match('/^ms[A-Z0-9_]/i', $name) || preg_match('/\bminiShop2\b|\bms2_/i', $code)) {
                    if (preg_match('/@package\s+minishop/i', $code) || stripos($name, 'ms') === 0) {
                        $needs[] = array(
                            'id' => 'custom_ms_plugin_' . $name,
                            'message' => 'Custom/legacy ms* plugin «' . $name . '» — review for MiniShop3 events/API.',
                            'source' => 'plugin',
                            'name' => $name,
                        );
                    }
                }
            }
        }
        if (!$needs) {
            $done[] = array('id' => 'ms_plugins_ok', 'message' => 'No custom ms* plugins flagged');
        }
        return array('done' => $done, 'needs_attention' => $needs);
    }

    protected function migrateTable(array $spec, $prefix, $apply)
    {
        $id = isset($spec['id']) ? $spec['id'] : 'unknown';
        $ms2 = isset($spec['ms2_table']) ? $spec['ms2_table'] : '';
        $ms3 = isset($spec['ms3_table']) ? $spec['ms3_table'] : '';
        $cols = isset($spec['columns']) && is_array($spec['columns']) ? $spec['columns'] : array();
        $required = isset($spec['required_ms2']) && is_array($spec['required_ms2']) ? $spec['required_ms2'] : array();

        $src = $prefix . $ms2;
        $dst = $prefix . $ms3;
        $out = array(
            'copied' => 0,
            'skipped' => 0,
            'failed' => 0,
            'unmapped' => array(),
            'verify' => array(),
            'id_map' => array(),
            'ok_msg' => '',
            'message' => '',
            'hard' => false,
            'row_errors' => array(),
        );

        if (!$this->tableExists($src)) {
            $out['ok_msg'] = $id . ': source table missing (skipped)';
            $out['skipped'] = 0;
            return $out;
        }
        if (!$this->tableExists($dst)) {
            $out['hard'] = true;
            $out['message'] = $id . ': destination table ' . $dst . ' missing — MiniShop3 schema incomplete';
            $out['unmapped'][] = array(
                'table' => $ms2,
                'column' => '*',
                'reason' => 'ms3_table_missing',
                'hard' => true,
            );
            return $out;
        }

        $srcCols = $this->columnList($src);
        $dstCols = $this->columnList($dst);
        foreach ($required as $req) {
            if (!in_array($req, $srcCols, true)) {
                $out['hard'] = true;
                $out['message'] = $id . ': required ms2 column missing: ' . $req;
                $out['unmapped'][] = array(
                    'table' => $ms2,
                    'column' => $req,
                    'reason' => 'required_missing',
                    'hard' => true,
                );
                return $out;
            }
        }
        foreach ($srcCols as $c) {
            if (!isset($cols[$c])) {
                $out['unmapped'][] = array(
                    'table' => $ms2,
                    'column' => $c,
                    'reason' => 'not_in_schema_map',
                    'hard' => false,
                );
            }
        }
        $mapped = array();
        foreach ($cols as $from => $to) {
            if (!in_array($from, $srcCols, true)) {
                continue;
            }
            if (!in_array($to, $dstCols, true)) {
                /* Soft: MS3 renamed/dropped optional columns — skip and continue with the rest. */
                $out['unmapped'][] = array(
                    'table' => $ms3,
                    'column' => $to,
                    'from' => $from,
                    'reason' => 'ms3_column_missing',
                    'hard' => false,
                );
                continue;
            }
            $mapped[$from] = $to;
        }
        if (empty($mapped)) {
            $out['hard'] = true;
            $out['message'] = $id . ': no mappable columns (MS3 schema mismatch)';
            $out['unmapped'][] = array(
                'table' => $ms2,
                'column' => '*',
                'reason' => 'no_mappable',
                'hard' => true,
            );
            return $out;
        }

        $rows = $this->fetchAll($src);
        $key = isset($spec['key']) ? $spec['key'] : 'id';
        $copied = 0;
        $skipped = 0;
        $failed = 0;
        $idMap = array();

        $dstKey = isset($mapped[$key]) ? $mapped[$key] : $key;
        foreach ($rows as $row) {
            $payload = array();
            foreach ($mapped as $from => $to) {
                $payload[$to] = $row[$from];
            }
            if ($payload) {
                $payload = $this->transformOrderRow($id, $row, $payload, $prefix, $dstCols);
            }
            if (!$payload) {
                $skipped++;
                continue;
            }
            $oldKey = isset($row[$key]) ? $row[$key] : null;
            $newKey = isset($payload[$dstKey]) ? $payload[$dstKey] : $oldKey;
            if (!$apply) {
                $copied++;
                if ($oldKey !== null) {
                    $idMap[(string)$oldKey] = (string)$newKey;
                }
                continue;
            }
            try {
                $ok = $this->upsertRow($dst, $payload, $dstKey);
                if ($ok) {
                    $copied++;
                    if ($oldKey !== null) {
                        $idMap[(string)$oldKey] = (string)$newKey;
                    }
                } else {
                    $failed++;
                    if ($failed <= 3) {
                        $out['row_errors'][] = $id . ' key=' . (string)$oldKey . ': upsert returned false';
                    }
                }
            } catch (Exception $e) {
                $failed++;
                if ($failed <= 3) {
                    $out['row_errors'][] = $id . ' key=' . (string)$oldKey . ': ' . $e->getMessage();
                }
            } catch (Throwable $e) {
                $failed++;
                if ($failed <= 3) {
                    $out['row_errors'][] = $id . ' key=' . (string)$oldKey . ': ' . $e->getMessage();
                }
            }
        }

        $srcCount = count($rows);
        $dstCount = $this->countRows($dst);
        $out['copied'] = $copied;
        $out['skipped'] = $skipped;
        $out['failed'] = $failed;
        $out['id_map'] = $idMap;
        $out['verify'] = array(
            'source_count' => $srcCount,
            'dest_count' => $dstCount,
            'copied' => $copied,
            'mode' => $apply ? 'apply' : 'dry-run',
        );
        $out['ok_msg'] = sprintf(
            '%s: %s %d/%d rows (skipped %d, failed %d)',
            $id,
            $apply ? 'copied' : 'would copy',
            $copied,
            $srcCount,
            $skipped,
            $failed
        );
        if ($failed) {
            $detail = !empty($out['row_errors']) ? ' — ' . implode('; ', $out['row_errors']) : '';
            $out['message'] = $id . ': ' . $failed . ' row failures' . $detail;
        }
        return $out;
    }

    protected function isPackageInstalled($name)
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
        $path = defined('MODX_CORE_PATH') ? MODX_CORE_PATH . 'components/' . $name . '/' : '';
        return $path !== '' && is_dir($path);
    }

    protected function tablePrefix()
    {
        if (method_exists($this->modx, 'getOption')) {
            return (string)$this->modx->getOption('table_prefix', null, 'modx_');
        }
        return 'modx_';
    }

    protected function tableExists($table)
    {
        try {
            $pdo = $this->pdo();
            if (!$pdo) {
                return false;
            }
            $stmt = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table));
            return $stmt && $stmt->fetch() !== false;
        } catch (Exception $e) {
            return false;
        } catch (Throwable $e) {
            return false;
        }
    }

    protected function columnList($table)
    {
        $cols = array();
        try {
            $pdo = $this->pdo();
            if (!$pdo) {
                return $cols;
            }
            $stmt = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
            if ($stmt) {
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    if (isset($row['Field'])) {
                        $cols[] = $row['Field'];
                    }
                }
            }
        } catch (Exception $e) {
            /* empty */
        } catch (Throwable $e) {
            /* empty */
        }
        return $cols;
    }

    protected function fetchAll($table)
    {
        try {
            $pdo = $this->pdo();
            if (!$pdo) {
                return array();
            }
            $stmt = $pdo->query('SELECT * FROM `' . str_replace('`', '``', $table) . '`');
            return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : array();
        } catch (Exception $e) {
            return array();
        } catch (Throwable $e) {
            return array();
        }
    }

    protected function countRows($table)
    {
        try {
            $pdo = $this->pdo();
            if (!$pdo) {
                return 0;
            }
            $stmt = $pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $table) . '`');
            return $stmt ? (int)$stmt->fetchColumn() : 0;
        } catch (Exception $e) {
            return 0;
        } catch (Throwable $e) {
            return 0;
        }
    }

    protected function upsertRow($table, array $payload, $keyCol)
    {
        $pdo = $this->pdo();
        if (!$pdo || !$payload) {
            return false;
        }
        $safeTable = '`' . str_replace('`', '``', $table) . '`';
        $cols = array_keys($payload);
        $placeholders = array();
        $params = array();
        foreach ($cols as $i => $c) {
            $placeholders[] = ':p' . $i;
            $params[':p' . $i] = $payload[$c];
        }
        $colSql = implode(',', array_map(function ($c) {
            return '`' . str_replace('`', '``', $c) . '`';
        }, $cols));
        $updates = array();
        foreach ($cols as $i => $c) {
            if ($c === $keyCol) {
                continue;
            }
            $updates[] = '`' . str_replace('`', '``', $c) . '` = VALUES(`' . str_replace('`', '``', $c) . '`)';
        }
        $sql = 'INSERT INTO ' . $safeTable . ' (' . $colSql . ') VALUES (' . implode(',', $placeholders) . ')';
        if ($updates) {
            $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(',', $updates);
        }
        $stmt = $pdo->prepare($sql);
        return $stmt && $stmt->execute($params);
    }

    /** @var array|null */
    protected $orderCtx = null;

    /** @var array|null MS2 order ids to (re)write during repair; null = all */
    protected $orderOnlyIds = null;

    /**
     * MS3 order tables differ from MS2 beyond column names: unique `uuid` (empty uuid made every
     * order upsert into one row), required `product_key`, addresses linked by `order_id`, and status
     * ids shifted because MS3 reserves one status for the draft (= live cart).
     *
     * @return array|null payload, or null to skip the row
     */
    protected function transformOrderRow($specId, array $row, array $payload, $prefix, array $dstCols)
    {
        if (!in_array($specId, array('status', 'order', 'order_product', 'order_address'), true)) {
            return $payload;
        }
        $ctx = $this->orderContext($prefix);
        $only = $this->orderOnlyIds;
        switch ($specId) {
            case 'status':
                $old = (int)$row['id'];
                if (isset($ctx['status_map'][$old])) {
                    $payload['id'] = $ctx['status_map'][$old];
                }
                return $payload;
            case 'order':
                $oid = (int)$row['id'];
                if ($only !== null && !isset($only[$oid])) {
                    return null;
                }
                $old = isset($row['status']) ? (int)$row['status'] : 0;
                if (array_key_exists('status_id', $payload) && isset($ctx['status_map'][$old])) {
                    $payload['status_id'] = $ctx['status_map'][$old];
                }
                if (in_array('uuid', $dstCols, true)) {
                    $payload['uuid'] = $this->orderUuid($oid);
                }
                if (in_array('token', $dstCols, true) && !isset($payload['token'])) {
                    $payload['token'] = '';
                }
                return $payload;
            case 'order_product':
                $oid = (int)$row['order_id'];
                if ($ctx['order_ids'] && !isset($ctx['order_ids'][$oid])) {
                    return null;
                }
                if ($only !== null && !isset($only[$oid])) {
                    return null;
                }
                if (in_array('product_key', $dstCols, true) && empty($payload['product_key'])) {
                    $opts = isset($row['options']) ? (string)$row['options'] : '';
                    $payload['product_key'] = 'ms' . md5((int)$row['product_id'] . $opts);
                }
                return $payload;
            case 'order_address':
                if (!in_array('order_id', $dstCols, true) || !$ctx['address_link']) {
                    return $payload;
                }
                $aid = (int)$row['id'];
                if (!isset($ctx['address_order'][$aid])) {
                    return null;
                }
                $oid = $ctx['address_order'][$aid];
                if ($only !== null && !isset($only[$oid])) {
                    return null;
                }
                $payload['order_id'] = $oid;
                return $payload;
        }
        return $payload;
    }

    /**
     * @param string $prefix
     * @return array
     */
    protected function orderContext($prefix)
    {
        if ($this->orderCtx !== null) {
            return $this->orderCtx;
        }
        $ctx = array(
            'status_map' => array(),
            'ms3_system' => array(),
            'order_ids' => array(),
            'address_order' => array(),
            'address_link' => false,
        );
        $modx = $this->modx;
        $opt = function ($key, $default) use ($modx) {
            $v = (is_object($modx) && method_exists($modx, 'getOption')) ? $modx->getOption($key, null, null) : null;
            return ($v === null || $v === '') ? $default : (int)$v;
        };
        $sys = array(
            'draft' => $opt('ms3_status_draft', 1),
            'new' => $opt('ms3_status_new', 2),
            'paid' => $opt('ms3_status_paid', 3),
            'sent' => $opt('ms3_status_sent', 4),
            'canceled' => $opt('ms3_status_canceled', 5),
        );
        $ms2 = array(
            'new' => $opt('ms2_status_new', 1),
            'paid' => $opt('ms2_status_paid', 2),
            'sent' => $opt('ms2_status_send', 3),
            'canceled' => $opt('ms2_status_canceled', 4),
        );
        $ctx['ms3_system'] = array_values(array_unique(array_values($sys)));
        $semantic = array_flip($ms2);
        $pdo = $this->pdo();
        $st2 = $prefix . 'ms2_order_statuses';
        if ($pdo && $this->tableExists($st2)) {
            try {
                $ids = array_map('intval', $pdo->query('SELECT `id` FROM ' . $this->qt($st2) . ' ORDER BY `id`')->fetchAll(PDO::FETCH_COLUMN));
                /* Deterministic id for custom MS2 statuses that sit on an MS3 system id */
                $base = max(100, ($ids ? max($ids) : 0) + 1);
                foreach ($ids as $sid) {
                    if (isset($semantic[$sid])) {
                        $ctx['status_map'][$sid] = $sys[$semantic[$sid]];
                    } elseif (in_array($sid, $ctx['ms3_system'], true)) {
                        $ctx['status_map'][$sid] = $base + $sid;
                    } else {
                        $ctx['status_map'][$sid] = $sid;
                    }
                }
            } catch (Exception $e) {
            } catch (Throwable $e) {
            }
        }
        $o2 = $prefix . 'ms2_orders';
        if ($pdo && $this->tableExists($o2)) {
            try {
                $sel = in_array('address', $this->columnList($o2), true) ? '`id`,`address`' : '`id`';
                foreach ($pdo->query('SELECT ' . $sel . ' FROM ' . $this->qt($o2))->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $ctx['order_ids'][(int)$r['id']] = true;
                    if (!empty($r['address'])) {
                        $ctx['address_order'][(int)$r['address']] = (int)$r['id'];
                        $ctx['address_link'] = true;
                    }
                }
            } catch (Exception $e) {
            } catch (Throwable $e) {
            }
        }
        $this->orderCtx = $ctx;
        return $ctx;
    }

    /**
     * Stable per MS2 order id so re-runs upsert the same MS3 row.
     *
     * @param int $id
     * @return string
     */
    protected function orderUuid($id)
    {
        $h = md5('migratemodx:ms2-order:' . (int)$id);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-4' . substr($h, 13, 3) . '-'
            . dechex(8 | (hexdec($h[16]) & 3)) . substr($h, 17, 3) . '-' . substr($h, 20, 12);
    }

    protected function qt($table)
    {
        return '`' . str_replace('`', '``', $table) . '`';
    }

    /**
     * MS3 tables carrying order_id => MS2 twin table (rows that came from MS2 stay with the MS2 order).
     *
     * @return array<string,string>
     */
    protected function orderRefTables($prefix)
    {
        $out = array();
        $pdo = $this->pdo();
        if (!$pdo) {
            return $out;
        }
        try {
            $stmt = $pdo->prepare(
                'SELECT DISTINCT `TABLE_NAME` FROM information_schema.COLUMNS'
                . ' WHERE `TABLE_SCHEMA` = DATABASE() AND `COLUMN_NAME` = \'order_id\' AND `TABLE_NAME` LIKE ?'
            );
            $stmt->execute(array(str_replace('_', '\\_', $prefix . 'ms3_') . '%'));
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $t) {
                $twin = '';
                if ($t === $prefix . 'ms3_order_products') {
                    $twin = $prefix . 'ms2_order_products';
                } elseif ($t === $prefix . 'ms3_order_addresses') {
                    $twin = $prefix . 'ms2_order_addresses';
                }
                $out[$t] = $twin;
            }
        } catch (Exception $e) {
        } catch (Throwable $e) {
        }
        return $out;
    }

    protected function nextOrderId($prefix)
    {
        $pdo = $this->pdo();
        $max = 0;
        $sources = array($prefix . 'ms3_orders' => 'id', $prefix . 'ms2_orders' => 'id');
        foreach (array_keys($this->orderRefTables($prefix)) as $t) {
            $sources[$t] = 'order_id';
        }
        foreach ($sources as $t => $col) {
            if (!$this->tableExists($t)) {
                continue;
            }
            try {
                $max = max($max, (int)$pdo->query('SELECT MAX(`' . $col . '`) FROM ' . $this->qt($t))->fetchColumn());
            } catch (Exception $e) {
            } catch (Throwable $e) {
            }
        }
        return $max + 1;
    }

    /**
     * Move MS3-native orders (drafts = live carts, or real orders placed after install) off ids
     * that MS2 orders are about to take, together with their own lines/addresses/logs.
     */
    protected function prepareOrderTables($prefix)
    {
        $out = array('relocated' => array(), 'errors' => array());
        $o2 = $prefix . 'ms2_orders';
        $o3 = $prefix . 'ms3_orders';
        $pdo = $this->pdo();
        if (!$pdo || !$this->tableExists($o2) || !$this->tableExists($o3)) {
            return $out;
        }
        $cols3 = $this->columnList($o3);
        if (!in_array('token', $cols3, true)) {
            return $out;
        }
        try {
            $ms2Ids = array_map('intval', $pdo->query('SELECT `id` FROM ' . $this->qt($o2))->fetchAll(PDO::FETCH_COLUMN));
            if (!$ms2Ids) {
                return $out;
            }
            $hasUuid = in_array('uuid', $cols3, true);
            $rows = $pdo->query(
                'SELECT `id`' . ($hasUuid ? ',`uuid`' : '') . ' FROM ' . $this->qt($o3)
                . ' WHERE `id` IN (' . implode(',', $ms2Ids) . ') AND `token` <> \'\''
            )->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) {
                return $out;
            }
            $next = $this->nextOrderId($prefix);
            $refs = $this->orderRefTables($prefix);
            foreach ($rows as $r) {
                $old = (int)$r['id'];
                if ($hasUuid && (string)$r['uuid'] === $this->orderUuid($old)) {
                    continue;
                }
                $new = $next++;
                $pdo->beginTransaction();
                try {
                    $pdo->prepare('UPDATE ' . $this->qt($o3) . ' SET `id` = ? WHERE `id` = ?')->execute(array($new, $old));
                    foreach ($refs as $t => $twin) {
                        $sql = 'UPDATE ' . $this->qt($t) . ' SET `order_id` = ? WHERE `order_id` = ?';
                        if ($twin !== '' && $this->tableExists($twin)) {
                            $sql .= ' AND `id` NOT IN (SELECT `id` FROM ' . $this->qt($twin) . ')';
                        }
                        $pdo->prepare($sql)->execute(array($new, $old));
                    }
                    $pdo->commit();
                    $out['relocated'][] = array('from' => $old, 'to' => $new);
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $out['errors'][] = 'order ' . $old . ': ' . $e->getMessage();
                }
            }
        } catch (Exception $e) {
            $out['errors'][] = $e->getMessage();
        } catch (Throwable $e) {
            $out['errors'][] = $e->getMessage();
        }
        return $out;
    }

    /**
     * Drop order lines/addresses without an order (a fresh MS3 draft would inherit them),
     * restore the MS3 draft status if MS2 statuses were written over it, bump AUTO_INCREMENT.
     */
    protected function finalizeOrderTables($prefix)
    {
        $out = array(
            'orphan_products' => 0,
            'orphan_addresses' => 0,
            'draft_status_restored' => false,
            'auto_increment' => null,
            'errors' => array(),
        );
        $o3 = $prefix . 'ms3_orders';
        $pdo = $this->pdo();
        if (!$pdo || !$this->tableExists($o3)) {
            return $out;
        }
        try {
            foreach (array('ms3_order_products' => 'orphan_products', 'ms3_order_addresses' => 'orphan_addresses') as $t => $k) {
                if ($this->tableExists($prefix . $t)) {
                    $out[$k] = (int)$pdo->exec(
                        'DELETE x FROM ' . $this->qt($prefix . $t) . ' x LEFT JOIN ' . $this->qt($o3)
                        . ' o ON o.`id` = x.`order_id` WHERE o.`id` IS NULL'
                    );
                }
            }
            $out['draft_status_restored'] = $this->restoreMs3DraftStatus($prefix);
            $next = $this->nextOrderId($prefix);
            $pdo->exec('ALTER TABLE ' . $this->qt($o3) . ' AUTO_INCREMENT = ' . (int)$next);
            $out['auto_increment'] = $next;
        } catch (Exception $e) {
            $out['errors'][] = $e->getMessage();
        } catch (Throwable $e) {
            $out['errors'][] = $e->getMessage();
        }
        return $out;
    }

    /**
     * @return bool true when the draft row was rewritten
     */
    protected function restoreMs3DraftStatus($prefix)
    {
        $s3 = $prefix . 'ms3_order_statuses';
        $s2 = $prefix . 'ms2_order_statuses';
        $pdo = $this->pdo();
        if (!$pdo || !$this->tableExists($s3) || !$this->tableExists($s2)) {
            return false;
        }
        $draft = (int)$this->modx->getOption('ms3_status_draft', null, 1);
        if ($draft <= 0) {
            $draft = 1;
        }
        $stmt = $pdo->prepare('SELECT `name` FROM ' . $this->qt($s3) . ' WHERE `id` = ?');
        $stmt->execute(array($draft));
        $name = $stmt->fetchColumn();
        $ms2Names = $pdo->query('SELECT `name` FROM ' . $this->qt($s2))->fetchAll(PDO::FETCH_COLUMN);
        if ($name !== false && ($name === 'ms3_order_status_draft' || !in_array($name, $ms2Names, true))) {
            return false;
        }
        $row = array(
            'id' => $draft,
            'name' => 'ms3_order_status_draft',
            'color' => 'C0C0C0',
            'final' => 0,
            'fixed' => 0,
            'editable' => 0,
            'active' => 1,
            'position' => 0,
        );
        $cols = $this->columnList($s3);
        foreach (array_keys($row) as $c) {
            if (!in_array($c, $cols, true)) {
                unset($row[$c]);
            }
        }
        return $this->upsertRow($s3, $row, 'id');
    }

    /**
     * Re-migrate MS2 orders that the pre-1.3.30 migrator collapsed into one row
     * (empty unique uuid) or mapped onto the MS3 draft status. Leaves correct rows untouched.
     */
    public function repairOrders()
    {
        $prefix = $this->tablePrefix();
        $out = array('prepare' => null, 'broken_orders' => 0, 'tables' => array(), 'finalize' => null);
        $o2 = $prefix . 'ms2_orders';
        $o3 = $prefix . 'ms3_orders';
        $pdo = $this->pdo();
        if (!$pdo || !$this->tableExists($o3)) {
            return $out;
        }
        $out['prepare'] = $this->prepareOrderTables($prefix);
        if ($this->tableExists($o2)) {
            try {
                $broken = array();
                $hasUuid = in_array('uuid', $this->columnList($o3), true);
                $have = array();
                if ($hasUuid) {
                    foreach ($pdo->query('SELECT `id`,`uuid` FROM ' . $this->qt($o3))->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        $have[(int)$r['id']] = (string)$r['uuid'];
                    }
                }
                foreach ($pdo->query('SELECT `id` FROM ' . $this->qt($o2))->fetchAll(PDO::FETCH_COLUMN) as $id) {
                    $id = (int)$id;
                    if (!$hasUuid || !isset($have[$id]) || $have[$id] !== $this->orderUuid($id)) {
                        $broken[$id] = true;
                    }
                }
                $out['broken_orders'] = count($broken);
                $statusBroken = $this->ms3StatusesOverwritten($prefix);
                if ($statusBroken) {
                    $spec = $this->schemaSpec('status');
                    if ($spec) {
                        $out['tables']['status'] = $this->migrateTable($spec, $prefix, true);
                    }
                }
                if ($broken) {
                    /* Collapsed rows hold another order's unique num — free it before upserting */
                    if (in_array('num', $this->columnList($o3), true)) {
                        $pdo->exec(
                            'UPDATE ' . $this->qt($o3) . ' SET `num` = NULL WHERE `id` IN ('
                            . implode(',', array_map('intval', array_keys($broken))) . ')'
                        );
                    }
                    $this->orderOnlyIds = $broken;
                    foreach (array('order', 'order_product', 'order_address') as $tid) {
                        $spec = $this->schemaSpec($tid);
                        if ($spec) {
                            $out['tables'][$tid] = $this->migrateTable($spec, $prefix, true);
                        }
                    }
                    $this->orderOnlyIds = null;
                }
            } catch (Exception $e) {
                $this->orderOnlyIds = null;
                $out['error'] = $e->getMessage();
            } catch (Throwable $e) {
                $this->orderOnlyIds = null;
                $out['error'] = $e->getMessage();
            }
        }
        $out['finalize'] = $this->finalizeOrderTables($prefix);
        return $out;
    }

    /**
     * Old migrator copied MS2 status rows by id over MS3 system rows (draft/new/paid/...).
     */
    protected function ms3StatusesOverwritten($prefix)
    {
        $s3 = $prefix . 'ms3_order_statuses';
        $s2 = $prefix . 'ms2_order_statuses';
        $pdo = $this->pdo();
        if (!$pdo || !$this->tableExists($s3) || !$this->tableExists($s2)) {
            return false;
        }
        $draft = (int)$this->modx->getOption('ms3_status_draft', null, 1);
        $stmt = $pdo->prepare('SELECT `name` FROM ' . $this->qt($s3) . ' WHERE `id` = ?');
        $stmt->execute(array($draft > 0 ? $draft : 1));
        $name = $stmt->fetchColumn();
        if ($name === false) {
            return true;
        }
        $ms2Names = $pdo->query('SELECT `name` FROM ' . $this->qt($s2))->fetchAll(PDO::FETCH_COLUMN);
        return $name !== 'ms3_order_status_draft' && in_array($name, $ms2Names, true);
    }

    protected function schemaSpec($id)
    {
        foreach ((array)(isset($this->schema['tables']) ? $this->schema['tables'] : array()) as $t) {
            if (isset($t['id']) && $t['id'] === $id) {
                return $t;
            }
        }
        return null;
    }

    protected function pdo()
    {
        if (isset($this->modx->pdo) && $this->modx->pdo instanceof PDO) {
            return $this->modx->pdo;
        }
        try {
            if (method_exists($this->modx, 'getConnection')) {
                $conn = $this->modx->getConnection();
                if ($conn && isset($conn->pdo) && $conn->pdo instanceof PDO) {
                    return $conn->pdo;
                }
            }
        } catch (Exception $e) {
            return null;
        } catch (Throwable $e) {
            return null;
        }
        return null;
    }

    /**
     * Inventory of catalog resources + ms2/ms3 product data rows (for verify / repair).
     *
     * @return array
     */
    public function inventoryCatalog()
    {
        $prefix = $this->tablePrefix();
        $content = $prefix . 'site_content';
        $inv = array(
            'products_ms2_key' => 0,
            'products_ms3_key' => 0,
            'categories_ms2_key' => 0,
            'categories_ms3_key' => 0,
            'ms2_products_rows' => 0,
            'ms3_products_rows' => 0,
            'orphan_ms3_data' => 0,
            'products_missing_ms3_data' => 0,
            'sample_missing_ids' => array(),
        );
        $pdo = $this->pdo();
        if (!$pdo || !$this->tableExists($content)) {
            return $inv;
        }
        $safe = '`' . str_replace('`', '``', $content) . '`';
        $counts = array(
            'products_ms2_key' => 'msProduct',
            'products_ms3_key' => 'MiniShop3\\Model\\msProduct',
            'categories_ms2_key' => 'msCategory',
            'categories_ms3_key' => 'MiniShop3\\Model\\msCategory',
        );
        foreach ($counts as $k => $ck) {
            try {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM ' . $safe . ' WHERE `class_key` = ? AND `deleted` = 0');
                $stmt->execute(array($ck));
                $inv[$k] = (int)$stmt->fetchColumn();
            } catch (Exception $e) {
                /* ignore */
            } catch (Throwable $e) {
                /* ignore */
            }
        }
        if ($this->tableExists($prefix . 'ms2_products')) {
            $inv['ms2_products_rows'] = $this->countRows($prefix . 'ms2_products');
        }
        if ($this->tableExists($prefix . 'ms3_products')) {
            $inv['ms3_products_rows'] = $this->countRows($prefix . 'ms3_products');
            try {
                $sql = 'SELECT COUNT(*) FROM `' . str_replace('`', '``', $prefix . 'ms3_products') . '` p'
                    . ' LEFT JOIN ' . $safe . ' c ON c.`id` = p.`id`'
                    . ' WHERE c.`id` IS NULL';
                $inv['orphan_ms3_data'] = (int)$pdo->query($sql)->fetchColumn();
                $sql2 = 'SELECT c.`id` FROM ' . $safe . ' c'
                    . ' LEFT JOIN `' . str_replace('`', '``', $prefix . 'ms3_products') . '` p ON p.`id` = c.`id`'
                    . ' WHERE c.`class_key` IN (?, ?) AND c.`deleted` = 0 AND p.`id` IS NULL'
                    . ' LIMIT 20';
                $stmt = $pdo->prepare($sql2);
                $stmt->execute(array('msProduct', 'MiniShop3\\Model\\msProduct'));
                $missing = $stmt->fetchAll(PDO::FETCH_COLUMN);
                $inv['sample_missing_ids'] = array_map('intval', $missing ? $missing : array());
                $inv['products_missing_ms3_data'] = count($inv['sample_missing_ids']);
                /* full count */
                $stmt = $pdo->prepare(
                    'SELECT COUNT(*) FROM ' . $safe . ' c'
                    . ' LEFT JOIN `' . str_replace('`', '``', $prefix . 'ms3_products') . '` p ON p.`id` = c.`id`'
                    . ' WHERE c.`class_key` IN (?, ?) AND c.`deleted` = 0 AND p.`id` IS NULL'
                );
                $stmt->execute(array('msProduct', 'MiniShop3\\Model\\msProduct'));
                $inv['products_missing_ms3_data'] = (int)$stmt->fetchColumn();
            } catch (Exception $e) {
                /* ignore */
            } catch (Throwable $e) {
                /* ignore */
            }
        }
        return $inv;
    }

    /**
     * Convert catalog resources to MiniShop3 class_keys without deleting pages.
     * Must run while site_content rows still exist (never use Package Management uninstall of MS2 for this).
     *
     * @return array
     */
    public function convertCatalogClassKeys()
    {
        $out = array(
            'ok' => false,
            'updated' => array(),
            'before' => $this->inventoryCatalog(),
            'errors' => array(),
        );
        $pdo = $this->pdo();
        $prefix = $this->tablePrefix();
        $table = $prefix . 'site_content';
        if (!$pdo || !$this->tableExists($table)) {
            $out['errors'][] = 'site_content not reachable';
            return $out;
        }
        $safe = '`' . str_replace('`', '``', $table) . '`';
        $map = array(
            'msProduct' => 'MiniShop3\\Model\\msProduct',
            'msCategory' => 'MiniShop3\\Model\\msCategory',
        );
        foreach ($map as $from => $to) {
            try {
                $stmt = $pdo->prepare('UPDATE ' . $safe . ' SET `class_key` = ? WHERE `class_key` = ?');
                $stmt->execute(array($to, $from));
                $out['updated'][$from] = array(
                    'to' => $to,
                    'rows' => (int)$stmt->rowCount(),
                );
            } catch (Exception $e) {
                $out['errors'][] = 'class_key ' . $from . ': ' . $e->getMessage();
            } catch (Throwable $e) {
                $out['errors'][] = 'class_key ' . $from . ': ' . $e->getMessage();
            }
        }
        /* Zoo themes often keep category folders as modDocument — MS3 scope only walks msCategory */
        $folders = $this->convertDocumentShopFolders();
        $out['updated']['modDocument_shop_folders'] = $folders;
        if (!empty($folders['errors'])) {
            $out['errors'] = array_merge($out['errors'], $folders['errors']);
        }
        /* Undelete soft-deleted catalog if still keyed as MS2/MS3 shop classes — leave deleted flag alone;
         * only report. */
        $out['after'] = $this->inventoryCatalog();
        $out['ok'] = empty($out['errors']);
        return $out;
    }

    /**
     * Promote modDocument folders that hold products into MiniShop3 msCategory.
     * Without this, [[msProducts parents=`catalog`]] sees an empty tree (MS3 scope ignores modDocument).
     *
     * @return array{rows:int,errors:string[]}
     */
    public function convertDocumentShopFolders()
    {
        $out = array('rows' => 0, 'errors' => array(), 'ids' => array());
        $pdo = $this->pdo();
        $prefix = $this->tablePrefix();
        $table = $prefix . 'site_content';
        if (!$pdo || !$this->tableExists($table)) {
            $out['errors'][] = 'site_content missing';
            return $out;
        }
        $safe = '`' . str_replace('`', '``', $table) . '`';
        $msProduct = 'MiniShop3\\Model\\msProduct';
        $msCategory = 'MiniShop3\\Model\\msCategory';
        $docs = array('modDocument', 'MODX\\Revolution\\modDocument');
        try {
            /* Direct parents of products */
            $sql = 'SELECT DISTINCT p.`id` FROM ' . $safe . ' p'
                . ' INNER JOIN ' . $safe . ' c ON c.`parent` = p.`id`'
                . ' WHERE p.`deleted` = 0 AND c.`deleted` = 0'
                . ' AND c.`class_key` IN (?, ?)'
                . ' AND p.`class_key` IN (?, ?)';
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array('msProduct', $msProduct, $docs[0], $docs[1]));
            $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            /* Also any modDocument under an msCategory catalog root that itself has product descendants */
            $sql2 = 'SELECT DISTINCT p.`id` FROM ' . $safe . ' p'
                . ' INNER JOIN ' . $safe . ' root ON root.`id` = p.`parent`'
                . ' WHERE p.`deleted` = 0'
                . ' AND p.`class_key` IN (?, ?)'
                . ' AND root.`class_key` IN (?, ?)'
                . ' AND EXISTS ('
                . '   SELECT 1 FROM ' . $safe . ' c WHERE c.`parent` = p.`id` AND c.`deleted` = 0'
                . '   AND c.`class_key` IN (?, ?)'
                . ' )';
            $stmt2 = $pdo->prepare($sql2);
            $stmt2->execute(array(
                $docs[0], $docs[1],
                'msCategory', $msCategory,
                'msProduct', $msProduct,
            ));
            $ids = array_values(array_unique(array_merge($ids, array_map('intval', $stmt2->fetchAll(PDO::FETCH_COLUMN)))));
            $ids = array_filter($ids);
            if (!$ids) {
                return $out;
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $upd = $pdo->prepare(
                'UPDATE ' . $safe . ' SET `class_key` = ? WHERE `id` IN (' . $placeholders . ') AND `class_key` IN (?, ?)'
            );
            $params = array_merge(array($msCategory), $ids, array($docs[0], $docs[1]));
            $upd->execute($params);
            $out['rows'] = (int)$upd->rowCount();
            $out['ids'] = $ids;
        } catch (Exception $e) {
            $out['errors'][] = $e->getMessage();
        } catch (Throwable $e) {
            $out['errors'][] = $e->getMessage();
        }
        return $out;
    }

    /**
     * Package Management uninstall of miniShop2 often soft-deletes shop resources.
     * MS3 cart then returns «product not found». Undelete MS3/MS2 shop class_keys.
     *
     * @return array{ok:bool,updated:int,errors:string[]}
     */
    public function undeleteShopResources()
    {
        $out = array('ok' => false, 'updated' => 0, 'errors' => array());
        $pdo = $this->pdo();
        $prefix = $this->tablePrefix();
        $table = $prefix . 'site_content';
        if (!$pdo || !$this->tableExists($table)) {
            $out['errors'][] = 'site_content missing';
            return $out;
        }
        $safe = '`' . str_replace('`', '``', $table) . '`';
        $keys = array(
            'msProduct',
            'msCategory',
            'MiniShop3\\Model\\msProduct',
            'MiniShop3\\Model\\msCategory',
        );
        try {
            $placeholders = implode(',', array_fill(0, count($keys), '?'));
            /* Soft-deleted by PM uninstall — restore and republish so MS3 cart can resolve them */
            $sql = 'UPDATE ' . $safe . ' SET `deleted` = 0, `published` = 1'
                . ' WHERE `deleted` = 1 AND `class_key` IN (' . $placeholders . ')';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($keys);
            $out['updated'] = (int)$stmt->rowCount();
            /* Products belong in catalog listings, not pdoMenu — keep nav categories-only */
            $prodKeys = array('msProduct', 'MiniShop3\\Model\\msProduct');
            $ph2 = implode(',', array_fill(0, count($prodKeys), '?'));
            $sqlHide = 'UPDATE ' . $safe . ' SET `hidemenu` = 1'
                . ' WHERE `class_key` IN (' . $ph2 . ') AND `hidemenu` = 0 AND `deleted` = 0';
            $stmt2 = $pdo->prepare($sqlHide);
            $stmt2->execute($prodKeys);
            $out['hidemenu_products'] = (int)$stmt2->rowCount();
            $out['ok'] = true;
        } catch (Exception $e) {
            $out['errors'][] = $e->getMessage();
        } catch (Throwable $e) {
            $out['errors'][] = $e->getMessage();
        }
        return $out;
    }

    /**
     * Force-copy stock (and related flags) from ms2_products → ms3_products.
     * Older schema only mapped count→stock; sites with an MS2 `stock` column stayed at 0.
     *
     * @return array{ok:bool,updated:int,errors:string[]}
     */
    public function syncMs3StockFromMs2()
    {
        $out = array('ok' => false, 'updated' => 0, 'errors' => array());
        $pdo = $this->pdo();
        $prefix = $this->tablePrefix();
        $ms2 = $prefix . 'ms2_products';
        $ms3 = $prefix . 'ms3_products';
        if (!$pdo || !$this->tableExists($ms2) || !$this->tableExists($ms3)) {
            $out['ok'] = true;
            $out['errors'][] = 'ms2/ms3 products table missing — skipped';
            return $out;
        }
        $srcCols = $this->columnList($ms2);
        $dstCols = $this->columnList($ms3);
        if (!in_array('stock', $dstCols, true)) {
            $out['errors'][] = 'ms3_products.stock missing';
            return $out;
        }
        $stockExpr = in_array('stock', $srcCols, true)
            ? 'a.`stock`'
            : (in_array('count', $srcCols, true) ? 'a.`count`' : null);
        if ($stockExpr === null) {
            $out['errors'][] = 'ms2_products has neither stock nor count';
            return $out;
        }
        $sets = array('b.`stock` = ' . $stockExpr);
        foreach (array('image', 'thumb', 'new', 'popular', 'favorite', 'price', 'old_price', 'article') as $col) {
            if (in_array($col, $srcCols, true) && in_array($col, $dstCols, true)) {
                $sets[] = 'b.`' . $col . '` = a.`' . $col . '`';
            }
        }
        if (in_array('vendor', $srcCols, true) && in_array('vendor_id', $dstCols, true)) {
            $sets[] = 'b.`vendor_id` = a.`vendor`';
        }
        $sql = 'UPDATE `' . str_replace('`', '``', $ms3) . '` b'
            . ' INNER JOIN `' . str_replace('`', '``', $ms2) . '` a ON a.`id` = b.`id`'
            . ' SET ' . implode(', ', $sets);
        try {
            $n = $pdo->exec($sql);
            $out['updated'] = ($n === false) ? 0 : (int)$n;
            $out['ok'] = true;
        } catch (Exception $e) {
            $out['errors'][] = $e->getMessage();
        } catch (Throwable $e) {
            $out['errors'][] = $e->getMessage();
        }
        return $out;
    }

    /**
     * Ensure every product resource has an ms3_products row (copy from ms2_products when possible).
     *
     * @return array
     */
    public function ensureMs3ProductData()
    {
        $out = array(
            'ok' => false,
            'created' => 0,
            'updated' => 0,
            'errors' => array(),
        );
        $pdo = $this->pdo();
        $prefix = $this->tablePrefix();
        $content = $prefix . 'site_content';
        $ms3 = $prefix . 'ms3_products';
        $ms2 = $prefix . 'ms2_products';
        if (!$pdo || !$this->tableExists($content) || !$this->tableExists($ms3)) {
            $out['errors'][] = 'site_content or ms3_products missing';
            return $out;
        }
        $safeC = '`' . str_replace('`', '``', $content) . '`';
        $safe3 = '`' . str_replace('`', '``', $ms3) . '`';
        try {
            $stmt = $pdo->prepare(
                'SELECT c.`id` FROM ' . $safeC . ' c'
                . ' LEFT JOIN ' . $safe3 . ' p ON p.`id` = c.`id`'
                . ' WHERE c.`deleted` = 0 AND c.`class_key` IN (?, ?) AND p.`id` IS NULL'
            );
            $stmt->execute(array('msProduct', 'MiniShop3\\Model\\msProduct'));
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            $out['errors'][] = $e->getMessage();
            return $out;
        } catch (Throwable $e) {
            $out['errors'][] = $e->getMessage();
            return $out;
        }
        $dstCols = $this->columnList($ms3);
        $srcCols = $this->tableExists($ms2) ? $this->columnList($ms2) : array();
        $colMap = array(
            'id' => 'id',
            'article' => 'article',
            'price' => 'price',
            'old_price' => 'old_price',
            'weight' => 'weight',
            'image' => 'image',
            'thumb' => 'thumb',
            'vendor' => 'vendor_id',
            'made_in' => 'made_in',
            'new' => 'new',
            'popular' => 'popular',
            'favorite' => 'favorite',
            'tags' => 'tags',
            'color' => 'color',
            'size' => 'size',
            'source' => 'source_id',
            'count' => 'stock',
            'stock' => 'stock',
        );
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id <= 0) {
                continue;
            }
            $payload = array('id' => $id);
            if ($srcCols) {
                try {
                    $q = $pdo->prepare('SELECT * FROM `' . str_replace('`', '``', $ms2) . '` WHERE `id` = ? LIMIT 1');
                    $q->execute(array($id));
                    $row = $q->fetch(PDO::FETCH_ASSOC);
                } catch (Exception $e) {
                    $row = false;
                } catch (Throwable $e) {
                    $row = false;
                }
                if (is_array($row)) {
                    foreach ($colMap as $from => $to) {
                        if ($from === 'id') {
                            continue;
                        }
                        if (!array_key_exists($from, $row) || !in_array($to, $dstCols, true)) {
                            continue;
                        }
                        $payload[$to] = $row[$from];
                    }
                }
            }
            if (!isset($payload['price']) && in_array('price', $dstCols, true)) {
                $payload['price'] = 0;
            }
            if ($this->upsertRow($ms3, $payload, 'id')) {
                $out['created']++;
            } else {
                $out['errors'][] = 'upsert failed for product id ' . $id;
            }
        }
        $out['ok'] = empty($out['errors']);
        return $out;
    }

    /**
     * Full catalog repair after a bad MS2 uninstall / partial migrate.
     * Does not delete resources. Converts class_keys, fills ms3_products, clears cache.
     *
     * @param string $siteRoot
     * @param string $dataDir
     * @return array
     */
    public function repairCatalog($siteRoot, $dataDir)
    {
        $out = array(
            'ok' => false,
            'inventory_before' => $this->inventoryCatalog(),
            'convert' => null,
            'ensure_data' => null,
            'tables' => null,
            'cache' => null,
            'inventory_after' => null,
            'errors' => array(),
            'advice' => array(),
        );
        if (!$this->isPackageInstalled('minishop3')) {
            $out['errors'][] = $this->lex('msg_ms3_not_installed', 'MiniShop3 not installed');
            $out['advice'][] = 'Install MiniShop3, then run repair again.';
            return $out;
        }
        $out['convert'] = $this->convertCatalogClassKeys();
        /* Re-copy product_data + files if ms2_* still present (stock/parent_id often missed on first pass). */
        $prefix = $this->tablePrefix();
        $out['tables'] = array();
        foreach (array('product_data', 'product_file') as $tid) {
            if ($tid === 'product_data'
                && !($this->tableExists($prefix . 'ms2_products') && $this->tableExists($prefix . 'ms3_products'))
            ) {
                continue;
            }
            if ($tid === 'product_file'
                && !($this->tableExists($prefix . 'ms2_product_files') && $this->tableExists($prefix . 'ms3_product_files'))
            ) {
                continue;
            }
            $spec = null;
            foreach ((array)$this->schema['tables'] as $t) {
                if (isset($t['id']) && $t['id'] === $tid) {
                    $spec = $t;
                    break;
                }
            }
            if ($spec) {
                $out['tables'][$tid] = $this->migrateTable($spec, $prefix, true);
            }
        }
        $out['orders'] = $this->repairOrders();
        $out['ensure_data'] = $this->ensureMs3ProductData();
        $out['stock_sync'] = $this->syncMs3StockFromMs2();
        $out['undelete'] = $this->undeleteShopResources();
        if (!class_exists('MmxVendorCompat', false)) {
            require_once dirname(__FILE__) . '/mmxvendorcompat.class.php';
        }
        $vc = new MmxVendorCompat($this->modx, $siteRoot, $this->storePath, $dataDir);
        $out['cache'] = $vc->clearModxCache();
        $out['inventory_after'] = $this->inventoryCatalog();

        $after = $out['inventory_after'];
        if (!empty($after['products_ms2_key']) || !empty($after['categories_ms2_key'])) {
            $out['advice'][] = 'Some resources still have MS2 class_key — convert did not catch them.';
        }
        if (empty($after['products_ms3_key']) && empty($after['products_ms2_key'])
            && !empty($after['ms3_products_rows'])
        ) {
            $out['advice'][] = 'ms3_products has rows but no product resources — Package Management uninstall likely deleted site_content. Restore a MigrateMODX snapshot (pre-uninstall), then run Retire miniShop2 (not PM uninstall).';
        }
        if (empty($after['products_ms3_key']) && empty($after['ms3_products_rows']) && empty($after['ms2_products_rows'])) {
            $out['advice'][] = 'No catalog rows found — restore from snapshot / DB backup.';
        }
        if (!empty($after['products_ms3_key']) && !empty($after['products_missing_ms3_data'])) {
            $out['advice'][] = 'Products exist without ms3_products data — ensure_data should have filled them; re-check.';
        }

        $out['ok'] = empty($out['errors'])
            && (empty($out['convert']['errors']))
            && (!empty($after['products_ms3_key']) || !empty($after['categories_ms3_key']));
        @file_put_contents(
            $this->storePath . 'ms3-catalog-repair.json',
            json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
        return $out;
    }

    /**
     * Retire miniShop2 after MS3 data migrate so the front no longer Fatal's on MODX 2 paths.
     * Converts class_keys first (keeps pages), then quarantines MS2 folders — never deletes site_content.
     *
     * @param string $siteRoot
     * @param string $dataDir MigrateMODX data/ (for VendorCompat)
     * @return array
     */
    public function retireMiniShop2($siteRoot, $dataDir)
    {
        $out = array(
            'ok' => false,
            'inventory_before' => $this->inventoryCatalog(),
            'class_keys' => array(),
            'ensure_data' => null,
            'quarantine' => null,
            'cache' => null,
            'inventory_after' => null,
            'errors' => array(),
            'warning' => '',
        );
        if (!$this->isPackageInstalled('minishop3')) {
            $out['errors'][] = $this->lex('msg_ms3_not_installed', 'MiniShop3 not installed');
            return $out;
        }

        $convert = $this->convertCatalogClassKeys();
        $out['class_keys'] = isset($convert['updated']) ? $convert['updated'] : array();
        if (!empty($convert['errors'])) {
            $out['errors'] = array_merge($out['errors'], $convert['errors']);
        }
        $out['ensure_data'] = $this->ensureMs3ProductData();

        if (!class_exists('MmxVendorCompat', false)) {
            require_once dirname(__FILE__) . '/mmxvendorcompat.class.php';
        }
        $vc = new MmxVendorCompat($this->modx, $siteRoot, $this->storePath, $dataDir);
        $out['quarantine'] = $vc->forceQuarantinePackage('minishop2');
        $out['cache'] = $vc->clearModxCache();
        $out['inventory_after'] = $this->inventoryCatalog();

        $after = $out['inventory_after'];
        if (empty($after['products_ms3_key']) && empty($after['categories_ms3_key'])) {
            $out['warning'] = $this->lex(
                'msg_ms2_retire_empty_catalog',
                'After retire, no MS3 class_key products/categories found. If you used Package Management → Uninstall miniShop2, resources may have been removed — restore a snapshot, then use MigrateMODX «Retire miniShop2» instead of PM uninstall.'
            );
            $out['errors'][] = $out['warning'];
        }

        $qOk = !empty($out['quarantine']['ok']) || !empty($out['quarantine']['missing']);
        $out['ok'] = $qOk && empty($out['errors']);
        $out['warning_never_pm_uninstall'] = $this->lex(
            'msg_ms2_never_pm_uninstall',
            'Do not uninstall miniShop2 via Package Management — it can leave broken class_keys or remove shop resources. Use MigrateMODX Retire (quarantine folders) after class_key convert.'
        );
        @file_put_contents(
            $this->storePath . 'ms2-retire.json',
            json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
        return $out;
    }

    protected function loadJson($path)
    {
        if (!is_file($path)) {
            return array();
        }
        $data = json_decode((string)file_get_contents($path), true);
        return is_array($data) ? $data : array();
    }
}
