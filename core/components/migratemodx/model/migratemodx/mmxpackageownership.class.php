<?php
/**
 * Map snippets/plugins/chunks/templates/files to extras packages,
 * and detect manual overrides of vendor elements.
 */
class MmxPackageOwnership
{
    /** @var modX */
    protected $modx;
    /** @var array|null lowercase element name => package */
    protected $nameIndex = null;
    /** @var array|null package => lowercase name => absolute file path */
    protected $fileIndex = null;
    /** @var array lowercase package folder => true */
    protected $installedDirs = array();

    public function __construct($modx)
    {
        $this->modx = $modx;
    }

    /**
     * @param object $el MODX element
     * @param string $kind snippet|plugin|chunk|template
     * @param string $code current element body
     * @return array{package:string,path:string,manual_override:bool,vendor_stock:bool}
     */
    public function resolveElement($el, $kind, $code = '')
    {
        $this->buildIndex();
        $name = '';
        if ($kind === 'template') {
            $name = (string)$el->get('templatename');
        } else {
            $name = (string)$el->get('name');
        }
        $staticFile = '';
        if ((int)$el->get('static') === 1) {
            $staticFile = str_replace('\\', '/', (string)$el->get('static_file'));
        }
        $pkg = '';
        $path = '';
        if ($staticFile !== '') {
            $pkg = $this->packageFromPath($staticFile);
            $path = $staticFile;
            if ($pkg === '' && is_file($staticFile)) {
                $path = str_replace('\\', '/', $staticFile);
            } elseif ($pkg !== '' && !is_file($staticFile) && defined('MODX_BASE_PATH')) {
                $abs = rtrim(str_replace('\\', '/', MODX_BASE_PATH), '/') . '/' . ltrim($staticFile, '/');
                if (is_file($abs)) {
                    $path = $abs;
                }
            }
        }
        if ($pkg === '') {
            $fromDoc = $this->packageFromDocblock($code);
            if ($fromDoc !== '' && isset($this->installedDirs[$fromDoc])) {
                $pkg = $fromDoc;
            }
        }
        if ($pkg === '') {
            $hit = $this->lookupByName($name, $kind);
            if ($hit) {
                $pkg = $hit['package'];
                $path = $hit['path'];
            }
        }
        if ($pkg === '') {
            $catPkg = $this->packageFromCategory($el);
            if ($catPkg !== '') {
                $pkg = $catPkg;
            }
        }
        if ($pkg === '') {
            return array(
                'package' => 'custom',
                'path' => $path,
                'manual_override' => false,
                'vendor_stock' => false,
            );
        }
        $manual = false;
        if ($path !== '' && is_file($path) && $code !== '') {
            $vendor = @file_get_contents($path);
            if ($vendor !== false && $this->isSubstantialEdit($vendor, $code)) {
                /* Real rewrite of vendor element — keep in audit. */
                $manual = true;
            }
        }
        /* Name matched a package element but no file to diff: treat as vendor stock
           (update/reinstall refreshes it). Renamed custom copies are not in the index. */
        return array(
            'package' => $pkg,
            'path' => $path,
            'manual_override' => $manual,
            'vendor_stock' => !$manual,
        );
    }

    public function packageFromPath($path)
    {
        $path = str_replace('\\', '/', (string)$path);
        if (preg_match('#/components/([a-zA-Z0-9_\-]+)/#', $path, $m)) {
            return strtolower($m[1]);
        }
        return '';
    }

    /**
     * Packages whose stock code will be fixed by update/replace — skip monkey-patching.
     */
    public static function isVendorRefreshVerdict($verdict)
    {
        return in_array((string)$verdict, array('replace', 'fail', 'update', 'ok'), true);
    }

    public function packageFromDocblock($code)
    {
        if ($code === '' || !preg_match('/@package\s+([a-zA-Z0-9_\-]+)/i', (string)$code, $m)) {
            return '';
        }
        return strtolower($m[1]);
    }

    protected function packageFromCategory($el)
    {
        try {
            $catId = (int)$el->get('category');
            if ($catId <= 0) {
                return '';
            }
            $cls = class_exists('\\MODX\\Revolution\\modCategory')
                ? '\\MODX\\Revolution\\modCategory'
                : 'modCategory';
            $cat = $this->modx->getObject($cls, $catId);
            if (!$cat) {
                return '';
            }
            $cname = strtolower(preg_replace('/[^a-zA-Z0-9_\-]+/', '', (string)$cat->get('category')));
            if ($cname !== '' && isset($this->installedDirs[$cname])) {
                return $cname;
            }
            /* Categories like "modDevTools" / "FormIt" */
            $raw = strtolower(trim((string)$cat->get('category')));
            if ($raw !== '' && isset($this->installedDirs[$raw])) {
                return $raw;
            }
            foreach ($this->installedDirs as $pkg => $_) {
                if (strlen($pkg) >= 4 && ($raw === $pkg || strpos($raw, $pkg) === 0 || strpos($pkg, $raw) === 0)) {
                    return $pkg;
                }
            }
        } catch (Exception $e) {
            return '';
        } catch (Throwable $e) {
            return '';
        }
        return '';
    }

    protected function lookupByName($name, $kind)
    {
        $this->buildIndex();
        $raw = strtolower(trim((string)$name));
        $key = $this->normalizeElementKey($name);
        if ($key === '') {
            return null;
        }
        $pkg = '';
        if (isset($this->nameIndex[$key])) {
            $pkg = $this->nameIndex[$key];
        } elseif (isset($this->nameIndex[$raw])) {
            $pkg = $this->nameIndex[$raw];
            $key = $raw;
        } elseif (isset($this->installedDirs[$key]) || isset($this->installedDirs[$raw])) {
            /* Element named exactly like the package (modDevTools → moddevtools). */
            $pkg = isset($this->installedDirs[$key]) ? $key : $raw;
        } else {
            /* Prefix: FormItRetriever → formit (min length 4 to avoid «if»). */
            foreach ($this->installedDirs as $cand => $_) {
                if (strlen($cand) < 4) {
                    continue;
                }
                if (strpos($key, $cand) === 0 || strpos($raw, $cand) === 0) {
                    $pkg = $cand;
                    break;
                }
            }
        }
        if ($pkg === '') {
            $pkg = $this->resolveByConvention($key);
        }
        if ($pkg === '') {
            return null;
        }
        $path = '';
        if (isset($this->fileIndex[$pkg][$key])) {
            $path = $this->fileIndex[$pkg][$key];
        } elseif (isset($this->fileIndex[$pkg][$raw])) {
            $path = $this->fileIndex[$pkg][$raw];
        }
        return array('package' => $pkg, 'path' => $path, 'kind' => $kind);
    }

    /**
     * pdoCrumbs / get_tickets / ms_products → pdocrumbs / gettickets / msproducts
     */
    public function normalizeElementKey($name)
    {
        $s = strtolower(trim((string)$name));
        $s = preg_replace('/[^a-z0-9]+/', '', $s);
        return $s === null ? '' : $s;
    }

    /**
     * Heuristics for renamed transport elements (TicketComments, msProducts, …).
     */
    protected function resolveByConvention($key)
    {
        if ($key === '') {
            return '';
        }
        if (isset($this->installedDirs['tickets']) && strpos($key, 'ticket') !== false) {
            return 'tickets';
        }
        if (isset($this->installedDirs['msearch2']) && strpos($key, 'msearch') === 0) {
            return 'msearch2';
        }
        if (isset($this->installedDirs['minishop2'])
            && preg_match('/^ms(product|cart|order|gallery|option|minicart|getorder)/', $key)
        ) {
            return 'minishop2';
        }
        if (isset($this->installedDirs['formit']) && strpos($key, 'formit') === 0) {
            return 'formit';
        }
        if (isset($this->installedDirs['pdotools']) && strpos($key, 'pdo') === 0) {
            return 'pdotools';
        }
        /* Stem: «tickets» → «ticket» inside TicketLatest */
        foreach ($this->installedDirs as $cand => $_) {
            if (strlen($cand) < 5) {
                continue;
            }
            $stem = preg_replace('/[0-9]+$/', '', $cand);
            $stem = rtrim((string)$stem, 's');
            if (strlen($stem) >= 5 && strpos($key, $stem) !== false) {
                return $cand;
            }
        }
        return '';
    }

    protected function buildIndex()
    {
        if ($this->nameIndex !== null) {
            return;
        }
        $this->nameIndex = array();
        $this->fileIndex = array();
        $this->installedDirs = array();
        $root = defined('MODX_CORE_PATH') ? MODX_CORE_PATH . 'components/' : '';
        if ($root === '' || !is_dir($root)) {
            return;
        }
        $map = array(
            'snippets' => 'snippet',
            'plugins' => 'plugin',
            'chunks' => 'chunk',
            'templates' => 'template',
        );
        foreach (scandir($root) as $pkg) {
            if ($pkg === '.' || $pkg === '..' || $pkg === 'migratemodx') {
                continue;
            }
            $base = $root . $pkg;
            if (!is_dir($base)) {
                continue;
            }
            $pkgKey = strtolower($pkg);
            $this->installedDirs[$pkgKey] = true;
            $this->fileIndex[$pkgKey] = array();
            /* Package folder name is also a possible element name. */
            if (!isset($this->nameIndex[$pkgKey])) {
                $this->nameIndex[$pkgKey] = $pkgKey;
            }
            foreach ($map as $dir => $kind) {
                $folder = $base . '/elements/' . $dir;
                if (!is_dir($folder)) {
                    continue;
                }
                $this->indexDir($folder, $pkgKey, $kind);
            }
            /* Some extras put elements directly under elements/ */
            $flat = $base . '/elements';
            if (is_dir($flat)) {
                $this->indexDir($flat, $pkgKey, '');
            }
        }
    }

    protected function indexDir($folder, $pkgKey, $kindHint)
    {
        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS)
            );
        } catch (Exception $e) {
            return;
        }
        foreach ($it as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $ext = strtolower($file->getExtension());
            if (!in_array($ext, array('php', 'tpl', 'html', 'txt'), true)) {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            $base = $file->getBasename('.' . $file->getExtension());
            $names = $this->elementNamesFromBasename($base);
            foreach ($names as $n) {
                $variants = array(strtolower($n), $this->normalizeElementKey($n));
                foreach ($variants as $nKey) {
                    if ($nKey === '') {
                        continue;
                    }
                    if (!isset($this->nameIndex[$nKey])) {
                        $this->nameIndex[$nKey] = $pkgKey;
                    }
                    $this->fileIndex[$pkgKey][$nKey] = $path;
                }
            }
        }
    }

    protected function elementNamesFromBasename($base)
    {
        $base = (string)$base;
        $out = array($base);
        $stripped = preg_replace(
            '/^(snippet|plugin|chunk|template|tv|hook)\./i',
            '',
            $base
        );
        if ($stripped !== null && $stripped !== $base) {
            $out[] = $stripped;
        }
        $stripped2 = preg_replace(
            '/\.(snippet|plugin|chunk|template)$/i',
            '',
            $base
        );
        if ($stripped2 !== null && $stripped2 !== $base) {
            $out[] = $stripped2;
        }
        return array_unique($out);
    }

    protected function sameCode($a, $b)
    {
        return $this->normalizeCode($a) === $this->normalizeCode($b);
    }

    /**
     * True only when DB body is a real rewrite of the vendor file (not whitespace/BOM drift).
     */
    public function isSubstantialEdit($vendor, $code)
    {
        if ($this->sameCode($vendor, $code)) {
            return false;
        }
        $va = $this->normalizeCode($vendor);
        $vb = $this->normalizeCode($code);
        if ($va === '' || $vb === '') {
            return $va !== $vb;
        }
        $lenA = strlen($va);
        $lenB = strlen($vb);
        $ratio = min($lenA, $lenB) / max($lenA, $lenB);
        if ($ratio < 0.75) {
            return true;
        }
        similar_text($va, $vb, $pct);
        return $pct < 90.0;
    }

    protected function normalizeCode($s)
    {
        $s = str_replace("\r\n", "\n", (string)$s);
        $s = str_replace("\r", "\n", $s);
        $s = preg_replace('#/\*.*?\*/#s', '', $s);
        $s = preg_replace('#^\s*//.*$#m', '', $s);
        $s = preg_replace('/\s+/', '', $s);
        return $s === null ? '' : $s;
    }
}
