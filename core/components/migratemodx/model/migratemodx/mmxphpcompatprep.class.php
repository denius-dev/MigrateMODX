<?php
class MmxPhpCompatPrep
{
    /** @var modX */
    protected $modx;
    protected $rules = array();
    protected $storePath;
    /** @var MmxPackageOwnership|null */
    protected $ownership;

    public function __construct($modx, $rulesFile, $storePath, $ownership = null)
    {
        $this->modx = $modx;
        $this->storePath = rtrim(str_replace('\\', '/', $storePath), '/') . '/';
        $this->rules = is_file($rulesFile) ? include $rulesFile : array();
        if (!is_array($this->rules)) {
            $this->rules = array();
        }
        if ($ownership instanceof MmxPackageOwnership) {
            $this->ownership = $ownership;
        } elseif (class_exists('MmxPackageOwnership')) {
            $this->ownership = new MmxPackageOwnership($modx);
        } else {
            $this->ownership = null;
        }
    }

    public function scan($scope = 'all')
    {
        $targets = $this->collectTargets($scope);
        $findings = array();
        foreach ($targets as $t) {
            foreach ($this->scanCode($t['code'], $t) as $f) {
                $findings[] = $f;
            }
        }
        return $findings;
    }

    public function dryRun($scope = 'custom', array $opts = array())
    {
        $findings = $this->scan($scope);
        $findings = $this->filterFindings($findings, $opts);
        $fixes = array();
        foreach ($findings as $f) {
            if (empty($f['autofixable'])) {
                continue;
            }
            $new = $this->applyRulesToCode($f['code_before'], true);
            if ($new !== null && $new !== $f['code_before']) {
                $fixes[] = array(
                    'uid' => isset($f['uid']) ? $f['uid'] : $this->uid($f),
                    'source' => $f['source'],
                    'name' => $f['name'],
                    'id' => $f['id'],
                    'path' => isset($f['path']) ? $f['path'] : '',
                    'package' => isset($f['package']) ? $f['package'] : '',
                    'rule' => $f['rule'],
                    'diff' => $this->makeDiff($f['code_before'], $new),
                    'excerpt' => $this->excerpt($f['code_before'], $f['rule']),
                    'code_after' => $new,
                );
            }
        }
        return $fixes;
    }

    public function apply($scope = 'custom', array $opts = array())
    {
        $fixes = $this->dryRun($scope, $opts);
        $journal = array('created_at' => date('c'), 'items' => array());
        $applied = 0;
        foreach ($fixes as $fix) {
            $before = null;
            if ($fix['source'] === 'snippet' || $fix['source'] === 'plugin') {
                $cls = $fix['source'] === 'snippet' ? 'modSnippet' : 'modPlugin';
                $full = class_exists('\\MODX\\Revolution\\' . $cls) ? '\\MODX\\Revolution\\' . $cls : $cls;
                $el = $this->modx->getObject($full, (int)$fix['id']);
                if (!$el) {
                    continue;
                }
                $field = $fix['source'] === 'snippet' ? 'snippet' : 'plugincode';
                $before = (string)$el->get($field);
                $after = $this->applyRulesToCode($before, true);
                if ($after === null || $after === $before) {
                    continue;
                }
                $el->set($field, $after);
                $el->save();
                $journal['items'][] = array(
                    'type' => 'element',
                    'source' => $fix['source'],
                    'id' => (int)$fix['id'],
                    'field' => $field,
                    'before' => $before,
                );
                $applied++;
            } elseif ($fix['source'] === 'file' && !empty($fix['path']) && is_file($fix['path'])) {
                $before = (string)file_get_contents($fix['path']);
                $after = $this->applyRulesToCode($before, true);
                if ($after === null || $after === $before) {
                    continue;
                }
                $bakDir = $this->storePath . 'patches/files/';
                if (!is_dir($bakDir)) {
                    @mkdir($bakDir, 0755, true);
                }
                $bak = $bakDir . md5($fix['path']) . '.bak';
                file_put_contents($bak, $before);
                file_put_contents($fix['path'], $after);
                $journal['items'][] = array(
                    'type' => 'file',
                    'path' => $fix['path'],
                    'backup' => $bak,
                );
                $applied++;
            }
        }
        $jid = date('Ymd-His');
        $jpath = $this->storePath . 'patches/php-' . $jid . '.json';
        if (!is_dir(dirname($jpath))) {
            @mkdir(dirname($jpath), 0755, true);
        }
        file_put_contents($jpath, json_encode($journal, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return array('applied' => $applied, 'journal' => $jpath, 'fixes' => count($fixes));
    }

    public function rollbackPatches($journalPath = null)
    {
        if ($journalPath === null) {
            $files = glob($this->storePath . 'patches/php-*.json');
            if (!$files) {
                return array('ok' => false, 'error' => 'No PHP patch journals');
            }
            rsort($files);
            $journalPath = $files[0];
        }
        if (!is_file($journalPath)) {
            return array('ok' => false, 'error' => 'Journal not found');
        }
        $journal = json_decode((string)file_get_contents($journalPath), true);
        if (!is_array($journal) || empty($journal['items'])) {
            return array('ok' => false, 'error' => 'Empty journal');
        }
        $restored = 0;
        foreach (array_reverse($journal['items']) as $item) {
            if ($item['type'] === 'element') {
                $cls = $item['source'] === 'snippet' ? 'modSnippet' : 'modPlugin';
                $full = class_exists('\\MODX\\Revolution\\' . $cls) ? '\\MODX\\Revolution\\' . $cls : $cls;
                $el = $this->modx->getObject($full, (int)$item['id']);
                if ($el) {
                    $el->set($item['field'], $item['before']);
                    $el->save();
                    $restored++;
                }
            } elseif ($item['type'] === 'file' && !empty($item['backup']) && is_file($item['backup'])) {
                file_put_contents($item['path'], file_get_contents($item['backup']));
                $restored++;
            }
        }
        return array('ok' => true, 'restored' => $restored, 'journal' => $journalPath);
    }

    protected function collectTargets($scope)
    {
        $targets = array();
        if ($scope === 'all' || $scope === 'custom') {
            foreach ($this->modx->getCollection($this->cls('modSnippet')) as $el) {
                $code = (string)$el->get('snippet');
                $own = $this->ownership
                    ? $this->ownership->resolveElement($el, 'snippet', $code)
                    : array('package' => 'custom', 'path' => '', 'manual_override' => false);
                $targets[] = array(
                    'source' => 'snippet',
                    'id' => (int)$el->get('id'),
                    'name' => (string)$el->get('name'),
                    'code' => $code,
                    'path' => $own['path'],
                    'package' => $own['package'],
                    'manual_override' => !empty($own['manual_override']),
                );
            }
            foreach ($this->modx->getCollection($this->cls('modPlugin')) as $el) {
                $code = (string)$el->get('plugincode');
                $own = $this->ownership
                    ? $this->ownership->resolveElement($el, 'plugin', $code)
                    : array('package' => 'custom', 'path' => '', 'manual_override' => false);
                $targets[] = array(
                    'source' => 'plugin',
                    'id' => (int)$el->get('id'),
                    'name' => (string)$el->get('name'),
                    'code' => $code,
                    'path' => $own['path'],
                    'package' => $own['package'],
                    'manual_override' => !empty($own['manual_override']),
                );
            }
        }
        if ($scope === 'all' || $scope === 'extras') {
            $dir = MODX_CORE_PATH . 'components/';
            if (is_dir($dir)) {
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
                foreach ($it as $file) {
                    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                        continue;
                    }
                    $path = str_replace('\\', '/', $file->getPathname());
                    if (strpos($path, '/components/migratemodx/') !== false) {
                        continue;
                    }
                    $code = @file_get_contents($path);
                    if ($code === false) {
                        continue;
                    }
                    $targets[] = array(
                        'source' => 'file',
                        'id' => 0,
                        'name' => $path,
                        'code' => $code,
                        'path' => $path,
                        'package' => $this->packageFromPath($path),
                    );
                }
            }
        }
        return $targets;
    }

    protected function scanCode($code, array $meta)
    {
        $out = array();
        foreach ($this->rules as $rule) {
            if (!isset($rule['detect']) || !preg_match($rule['detect'], $code)) {
                continue;
            }
            $row = array(
                'rule' => $rule['id'],
                'severity' => $rule['severity'],
                'message' => $rule['message'],
                'autofixable' => !empty($rule['autofix']) && isset($rule['safety']) && $rule['safety'] === 'high',
                'source' => $meta['source'],
                'name' => $meta['name'],
                'id' => $meta['id'],
                'path' => $meta['path'],
                'package' => isset($meta['package']) ? $meta['package'] : '',
                'manual_override' => !empty($meta['manual_override']),
                'excerpt' => $this->excerpt($code, $rule['id']),
                'code_before' => $code,
            );
            $row['uid'] = $this->uid($row);
            $out[] = $row;
        }
        return $out;
    }

    public function filterFindings(array $findings, array $opts)
    {
        $targets = isset($opts['targets']) && is_array($opts['targets']) ? $opts['targets'] : null;
        $mode = isset($opts['mode']) ? (string)$opts['mode'] : 'all';
        $skip = array();
        $src = array();
        if (!empty($opts['vendor_packages']) && is_array($opts['vendor_packages'])) {
            $src = $opts['vendor_packages'];
        } elseif (!empty($opts['replaced_packages']) && is_array($opts['replaced_packages'])) {
            $src = $opts['replaced_packages'];
        }
        foreach ($src as $p) {
            $skip[strtolower((string)$p)] = true;
        }
        $out = array();
        foreach ($findings as $f) {
            $pkg = strtolower(isset($f['package']) ? $f['package'] : '');
            $manual = !empty($f['manual_override']);
            if ($mode === 'keepers') {
                /* Skip stock vendor packages; keep custom + manual overrides. */
                if ($pkg !== '' && $pkg !== 'custom' && isset($skip[$pkg]) && !$manual) {
                    continue;
                }
            }
            if ($mode === 'replaced') {
                if ($manual) {
                    continue;
                }
                if ($pkg === '' || $pkg === 'custom' || !isset($skip[$pkg])) {
                    continue;
                }
            }
            $uid = isset($f['uid']) ? $f['uid'] : $this->uid($f);
            if ($targets !== null && !in_array($uid, $targets, true)) {
                continue;
            }
            $f['uid'] = $uid;
            $out[] = $f;
        }
        return $out;
    }

    public function packageFromPath($path)
    {
        $path = str_replace('\\', '/', (string)$path);
        if (preg_match('#/components/([a-zA-Z0-9_\-]+)/#', $path, $m)) {
            return strtolower($m[1]);
        }
        return '';
    }

    public function uid(array $f)
    {
        return implode('|', array(
            isset($f['source']) ? $f['source'] : '',
            isset($f['id']) ? (string)$f['id'] : '0',
            isset($f['path']) ? $f['path'] : '',
            isset($f['rule']) ? $f['rule'] : (isset($f['code']) ? $f['code'] : ''),
            isset($f['name']) ? $f['name'] : '',
        ));
    }

    public function excerpt($code, $needle = '')
    {
        $lines = preg_split("/\r\n|\n|\r/", (string)$code);
        $hit = -1;
        if ($needle !== '') {
            foreach ($lines as $i => $line) {
                if (stripos($line, str_replace('_', '', $needle)) !== false
                    || ($needle === 'curly_offset' && preg_match('/\$[a-zA-Z_][a-zA-Z0-9_]*\{/', $line))
                    || ($needle === 'dollar_brace_interp' && strpos($line, '${') !== false)
                    || ($needle === 'each' && preg_match('/\beach\s*\(/', $line))
                ) {
                    $hit = $i;
                    break;
                }
            }
        }
        if ($hit < 0) {
            $hit = 0;
        }
        $start = max(0, $hit - 2);
        $end = min(count($lines), $hit + 4);
        $chunk = array_slice($lines, $start, $end - $start);
        $text = implode("\n", $chunk);
        if (strlen($text) > 1200) {
            $text = substr($text, 0, 1200) . '…';
        }
        return $text;
    }

    protected function applyRulesToCode($code, $highOnly = true)
    {
        $out = $code;
        foreach ($this->rules as $rule) {
            if (empty($rule['autofix'])) {
                continue;
            }
            if ($highOnly && (!isset($rule['safety']) || $rule['safety'] !== 'high')) {
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

    protected function makeDiff($before, $after)
    {
        $b = explode("\n", $before);
        $a = explode("\n", $after);
        $lines = array();
        $max = max(count($b), count($a));
        for ($i = 0; $i < $max && $i < 80; $i++) {
            $bl = isset($b[$i]) ? $b[$i] : '';
            $al = isset($a[$i]) ? $a[$i] : '';
            if ($bl !== $al) {
                $lines[] = '-' . $bl;
                $lines[] = '+' . $al;
            }
        }
        return implode("\n", $lines);
    }

    protected function cls($name)
    {
        return class_exists('\\MODX\\Revolution\\' . $name) ? '\\MODX\\Revolution\\' . $name : $name;
    }
}
