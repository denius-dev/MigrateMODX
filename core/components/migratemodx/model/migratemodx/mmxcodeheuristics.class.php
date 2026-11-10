<?php
class MmxCodeHeuristics
{
    /** @var modX */
    protected $modx;
    /** @var MmxPackageOwnership */
    protected $ownership;

    protected $patterns = array(
        array('code' => 'modAction', 'severity' => 'blocker', 're' => '/\\bmodAction\\b|\\$modx->actionMap|MODx\\.action\\b/'),
        array('code' => 'modRestClient', 'severity' => 'blocker', 're' => '/\\bmodRestClient\\b/'),
        array('code' => 'contentType_field', 'severity' => 'warning', 're' => '/->get\\([\'"]contentType[\'"]\\)|\\[\'contentType\'\\]/'),
        array('code' => 'flat_processor_require', 'severity' => 'blocker', 're' => '/core\\/model\\/modx\\/modprocessor\\.class\\.php|processors\\/resource\\/(create|update)\\.class\\.php/'),
        array('code' => 'eval_tv', 'severity' => 'blocker', 're' => '/@EVAL\\b/'),
        array('code' => 'unnamespaced_extends_resource', 'severity' => 'warning', 're' => '/extends\\s+modResource\\b/'),
    );

    public function __construct($modx, $ownership = null)
    {
        $this->modx = $modx;
        $this->ownership = $ownership instanceof MmxPackageOwnership
            ? $ownership
            : new MmxPackageOwnership($modx);
    }

    public function run()
    {
        $findings = array();
        try {
            $findings = array_merge($findings, $this->scanElements('modSnippet', 'snippet'));
            $findings = array_merge($findings, $this->scanElements('modPlugin', 'plugin'));
            $findings = array_merge($findings, $this->scanDirectory(MODX_CORE_PATH . 'components/', 'component_file'));
        } catch (Exception $e) {
            $findings[] = array(
                'source' => 'system',
                'name' => 'heuristics',
                'id' => 0,
                'code' => 'heuristics_error',
                'severity' => 'warning',
                'message' => 'Code heuristics aborted: ' . $e->getMessage(),
            );
        } catch (Throwable $e) {
            $findings[] = array(
                'source' => 'system',
                'name' => 'heuristics',
                'id' => 0,
                'code' => 'heuristics_error',
                'severity' => 'warning',
                'message' => 'Code heuristics aborted: ' . $e->getMessage(),
            );
        }
        return $findings;
    }

    protected function scanElements($class, $kind)
    {
        $out = array();
        try {
            $cls = class_exists('\\MODX\\Revolution\\' . $class) ? '\\MODX\\Revolution\\' . $class : $class;
            $collection = $this->modx->getCollection($cls);
            if (!$collection) {
                return $out;
            }
            foreach ($collection as $el) {
                $code = (string)$el->get('snippet');
                if ($kind === 'plugin') {
                    $code = (string)$el->get('plugincode');
                }
                $name = (string)$el->get('name');
                $id = (int)$el->get('id');
                $own = $this->ownership->resolveElement($el, $kind, $code);
                foreach ($this->match($code) as $m) {
                    $m['source'] = $kind;
                    $m['name'] = $name;
                    $m['id'] = $id;
                    $m['path'] = $own['path'];
                    $m['package'] = $own['package'];
                    $m['manual_override'] = !empty($own['manual_override']);
                    $m['uid'] = $kind . '|' . $id . '||' . $m['code'] . '|' . $name;
                    $out[] = $m;
                }
            }
        } catch (Exception $e) {
            return $out;
        } catch (Throwable $e) {
            return $out;
        }
        return $out;
    }

    protected function scanDirectory($dir, $kind)
    {
        $out = array();
        if (!is_dir($dir)) {
            return $out;
        }
        try {
            $skip = array('migratemodx');
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
            );
        } catch (Exception $e) {
            return $out;
        } catch (Throwable $e) {
            return $out;
        }
        foreach ($it as $file) {
            try {
                if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                    continue;
                }
            } catch (Exception $e) {
                continue;
            } catch (Throwable $e) {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            foreach ($skip as $s) {
                if (strpos($path, '/components/' . $s . '/') !== false) {
                    continue 2;
                }
            }
            $code = @file_get_contents($path);
            if ($code === false) {
                continue;
            }
            $pkg = '';
            if (preg_match('#/components/([a-zA-Z0-9_\-]+)/#', $path, $mPkg)) {
                $pkg = strtolower($mPkg[1]);
            }
            foreach ($this->match($code) as $m) {
                $m['source'] = $kind;
                $m['name'] = $path;
                $m['id'] = 0;
                $m['path'] = $path;
                $m['package'] = $pkg;
                $m['uid'] = $kind . '|0|' . $path . '|' . $m['code'] . '|' . $path;
                $out[] = $m;
            }
        }
        return $out;
    }

    protected function match($code)
    {
        $hits = array();
        $lines = preg_split("/\r\n|\n|\r/", (string)$code);
        foreach ($this->patterns as $p) {
            if (!preg_match($p['re'], $code)) {
                continue;
            }
            $excerpt = '';
            foreach ($lines as $i => $line) {
                if (preg_match($p['re'], $line)) {
                    $start = max(0, $i - 2);
                    $excerpt = implode("\n", array_slice($lines, $start, 6));
                    break;
                }
            }
            if ($excerpt === '' && $lines) {
                $excerpt = implode("\n", array_slice($lines, 0, 6));
            }
            if (strlen($excerpt) > 1200) {
                $excerpt = substr($excerpt, 0, 1200) . '…';
            }
            $hits[] = array(
                'code' => $p['code'],
                'severity' => $p['severity'],
                'message' => 'Found pattern: ' . $p['code'],
                'excerpt' => $excerpt,
            );
        }
        return $hits;
    }
}
