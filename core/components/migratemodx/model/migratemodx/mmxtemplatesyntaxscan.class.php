<?php
class MmxTemplateSyntaxScan
{
    /** @var modX */
    protected $modx;
    /** @var MmxPackageOwnership */
    protected $ownership;

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
        $fenom = null;
        try {
            $fenom = $this->getFenom();
        } catch (Exception $e) {
            $findings[] = array(
                'source' => 'system',
                'name' => 'fenom',
                'id' => 0,
                'engine' => 'fenom',
                'severity' => 'warning',
                'message' => 'Fenom compile check unavailable: ' . $e->getMessage()
                    . ' (regex scan still runs)',
            );
            $fenom = null;
        } catch (Throwable $e) {
            $findings[] = array(
                'source' => 'system',
                'name' => 'fenom',
                'id' => 0,
                'engine' => 'fenom',
                'severity' => 'warning',
                'message' => 'Fenom compile check unavailable: ' . $e->getMessage()
                    . ' (regex scan still runs)',
            );
            $fenom = null;
        }
        $findings = array_merge($findings, $this->scanCollection('modTemplate', 'template', 'content', $fenom));
        $findings = array_merge($findings, $this->scanCollection('modChunk', 'chunk', 'snippet', $fenom));
        return $findings;
    }

    protected function scanCollection($class, $kind, $field, $fenom)
    {
        $out = array();
        $cls = class_exists('\\MODX\\Revolution\\' . $class) ? '\\MODX\\Revolution\\' . $class : $class;
        $items = $this->modx->getCollection($cls);
        if (!$items) {
            return $out;
        }
        foreach ($items as $el) {
            $content = (string)$el->get($field);
            $name = (string)$el->get('templatename');
            if ($kind === 'chunk') {
                $name = (string)$el->get('name');
            }
            $id = (int)$el->get('id');
            $own = $this->ownership->resolveElement($el, $kind, $content);
            foreach ($this->scanContent($content, $fenom) as $f) {
                $f['source'] = $kind;
                $f['name'] = $name;
                $f['id'] = $id;
                $f['path'] = $own['path'];
                $f['package'] = $own['package'];
                $f['manual_override'] = !empty($own['manual_override']);
                if (empty($f['excerpt'])) {
                    $f['excerpt'] = $this->excerpt($content);
                }
                $f['uid'] = $kind . '|' . $id . '||' . (isset($f['engine']) ? $f['engine'] : '') . '|' . $name;
                $out[] = $f;
            }
        }
        return $out;
    }

    protected function excerpt($content)
    {
        $lines = preg_split("/\r\n|\n|\r/", (string)$content);
        $hit = 0;
        foreach ($lines as $i => $line) {
            if (preg_match('/\\{(if|foreach|for|while|\\$)|\\[\\[/', $line)) {
                $hit = $i;
                break;
            }
        }
        $start = max(0, $hit - 2);
        $chunk = array_slice($lines, $start, 8);
        $text = implode("\n", $chunk);
        if (strlen($text) > 1200) {
            $text = substr($text, 0, 1200) . '…';
        }
        return $text;
    }

    protected function scanContent($content, $fenom)
    {
        $hits = array();
        $ex = $this->excerpt($content);
        /* MODX tags balance */
        $open = preg_match_all('/\\[\\[/', $content, $m1);
        $close = preg_match_all('/\\]\\]/', $content, $m2);
        if ($open !== $close) {
            $hits[] = array(
                'engine' => 'modx',
                'severity' => 'blocker',
                'message' => 'Unbalanced MODX tags [[ vs ]] (' . $open . '/' . $close . ')',
                'excerpt' => $ex,
            );
        }

        $hasFenom = (bool)preg_match('/\\{(if|foreach|for|while|set|include|extends|block|\\$)/', $content);
        $hasModx = (bool)preg_match('/\\[\\[/', $content);
        if ($hasFenom && $hasModx) {
            $hits[] = array(
                'engine' => 'mixed',
                'severity' => 'warning',
                'message' => 'Mixed MODX [[…]] and Fenom {…} in one element — verify parser mode',
                'excerpt' => $ex,
            );
        }

        if ($hasFenom) {
            if (preg_match('/\\{if\\b[^}]*$/', $content) || preg_match('/\\{foreach\\b[^}]*$/', $content)) {
                $hits[] = array(
                    'engine' => 'fenom',
                    'severity' => 'blocker',
                    'message' => 'Possibly unclosed Fenom {if}/{foreach} tag',
                    'excerpt' => $ex,
                );
            }
            foreach (array('if', 'foreach', 'for', 'block') as $tag) {
                $o = preg_match_all('/\\{' . $tag . '\\b/', $content, $mm);
                $c = preg_match_all('/\\{\\/' . $tag . '\\}/', $content, $mm2);
                if ($o > $c) {
                    $hits[] = array(
                        'engine' => 'fenom',
                        'severity' => 'blocker',
                        'message' => 'Unclosed Fenom {' . $tag . '} (' . $o . ' open / ' . $c . ' close)',
                        'excerpt' => $ex,
                    );
                }
            }
            if ($fenom) {
                try {
                    $fenom->compileCode($content, 'mmx_scan_' . md5($content));
                } catch (Exception $e) {
                    $hits[] = array(
                        'engine' => 'fenom',
                        'severity' => 'blocker',
                        'message' => 'Fenom parse error: ' . $e->getMessage(),
                        'excerpt' => $ex,
                    );
                } catch (Throwable $e) {
                    $hits[] = array(
                        'engine' => 'fenom',
                        'severity' => 'blocker',
                        'message' => 'Fenom parse error: ' . $e->getMessage(),
                        'excerpt' => $ex,
                    );
                }
            }
        }
        return $hits;
    }

    /**
     * Prefer a live pdoTools Fenom; never fatal if Fenom classes are incomplete.
     */
    protected function getFenom()
    {
        /* 1) Already-bootstrapped pdoTools (autoload + Provider already loaded) */
        try {
            $pdo = $this->modx->getService('pdoTools');
            if (!$pdo) {
                $pdo = $this->modx->getService(
                    'pdotools',
                    'pdoTools',
                    MODX_CORE_PATH . 'components/pdotools/model/pdotools/'
                );
            }
            if ($pdo && !empty($pdo->fenom) && is_object($pdo->fenom)) {
                return new MmxFenomBridge($pdo->fenom);
            }
        } catch (Exception $e) {
            /* continue */
        } catch (Throwable $e) {
            /* continue */
        }

        $base = MODX_CORE_PATH . 'components/pdotools/';
        $autoloads = array(
            $base . 'vendor/autoload.php',
            $base . 'model/fenom/autoload.php',
        );
        foreach ($autoloads as $auto) {
            if (!is_file($auto)) {
                continue;
            }
            try {
                require_once $auto;
                $bridge = $this->makeFenomInstance();
                if ($bridge) {
                    return $bridge;
                }
            } catch (Exception $e) {
                /* try next */
            } catch (Throwable $e) {
                /* try next */
            }
        }

        /* 3) Manual require of Fenom + Provider (old pdoTools layouts) */
        $candidates = array(
            array(
                'fenom' => $base . 'vendor/fenom/fenom/src/Fenom.php',
                'provider' => $base . 'vendor/fenom/fenom/src/Fenom/Provider.php',
            ),
            array(
                'fenom' => $base . 'model/fenom/Fenom.php',
                'provider' => $base . 'model/fenom/Provider.php',
            ),
            array(
                'fenom' => $base . 'model/fenom/Fenom.php',
                'provider' => $base . 'model/fenom/Fenom/Provider.php',
            ),
        );
        foreach ($candidates as $pair) {
            if (!is_file($pair['fenom'])) {
                continue;
            }
            try {
                if (is_file($pair['provider'])) {
                    require_once $pair['provider'];
                }
                require_once $pair['fenom'];
                /* Extra siblings often needed for compile */
                $dir = dirname($pair['fenom']);
                foreach (array('Template.php', 'Fenom/Template.php', 'Tokenizer.php', 'Fenom/Tokenizer.php') as $rel) {
                    $sib = $dir . '/' . $rel;
                    if (is_file($sib)) {
                        require_once $sib;
                    }
                }
                $bridge = $this->makeFenomInstance();
                if ($bridge) {
                    return $bridge;
                }
            } catch (Exception $e) {
                continue;
            } catch (Throwable $e) {
                continue;
            }
        }

        return null;
    }

    /**
     * @return MmxFenomBridge|null
     */
    protected function makeFenomInstance()
    {
        if (!class_exists('Fenom', false) && !class_exists('\\Fenom', false)) {
            return null;
        }
        if (!class_exists('Fenom\\Provider', false) && !class_exists('\\Fenom\\Provider', false)) {
            /* Without Provider, Fenom::factory() fatals — skip compile path */
            return null;
        }
        $tmp = MODX_CORE_PATH . 'cache/migratemodx/fenom/';
        if (!is_dir($tmp)) {
            @mkdir($tmp, 0755, true);
        }
        if (!method_exists('Fenom', 'factory')) {
            return null;
        }
        $f = Fenom::factory($tmp, $tmp);
        if (!is_object($f)) {
            return null;
        }
        return new MmxFenomBridge($f);
    }
}

class MmxFenomBridge
{
    protected $fenom;

    public function __construct($fenom)
    {
        $this->fenom = $fenom;
    }

    public function compileCode($code, $name)
    {
        if (method_exists($this->fenom, 'compileCode')) {
            return $this->fenom->compileCode($code, $name);
        }
        if (method_exists($this->fenom, 'compile')) {
            return $this->fenom->compile($name, $code);
        }
        /* Regex checks already ran; no compile API available */
        return true;
    }
}
