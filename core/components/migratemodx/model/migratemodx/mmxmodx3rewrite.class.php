<?php
/**
 * High-safety MODX 3 / ms2→ms3 call rewrites + attention for ambiguous cases.
 */
class MmxModx3Rewrite
{
    /** @var modX */
    protected $modx;
    protected $storePath;
    protected $modx3Rules = array();
    protected $ms2Rules = array();

    public function __construct($modx, $dataDir, $storePath)
    {
        $this->modx = $modx;
        $this->storePath = rtrim(str_replace('\\', '/', $storePath), '/') . '/';
        $dataDir = rtrim(str_replace('\\', '/', $dataDir), '/') . '/';
        $this->modx3Rules = $this->loadJson($dataDir . 'modx3.json');
        $this->ms2Rules = $this->loadJson($dataDir . 'ms2-ms3.json');
    }

    /**
     * Scan + optional apply of high-safety string replacements.
     *
     * @param bool $apply
     * @return array
     */
    public function run($apply = true)
    {
        $done = array();
        $failed = array();
        $needs = array();
        $journal = array('created_at' => date('c'), 'items' => array());

        $targets = $this->collectTargets();
        foreach ($targets as $t) {
            $code = $t['code'];
            $orig = $code;
            $localDone = array();
            $localNeeds = array();

            foreach ($this->safeReplacements() as $rep) {
                if ($rep['from'] === '' || strpos($code, $rep['from']) === false) {
                    continue;
                }
                if ($apply) {
                    $code = str_replace($rep['from'], $rep['to'], $code);
                }
                $localDone[] = array(
                    'id' => $rep['id'],
                    'message' => $rep['message'],
                    'source' => $t['source'],
                    'name' => $t['name'],
                    'applied' => (bool)$apply,
                );
            }

            foreach ($this->attentionDetectors() as $det) {
                if (@preg_match('/' . $det['re'] . '/i', $code)) {
                    $localNeeds[] = array(
                        'id' => $det['id'],
                        'message' => $det['message'],
                        'source' => $t['source'],
                        'name' => $t['name'],
                        'path' => isset($t['path']) ? $t['path'] : '',
                    );
                }
            }

            foreach ($this->ms2SnippetHints($code, $t) as $hint) {
                if (!empty($hint['attention'])) {
                    $localNeeds[] = $hint;
                } else {
                    $localDone[] = $hint;
                }
            }

            if ($apply && $code !== $orig) {
                try {
                    $this->persistTarget($t, $code, $orig, $journal);
                    $done = array_merge($done, $localDone);
                } catch (Exception $e) {
                    $failed[] = array(
                        'id' => 'rewrite_persist',
                        'message' => $e->getMessage(),
                        'source' => $t['source'],
                        'name' => $t['name'],
                    );
                } catch (Throwable $e) {
                    $failed[] = array(
                        'id' => 'rewrite_persist',
                        'message' => $e->getMessage(),
                        'source' => $t['source'],
                        'name' => $t['name'],
                    );
                }
            } else {
                $done = array_merge($done, $localDone);
            }
            $needs = array_merge($needs, $localNeeds);
        }

        if ($apply && !empty($journal['items'])) {
            $dir = $this->storePath . 'patches/';
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $path = $dir . 'rewrite-' . date('Ymd-His') . '.json';
            file_put_contents($path, json_encode($journal, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }

        return array(
            'ok' => empty($failed),
            'done' => $done,
            'failed' => $failed,
            'needs_attention' => $needs,
            'targets' => count($targets),
        );
    }

    protected function loadJson($path)
    {
        if (!is_file($path)) {
            return array();
        }
        $data = json_decode((string)file_get_contents($path), true);
        return is_array($data) ? $data : array();
    }

    protected function safeReplacements()
    {
        $out = array();
        foreach ((array)(isset($this->modx3Rules['safe_string_replacements']) ? $this->modx3Rules['safe_string_replacements'] : array()) as $r) {
            if (empty($r['from']) || empty($r['to'])) {
                continue;
            }
            if (isset($r['safety']) && $r['safety'] !== 'high') {
                continue;
            }
            $out[] = array(
                'id' => isset($r['id']) ? $r['id'] : md5($r['from']),
                'from' => (string)$r['from'],
                'to' => (string)$r['to'],
                'message' => isset($r['message']) ? (string)$r['message'] : 'Safe string replacement',
            );
        }
        return $out;
    }

    protected function attentionDetectors()
    {
        $out = array();
        foreach ((array)(isset($this->modx3Rules['element_replacements']) ? $this->modx3Rules['element_replacements'] : array()) as $r) {
            if (empty($r['detect'])) {
                continue;
            }
            $out[] = array(
                'id' => isset($r['id']) ? $r['id'] : 'detect',
                're' => (string)$r['detect'],
                'message' => isset($r['message']) ? (string)$r['message'] : 'Needs manual review',
            );
        }
        foreach ((array)(isset($this->ms2Rules['attention_patterns']) ? $this->ms2Rules['attention_patterns'] : array()) as $r) {
            if (empty($r['re'])) {
                continue;
            }
            $out[] = array(
                'id' => isset($r['id']) ? $r['id'] : 'ms2_pat',
                're' => (string)$r['re'],
                'message' => isset($r['message']) ? (string)$r['message'] : 'ms2 pattern needs review',
            );
        }
        return $out;
    }

    /**
     * Map known ms2 snippet calls using table only (never invent names).
     */
    protected function ms2SnippetHints($code, array $t)
    {
        $out = array();
        $map = isset($this->ms2Rules['snippets']) && is_array($this->ms2Rules['snippets'])
            ? $this->ms2Rules['snippets'] : array();
        foreach ($map as $from => $info) {
            $to = is_array($info) && isset($info['to']) ? $info['to'] : null;
            $note = is_array($info) && isset($info['note']) ? $info['note'] : '';
            if ($to === null || $to === '') {
                continue;
            }
            // Call forms: [[msProducts]], [[!msProducts]], [[msProducts? ...]]
            $re = '/\[\[!?' . preg_quote($from, '/') . '(?:\?|\])/i';
            if (!preg_match($re, $code)) {
                continue;
            }
            if (strcasecmp($from, $to) === 0) {
                $out[] = array(
                    'id' => 'ms2_snip_' . $from,
                    'message' => 'Snippet [[' . $from . ']] present — ' . $note,
                    'source' => $t['source'],
                    'name' => $t['name'],
                    'attention' => true,
                );
            } else {
                $out[] = array(
                    'id' => 'ms2_snip_' . $from,
                    'message' => 'Snippet [[' . $from . ']] → [[' . $to . ']] (table). ' . $note,
                    'source' => $t['source'],
                    'name' => $t['name'],
                    'attention' => true,
                );
            }
        }
        $chunks = isset($this->ms2Rules['chunks_prefix']) && is_array($this->ms2Rules['chunks_prefix'])
            ? $this->ms2Rules['chunks_prefix'] : array();
        foreach ($chunks as $prefix => $info) {
            if (stripos($t['name'], $prefix) === 0 || stripos($code, $prefix) !== false) {
                $out[] = array(
                    'id' => 'ms2_chunk_' . $prefix,
                    'message' => isset($info['note']) ? $info['note'] : ('Chunk/tpl ' . $prefix . ' needs ms3 rebuild'),
                    'source' => $t['source'],
                    'name' => $t['name'],
                    'attention' => true,
                );
            }
        }
        return $out;
    }

    protected function collectTargets()
    {
        $out = array();
        $classes = array(
            array('modSnippet', 'snippet', 'snippet'),
            array('modPlugin', 'plugincode', 'plugin'),
            array('modChunk', 'snippet', 'chunk'),
            array('modTemplate', 'content', 'template'),
        );
        foreach ($classes as $c) {
            list($cls, $field, $source) = $c;
            $full = class_exists('\\MODX\\Revolution\\' . $cls) ? '\\MODX\\Revolution\\' . $cls : $cls;
            try {
                $items = $this->modx->getCollection($full);
            } catch (Exception $e) {
                $items = null;
            } catch (Throwable $e) {
                $items = null;
            }
            if (!$items) {
                continue;
            }
            foreach ($items as $el) {
                $code = (string)$el->get($field);
                if ($code === '') {
                    continue;
                }
                $out[] = array(
                    'source' => $source,
                    'id' => (int)$el->get('id'),
                    'name' => (string)$el->get('name'),
                    'field' => $field,
                    'code' => $code,
                    'class' => $full,
                );
            }
        }
        return $out;
    }

    protected function persistTarget(array $t, $code, $orig, array &$journal)
    {
        $full = $t['class'];
        $el = $this->modx->getObject($full, (int)$t['id']);
        if (!$el) {
            throw new Exception('Element not found: ' . $t['source'] . '#' . $t['id']);
        }
        $el->set($t['field'], $code);
        if (!$el->save()) {
            throw new Exception('Save failed: ' . $t['name']);
        }
        $journal['items'][] = array(
            'type' => 'element',
            'source' => $t['source'],
            'id' => (int)$t['id'],
            'field' => $t['field'],
            'before' => $orig,
        );
    }
}
