<?php
/**
 * Post ms2→ms3 front compatibility: rewrite class_key / queries in elements,
 * remap leftover MS2 snippets, patch phpThumbOn, heal minicart + cart page + listing thumbs.
 *
 * Goal: make typical custom themes work after MiniShop3 without hand-editing every site.
 */
class MmxMs3FrontCompat
{
    /** @var modX */
    protected $modx;
    protected $storePath;
    protected $siteRoot;

    public function __construct($modx, $storePath, $siteRoot = '')
    {
        $this->modx = $modx;
        $this->storePath = rtrim(str_replace('\\', '/', (string)$storePath), '/') . '/';
        if ($siteRoot !== '') {
            $this->siteRoot = rtrim(str_replace('\\', '/', $siteRoot), '/') . '/';
        } elseif (defined('MODX_BASE_PATH')) {
            $this->siteRoot = rtrim(str_replace('\\', '/', MODX_BASE_PATH), '/') . '/';
        } else {
            $this->siteRoot = '';
        }
    }

    /**
     * @param bool $apply
     * @return array
     */
    public function run($apply = true)
    {
        $done = array();
        $failed = array();
        $needs = array();
        $journal = array('created_at' => date('c'), 'items' => array());

        $el = $this->rewriteElements($apply, $journal);
        $done = array_merge($done, $el['done']);
        $failed = array_merge($failed, $el['failed']);
        $needs = array_merge($needs, $el['needs']);

        $snip = $this->remapShopSnippets($apply, $journal);
        $done = array_merge($done, $snip['done']);
        $failed = array_merge($failed, $snip['failed']);
        $needs = array_merge($needs, $snip['needs']);

        $static = $this->healStaticElementSources($apply, $journal);
        $done = array_merge($done, $static['done']);
        $failed = array_merge($failed, $static['failed']);
        $needs = array_merge($needs, $static['needs']);

        $cart = $this->healMiniCart($apply, $journal);
        $done = array_merge($done, $cart['done']);
        $needs = array_merge($needs, $cart['needs']);

        $cartPage = $this->healCartPage($apply, $journal);
        $done = array_merge($done, $cartPage['done']);
        $needs = array_merge($needs, $cartPage['needs']);

        $thumbs = $this->healListingThumbs($apply, $journal);
        $done = array_merge($done, $thumbs['done']);
        $needs = array_merge($needs, $thumbs['needs']);

        $scripts = $this->healThemeScripts($apply, $journal);
        $done = array_merge($done, $scripts['done']);
        $needs = array_merge($needs, $scripts['needs']);

        $orphans = $this->stubOrphanComponentSnippets($apply, $journal);
        $done = array_merge($done, $orphans['done']);
        $needs = array_merge($needs, $orphans['needs']);

        $gal = $this->healMsGalleryCalls($apply, $journal);
        $done = array_merge($done, $gal['done']);
        $needs = array_merge($needs, $gal['needs']);

        $jq = $this->healJqueryGuards($apply, $journal);
        $done = array_merge($done, $jq['done']);
        $needs = array_merge($needs, $jq['needs']);

        $ms3act = $this->healMs3ActionInputs($apply, $journal);
        $done = array_merge($done, $ms3act['done']);
        $needs = array_merge($needs, $ms3act['needs']);

        $poly = $this->healMs3SubmitterPolyfill($apply, $journal);
        $done = array_merge($done, $poly['done']);
        $needs = array_merge($needs, $poly['needs']);

        $bind = $this->auditFrontBindings($apply, $journal);
        $done = array_merge($done, $bind['done']);
        $needs = array_merge($needs, $bind['needs']);

        $assets = $this->healAssetScripts($apply, $journal);
        $done = array_merge($done, $assets['done']);
        $needs = array_merge($needs, $assets['needs']);

        $hide = $this->healProductHidemenu($apply, $journal);
        $done = array_merge($done, $hide['done']);
        $failed = array_merge($failed, $hide['failed']);

        $search = $this->healSearchFormTpl($apply, $journal);
        $done = array_merge($done, $search['done']);
        $needs = array_merge($needs, $search['needs']);

        $cats = $this->healCategoryCards($apply, $journal);
        $done = array_merge($done, $cats['done']);
        $needs = array_merge($needs, $cats['needs']);

        $mcBtn = $this->healMiniCartTrigger($apply, $journal);
        $done = array_merge($done, $mcBtn['done']);
        $needs = array_merge($needs, $mcBtn['needs']);

        $mcWire = $this->healMiniCartMs3Wiring($apply, $journal);
        $done = array_merge($done, $mcWire['done']);
        $needs = array_merge($needs, $mcWire['needs']);

        $keys = $this->healMs3CartProductKeys($apply, $journal);
        $done = array_merge($done, $keys['done']);
        $needs = array_merge($needs, $keys['needs']);

        $ms2 = $this->healPurgeMs2FrontTraces($apply, $journal);
        $done = array_merge($done, $ms2['done']);
        $needs = array_merge($needs, $ms2['needs']);
        $failed = array_merge($failed, $ms2['failed']);

        $noise = $this->healPhp8ThemeNoise($apply, $journal);
        $done = array_merge($done, $noise['done']);
        $needs = array_merge($needs, $noise['needs']);

        $php8 = $this->healPhp8Signatures($apply, $journal);
        $done = array_merge($done, $php8['done']);
        $failed = array_merge($failed, $php8['failed']);

        $plugin = $this->enableMiniShop3Plugin($apply, $journal);
        $done = array_merge($done, $plugin['done']);
        $failed = array_merge($failed, $plugin['failed']);

        $pthumb = $this->patchPhpThumbOn($apply, $journal);
        $done = array_merge($done, $pthumb['done']);
        $failed = array_merge($failed, $pthumb['failed']);

        if ($apply) {
            $this->clearElementCaches();
            if (!empty($journal['items'])) {
                if (!is_dir($this->storePath . 'patches/')) {
                    @mkdir($this->storePath . 'patches/', 0755, true);
                }
                @file_put_contents(
                    $this->storePath . 'patches/ms3-front-' . date('Ymd-His') . '.json',
                    json_encode($journal, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
                );
            }
        }

        return array(
            'ok' => empty($failed),
            'done' => $done,
            'failed' => $failed,
            'needs_attention' => $needs,
            'applied' => (bool)$apply,
            'changes' => count($journal['items']),
        );
    }

    /**
     * High-safety string replacements for MS2 class names → MiniShop3 FQCN.
     *
     * @return array<int,array{id:string,from:string,to:string,message:string}>
     */
    public function safeReplacements()
    {
        $msProduct = 'MiniShop3\\Model\\msProduct';
        $msCategory = 'MiniShop3\\Model\\msCategory';
        $msProductData = 'MiniShop3\\Model\\msProductData';
        $msCategoryMember = 'MiniShop3\\Model\\msCategoryMember';
        return array(
            array(
                'id' => 'php_class_key_msProduct',
                'from' => "'class_key' => 'msProduct'",
                'to' => "'class_key' => '" . $msProduct . "'",
                'message' => 'PHP class_key msProduct → MiniShop3\\Model\\msProduct',
            ),
            array(
                'id' => 'php_class_key_msProduct_dq',
                'from' => '"class_key" => "msProduct"',
                'to' => '"class_key" => "' . $msProduct . '"',
                'message' => 'PHP class_key msProduct → MiniShop3\\Model\\msProduct',
            ),
            array(
                'id' => 'php_class_key_msCategory',
                'from' => "'class_key' => 'msCategory'",
                'to' => "'class_key' => '" . $msCategory . "'",
                'message' => 'PHP class_key msCategory → MiniShop3\\Model\\msCategory',
            ),
            array(
                'id' => 'php_newquery_msProduct',
                'from' => "newQuery('msProduct')",
                'to' => "newQuery('" . $msProduct . "')",
                'message' => 'newQuery(msProduct) → MiniShop3 model',
            ),
            array(
                'id' => 'php_newquery_msProduct_dq',
                'from' => 'newQuery("msProduct")',
                'to' => 'newQuery("' . $msProduct . '")',
                'message' => 'newQuery(msProduct) → MiniShop3 model',
            ),
            array(
                'id' => 'php_newquery_msCategory',
                'from' => "newQuery('msCategory')",
                'to' => "newQuery('" . $msCategory . "')",
                'message' => 'newQuery(msCategory) → MiniShop3 model',
            ),
            array(
                'id' => 'php_getobject_msProductData',
                'from' => "getObject('msProductData'",
                'to' => "getObject('" . $msProductData . "'",
                'message' => 'getObject(msProductData) → MiniShop3 model',
            ),
            array(
                'id' => 'php_getobject_msProduct',
                'from' => "getObject('msProduct'",
                'to' => "getObject('" . $msProduct . "'",
                'message' => 'getObject(msProduct) → MiniShop3 model',
            ),
            array(
                'id' => 'php_getcount_msProduct',
                'from' => "getCount('msProduct'",
                'to' => "getCount('" . $msProduct . "'",
                'message' => 'getCount(msProduct) → MiniShop3 model',
            ),
            array(
                'id' => 'php_leftjoin_msCategoryMember',
                'from' => "leftJoin('msCategoryMember'",
                'to' => "leftJoin('" . $msCategoryMember . "'",
                'message' => 'leftJoin(msCategoryMember) → MiniShop3 model',
            ),
            array(
                'id' => 'tag_class_key_msProduct',
                'from' => '&class_key=`msProduct`',
                'to' => '&class_key=`' . $msProduct . '`',
                'message' => 'Tag class_key msProduct → MiniShop3 FQCN',
            ),
            array(
                'id' => 'tag_class_key_msCategory',
                'from' => '&class_key=`msCategory`',
                'to' => '&class_key=`' . $msCategory . '`',
                'message' => 'Tag class_key msCategory → MiniShop3 FQCN',
            ),
            array(
                'id' => 'json_class_key_msProduct',
                'from' => '"class_key":"msProduct"',
                'to' => '"class_key":"' . $msProduct . '"',
                'message' => 'JSON where class_key msProduct → MiniShop3 FQCN',
            ),
            array(
                'id' => 'json_class_key_msProduct_sp',
                'from' => '"class_key": "msProduct"',
                'to' => '"class_key": "' . $msProduct . '"',
                'message' => 'JSON where class_key msProduct → MiniShop3 FQCN',
            ),
            array(
                'id' => 'form_ms2_to_ms3',
                'from' => 'class="ms2_form',
                'to' => 'class="ms3_form',
                'message' => 'Cart form class ms2_form → ms3_form',
            ),
            array(
                'id' => 'form_ms2_to_ms3_sq',
                'from' => "class='ms2_form",
                'to' => "class='ms3_form",
                'message' => 'Cart form class ms2_form → ms3_form',
            ),
            /* Token in the middle of class="… ms2_form …" (common in theme product cards) */
            array(
                'id' => 'class_token_ms2_form',
                'from' => 'ms2_form',
                'to' => 'ms3_form',
                'message' => 'CSS class token ms2_form → ms3_form',
            ),
            array(
                'id' => 'name_ms2_action',
                'from' => 'name="ms2_action"',
                'to' => 'name="ms3_action"',
                'message' => 'Form field ms2_action → ms3_action',
            ),
            array(
                'id' => 'name_ms2_action_sq',
                'from' => "name='ms2_action'",
                'to' => "name='ms3_action'",
                'message' => 'Form field ms2_action → ms3_action',
            ),
            array(
                'id' => 'ms2_total_count',
                'from' => 'ms2_total_count',
                'to' => 'ms3_total_count',
                'message' => 'Cart total class ms2_total_count → ms3_total_count',
            ),
            array(
                'id' => 'ms2_total_cost',
                'from' => 'ms2_total_cost',
                'to' => 'ms3_total_cost',
                'message' => 'Cart total class ms2_total_cost → ms3_total_cost',
            ),
            array(
                'id' => 'ms2_total_weight',
                'from' => 'ms2_total_weight',
                'to' => 'ms3_total_weight',
                'message' => 'Cart total class ms2_total_weight → ms3_total_weight',
            ),
            array(
                'id' => 'ms2_cost',
                'from' => 'class="ms2_cost',
                'to' => 'class="ms3_cost',
                'message' => 'Line cost class ms2_cost → ms3_cost',
            ),
        );
    }

    protected function rewriteElements($apply, array &$journal)
    {
        $done = array();
        $failed = array();
        $needs = array();
        $reps = $this->safeReplacements();
        foreach ($this->collectTargets() as $t) {
            $code = $t['code'];
            $orig = $code;
            $local = array();
            foreach ($reps as $rep) {
                if ($rep['from'] === '' || strpos($code, $rep['from']) === false) {
                    continue;
                }
                if ($apply) {
                    $code = str_replace($rep['from'], $rep['to'], $code);
                }
                $local[] = $rep;
            }
            /* Ensure MS3 forms get data-ms3-form so ms3.js binds add-to-cart / cart actions */
            if ($apply && strpos($code, 'ms3_form') !== false && strpos($code, 'data-ms3-form') === false) {
                $code2 = preg_replace(
                    '/(<form\b[^>]*\bms3_form\b[^>]*)(>)/i',
                    '$1 data-ms3-form$2',
                    $code
                );
                if ($code2 && $code2 !== $code) {
                    $code = $code2;
                    $local[] = array(
                        'id' => 'data_ms3_form',
                        'from' => '',
                        'to' => 'data-ms3-form',
                        'message' => 'Added data-ms3-form on ms3_form',
                    );
                }
            }
            /* MS3 FormData(form) omits submit-button name/value — inject hidden ms3_action */
            if ($apply) {
                $codeBeforeAction = $code;
                $code = $this->injectMs3ActionHiddenInputs($code);
                if ($code !== $codeBeforeAction) {
                    $local[] = array(
                        'id' => 'ms3_action_hidden',
                        'from' => '',
                        'to' => 'input[name=ms3_action]',
                        'message' => 'Hidden ms3_action for FormData (button-only action)',
                    );
                }
            }
            /* Attention-only: leftover miniShop2 JS API */
            if (preg_match('/miniShop2\.(Cart|Order|Callbacks|Message)/', $orig)) {
                $needs[] = array(
                    'id' => 'ms2_js_api',
                    'message' => 'Element «' . $t['name'] . '» still calls miniShop2.* JS — update theme scripts to MiniShop3 (ms3) frontend.',
                    'source' => $t['source'],
                    'name' => $t['name'],
                    'severity' => 'hard',
                );
            }
            if (!$local && $code === $orig) {
                continue;
            }
            if ($apply && $code !== $orig) {
                try {
                    $this->persistTarget($t, $code, $orig, $journal);
                    foreach ($local as $rep) {
                        $done[] = array(
                            'id' => $rep['id'],
                            'message' => $rep['message'] . ' @ ' . $t['source'] . ':' . $t['name'],
                            'source' => $t['source'],
                            'name' => $t['name'],
                            'applied' => true,
                        );
                    }
                } catch (Exception $e) {
                    $failed[] = array('id' => 'front_rewrite', 'message' => $e->getMessage(), 'name' => $t['name']);
                } catch (Throwable $e) {
                    $failed[] = array('id' => 'front_rewrite', 'message' => $e->getMessage(), 'name' => $t['name']);
                }
            } else {
                foreach ($local as $rep) {
                    $done[] = array(
                        'id' => $rep['id'],
                        'message' => $rep['message'] . ' @ ' . $t['source'] . ':' . $t['name'],
                        'source' => $t['source'],
                        'name' => $t['name'],
                        'applied' => false,
                    );
                }
            }
        }
        return array('done' => $done, 'failed' => $failed, 'needs' => $needs);
    }

    /**
     * Point known shop snippets at MiniShop3 static files when MS2 path is stale.
     * Also recreates snippets deleted by Package Management uninstall of miniShop2
     * (shared names — PM wipe leaves MiniShop3 installed but catalog tags empty).
     */
    protected function remapShopSnippets($apply, array &$journal)
    {
        $done = array();
        $failed = array();
        $needs = array();
        $map = array(
            'msProducts' => 'components/minishop3/elements/snippets/ms3_products.php',
            'msCart' => 'components/minishop3/elements/snippets/ms3_cart.php',
            'msGallery' => 'components/minishop3/elements/snippets/ms3_gallery.php',
            'msOptions' => 'components/minishop3/elements/snippets/ms3_options.php',
            'msOrder' => 'components/minishop3/elements/snippets/ms3_order.php',
            'msGetOrder' => 'components/minishop3/elements/snippets/ms3_get_order.php',
            'msProductOptions' => 'components/minishop3/elements/snippets/ms3_product_options.php',
        );
        $core = defined('MODX_CORE_PATH') ? MODX_CORE_PATH : '';
        foreach ($map as $name => $rel) {
            $abs = $core . $rel;
            if (!is_file($abs)) {
                continue;
            }
            $snip = $this->getSnippet($name);
            $created = false;
            if (!$snip) {
                $msg = 'Snippet ' . $name . ' recreated → MiniShop3 static file'
                    . ' (missing after miniShop2 Package Management uninstall)';
                if ($apply) {
                    try {
                        $snip = $this->newSnippet($name);
                        if (!$snip) {
                            throw new Exception('newObject failed');
                        }
                        $snip->set('static', 1);
                        $snip->set('static_file', 'core/' . $rel);
                        $snip->set('source', $this->staticElementSourceId());
                        $snip->set('snippet', '');
                        $this->applyMs3SnippetDefaults($snip, $name);
                        if (!$snip->save()) {
                            throw new Exception('save failed');
                        }
                        $created = true;
                        $journal['items'][] = array(
                            'type' => 'snippet_recreate',
                            'name' => $name,
                            'after' => 'core/' . $rel,
                        );
                        $done[] = array('id' => 'snip_recreate_' . $name, 'message' => $msg, 'applied' => true);
                    } catch (Exception $e) {
                        $failed[] = array('id' => 'snip_recreate_' . $name, 'message' => $e->getMessage());
                        continue;
                    } catch (Throwable $e) {
                        $failed[] = array('id' => 'snip_recreate_' . $name, 'message' => $e->getMessage());
                        continue;
                    }
                } else {
                    $done[] = array('id' => 'snip_recreate_' . $name, 'message' => $msg, 'applied' => false);
                    continue;
                }
            }
            if ($created) {
                continue;
            }
            $file = str_replace('\\', '/', (string)$snip->get('static_file'));
            $code = (string)$snip->get('snippet');
            $bad = (stripos($file, 'minishop2') !== false)
                || (strpos($code, "getService('miniShop2')") !== false)
                || (strpos($code, 'getService("miniShop2")') !== false);
            $needsDefaults = $this->snippetNeedsMs3Defaults($snip, $name);
            if (!$bad && $file !== '' && stripos($file, 'minishop3') !== false) {
                if ($needsDefaults && $apply) {
                    $this->applyMs3SnippetDefaults($snip, $name);
                    if ($snip->save()) {
                        $journal['items'][] = array('type' => 'snippet_defaults', 'name' => $name);
                        $done[] = array(
                            'id' => 'snip_defaults_' . $name,
                            'message' => 'Snippet ' . $name . ' default properties restored (return/tpl)',
                            'applied' => true,
                        );
                    }
                } elseif ($needsDefaults) {
                    $done[] = array(
                        'id' => 'snip_defaults_' . $name,
                        'message' => 'Snippet ' . $name . ' default properties restored (return/tpl)',
                        'applied' => false,
                    );
                }
                continue;
            }
            if (!$bad && $file === '' && strpos($code, 'MiniShop3') !== false) {
                continue;
            }
            $msg = 'Snippet ' . $name . ' remapped to MiniShop3 static file';
            if ($apply) {
                try {
                    $before = array(
                        'static' => $snip->get('static'),
                        'static_file' => $snip->get('static_file'),
                        'snippet' => $code,
                    );
                    $snip->set('static', 1);
                    $snip->set('static_file', 'core/' . $rel);
                    $snip->set('source', $this->staticElementSourceId());
                    $this->applyMs3SnippetDefaults($snip, $name);
                    /* Keep DB body as include hint; static file wins when static=1 */
                    if (!$snip->save()) {
                        throw new Exception('save failed');
                    }
                    $journal['items'][] = array(
                        'type' => 'snippet_remap',
                        'name' => $name,
                        'before' => $before,
                        'after' => 'core/' . $rel,
                    );
                    $done[] = array('id' => 'snip_remap_' . $name, 'message' => $msg, 'applied' => true);
                } catch (Exception $e) {
                    $failed[] = array('id' => 'snip_remap_' . $name, 'message' => $e->getMessage());
                } catch (Throwable $e) {
                    $failed[] = array('id' => 'snip_remap_' . $name, 'message' => $e->getMessage());
                }
            } else {
                $done[] = array('id' => 'snip_remap_' . $name, 'message' => $msg, 'applied' => false);
            }
        }
        /* MS2-era dynamic minicart helpers — stub so tags do not Fatal */
        foreach (array('msMiniCartDynamic', 'msDynamicCount') as $dynName) {
            $dyn = $this->getSnippet($dynName);
            if (!$dyn) {
                continue;
            }
            $file = (string)$dyn->get('static_file');
            $code = (string)$dyn->get('snippet');
            $isDyn = stripos($file, 'msminicartdynamic') !== false
                || stripos($code, 'msminicartdynamic') !== false
                || stripos($code, 'msMiniCartDynamic') !== false
                || ($dynName === 'msDynamicCount' && stripos($code, 'getMsCart') !== false);
            if (!$isDyn && $dynName === 'msMiniCartDynamic') {
                $isDyn = stripos($code, 'miniShop2') !== false || stripos($code, 'ms2_') !== false;
            }
            if (!$isDyn) {
                continue;
            }
            $needs[] = array(
                'id' => 'ms_minicart_dynamic',
                'message' => 'Snippet ' . $dynName . ' is miniShop2-era. Heal front stubs it; use MS3 qty inputs / [[!msMiniCart]] Fenom tpl; remove msminicartdynamic.js.',
                'severity' => 'hard',
                'name' => $dynName,
            );
            if ($apply) {
                $stub = ($dynName === 'msDynamicCount')
                    ? "<?php\n/** MS3: msDynamicCount stub — qty comes from cart tpl {\$product.count} */\nreturn '';\n"
                    : "<?php\nreturn '';\n";
                $before = (string)$dyn->get('snippet');
                $dyn->set('static', 0);
                $dyn->set('static_file', '');
                $dyn->set('snippet', $stub);
                if ($dyn->save()) {
                    $journal['items'][] = array(
                        'type' => 'snippet_stub',
                        'name' => $dynName,
                        'before' => $before,
                    );
                    $done[] = array(
                        'id' => 'snip_stub_' . $dynName,
                        'message' => $dynName . ' stubbed (MS2 dynamic minicart helper)',
                        'applied' => true,
                    );
                }
            }
        }
        return array('done' => $done, 'failed' => $failed, 'needs' => $needs);
    }

    /**
     * Static elements with source=0 resolve `static_file` against the PHP working directory.
     * Front pages run from the site root, but MiniShop3 api.php runs from assets/components/minishop3/,
     * so MODX caches an empty snippet there: cart/add|remove return no `render`, badge/panel never update.
     */
    protected function healStaticElementSources($apply, array &$journal)
    {
        $done = array();
        $failed = array();
        $needs = array();
        $base = defined('MODX_BASE_PATH') ? rtrim(str_replace('\\', '/', MODX_BASE_PATH), '/') . '/' : $this->siteRoot;
        if ($base === '') {
            return array('done' => $done, 'failed' => $failed, 'needs' => $needs);
        }
        $sourceId = $this->staticElementSourceId();
        $classes = array(
            'modSnippet' => 'snippet',
            'modChunk' => 'snippet',
            'modPlugin' => 'plugincode',
            'modTemplate' => 'content',
        );
        foreach ($classes as $short => $field) {
            $cls = class_exists('\\MODX\\Revolution\\' . $short) ? '\\MODX\\Revolution\\' . $short : $short;
            try {
                $list = $this->modx->getCollection($cls, array('static' => 1, 'source' => 0));
            } catch (Exception $e) {
                $list = array();
            } catch (Throwable $e) {
                $list = array();
            }
            foreach ((array)$list as $el) {
                $file = str_replace('\\', '/', trim((string)$el->get('static_file')));
                if ($file === '' || $file[0] === '/' || preg_match('#^[A-Za-z]:/#', $file) || strpos($file, '{') !== false) {
                    continue;
                }
                if (!is_file($base . $file)) {
                    continue;
                }
                $name = $short === 'modTemplate' ? (string)$el->get('templatename') : (string)$el->get('name');
                $label = $short . ':' . $name;
                if ($sourceId > 0) {
                    $msg = 'Static ' . $label . ' bound to Filesystem media source #' . $sourceId
                        . ' (relative path broke MiniShop3 API render)';
                } else {
                    $msg = 'Static ' . $label . ' inlined from ' . $file
                        . ' (no root Filesystem media source; relative path broke MiniShop3 API render)';
                }
                if (!$apply) {
                    $done[] = array('id' => 'static_source_' . $name, 'message' => $msg, 'applied' => false);
                    continue;
                }
                try {
                    $before = array('static' => (int)$el->get('static'), 'source' => (int)$el->get('source'), 'static_file' => $file);
                    if ($sourceId > 0) {
                        $el->set('source', $sourceId);
                    } else {
                        $code = (string)@file_get_contents($base . $file);
                        if ($field !== 'content' && $short !== 'modChunk') {
                            $code = preg_replace('/^\s*<\?php\s*/', '', $code);
                            $code = preg_replace('/\?>\s*$/', '', $code);
                        }
                        $el->set($field, $code);
                        $el->set('static', 0);
                    }
                    if (!$el->save()) {
                        throw new Exception('save failed');
                    }
                    $journal['items'][] = array(
                        'type' => 'static_source',
                        'class' => $short,
                        'name' => $name,
                        'before' => $before,
                        'source' => $sourceId,
                    );
                    $done[] = array('id' => 'static_source_' . $name, 'message' => $msg, 'applied' => true);
                } catch (Exception $e) {
                    $failed[] = array('id' => 'static_source_' . $name, 'message' => $label . ': ' . $e->getMessage());
                } catch (Throwable $e) {
                    $failed[] = array('id' => 'static_source_' . $name, 'message' => $label . ': ' . $e->getMessage());
                }
            }
        }
        return array('done' => $done, 'failed' => $failed, 'needs' => $needs);
    }

    /**
     * Filesystem media source rooted at MODX_BASE_PATH (empty basePath), or 0 when none exists.
     *
     * @return int
     */
    protected function staticElementSourceId()
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $cached = 0;
        $cls = class_exists('\\MODX\\Revolution\\Sources\\modMediaSource')
            ? '\\MODX\\Revolution\\Sources\\modMediaSource'
            : 'sources.modMediaSource';
        try {
            $sources = $this->modx->getCollection($cls);
        } catch (Exception $e) {
            $sources = array();
        } catch (Throwable $e) {
            $sources = array();
        }
        $candidates = array();
        foreach ((array)$sources as $src) {
            if (stripos((string)$src->get('class_key'), 'modFileMediaSource') === false) {
                continue;
            }
            $props = $src->get('properties');
            if (is_string($props)) {
                $props = json_decode($props, true);
            }
            $basePath = '';
            if (is_array($props) && isset($props['basePath'])) {
                $basePath = is_array($props['basePath'])
                    ? (string)(isset($props['basePath']['value']) ? $props['basePath']['value'] : '')
                    : (string)$props['basePath'];
            }
            $basePath = trim(str_replace('\\', '/', $basePath));
            $root = defined('MODX_BASE_PATH') ? rtrim(str_replace('\\', '/', MODX_BASE_PATH), '/') : '';
            if ($basePath === '' || $basePath === '/' || $basePath === './'
                || ($root !== '' && rtrim($basePath, '/') === $root)
            ) {
                $candidates[] = (int)$src->get('id');
            }
        }
        if (!$candidates) {
            return $cached;
        }
        $default = (int)$this->modx->getOption('default_media_source', null, 0);
        $cached = in_array($default, $candidates, true) ? $default : min($candidates);
        return $cached;
    }

    /**
     * Heal msMiniCart: bridge to msCart, prefer site customMiniCart tpl when present.
     */
    protected function healMiniCart($apply, array &$journal)
    {
        $done = array();
        $needs = array();
        $snip = $this->getSnippet('msMiniCart');
        $customTpl = $this->chunkExists('customMiniCart') ? 'customMiniCart' : 'tpl.msMiniCart';
        /* Override empty OR stock MS3 default props (snippet properties often set tpl=tpl.msMiniCart). */
        $bridge = "<?php\n"
            . "/** @var modX \$modx */\n"
            . "/** @var array \$scriptProperties */\n"
            . "\$tpl = isset(\$scriptProperties['tpl']) ? trim((string)\$scriptProperties['tpl']) : '';\n"
            . "if (\$tpl === '' || \$tpl === 'tpl.msMiniCart' || \$tpl === 'tpl.msCart') {\n"
            . "    \$scriptProperties['tpl'] = '" . $customTpl . "';\n"
            . "}\n"
            . "return \$modx->runSnippet('msCart', \$scriptProperties);\n";

        if (!$snip) {
            $msg = 'msMiniCart recreated → msCart bridge (tpl=' . $customTpl . ')';
            if ($apply) {
                $snip = $this->newSnippet('msMiniCart');
                if ($snip) {
                    $snip->set('static', 0);
                    $snip->set('static_file', '');
                    $snip->set('snippet', $bridge);
                    if ($snip->save()) {
                        $journal['items'][] = array('type' => 'msMiniCart_recreate', 'tpl' => $customTpl);
                        $done[] = array('id' => 'msMiniCart_bridge', 'message' => $msg, 'applied' => true);
                    }
                }
            } else {
                $done[] = array('id' => 'msMiniCart_bridge', 'message' => $msg, 'applied' => false);
            }
            return array('done' => $done, 'needs' => $needs);
        }
        $file = str_replace('\\', '/', (string)$snip->get('static_file'));
        $code = (string)$snip->get('snippet');
        $needsHeal = (stripos($file, 'minishop2') !== false)
            || (strpos($code, "getService('miniShop2')") !== false)
            || (strpos($code, 'getService("miniShop2")') !== false)
            || (strpos($code, 'runSnippet(\'msCart\'') === false && strpos($code, 'runSnippet("msCart"') === false
                && stripos($file, 'minishop3') === false && strpos($code, 'services->get(\'ms3\')') === false);

        $props = array();
        try {
            if (method_exists($snip, 'getProperties')) {
                $props = (array)$snip->getProperties();
            }
        } catch (Exception $e) {
            $props = array();
        } catch (Throwable $e) {
            $props = array();
        }
        $propTpl = isset($props['tpl']) ? (string)$props['tpl'] : '';
        $hasStockProp = ($propTpl === 'tpl.msMiniCart' || $propTpl === 'tpl.msCart');
        $bridgeStale = (strpos($code, 'runSnippet') !== false)
            && (strpos($code, 'customMiniCart') === false || strpos($code, "\$tpl === ''") === false)
            && $customTpl === 'customMiniCart';

        /* Upgrade existing MS3 bridge that still defaults to stock tpl.msMiniCart */
        $upgradeTpl = !$needsHeal
            && $customTpl === 'customMiniCart'
            && (
                $hasStockProp
                || $bridgeStale
                || strpos($code, "'tpl.msMiniCart'") !== false
                || strpos($code, '"tpl.msMiniCart"') !== false
            );

        if ($needsHeal || $upgradeTpl) {
            $msg = 'msMiniCart bridged to msCart (tpl=' . $customTpl . ')';
            if ($apply) {
                $before = array(
                    'static' => $snip->get('static'),
                    'static_file' => $snip->get('static_file'),
                    'snippet' => $code,
                    'properties' => $props,
                );
                $snip->set('static', 0);
                $snip->set('static_file', '');
                $snip->set('snippet', $bridge);
                if ($hasStockProp && method_exists($snip, 'setProperties')) {
                    unset($props['tpl']);
                    $snip->setProperties($props);
                }
                if ($snip->save()) {
                    $journal['items'][] = array('type' => 'msMiniCart_bridge', 'before' => $before, 'tpl' => $customTpl);
                    $done[] = array('id' => 'msMiniCart_bridge', 'message' => $msg, 'applied' => true);
                }
            } else {
                $done[] = array('id' => 'msMiniCart_bridge', 'message' => $msg, 'applied' => false);
            }
        }

        /* Fenom minicart chunks: MS3 actions, product_key, qty instead of msDynamicCount */
        foreach ($this->collectTargets() as $t) {
            if ($t['source'] !== 'chunk' && $t['source'] !== 'template') {
                continue;
            }
            if (!$this->isMiniCartMarkupTarget($t['name'], $t['code'])) {
                continue;
            }
            $body = $t['code'];
            $orig = $body;
            $body = $this->rewriteCartMarkup($body, array('strip_order' => true));
            if ($body === $orig) {
                continue;
            }
            $msg = 'Minicart «' . $t['name'] . '» updated for MS3 (actions, product_key, qty)';
            if ($apply) {
                try {
                    $this->persistTarget($t, $body, $orig, $journal);
                    $done[] = array('id' => 'minicart_markup_' . $t['name'], 'message' => $msg, 'applied' => true);
                } catch (Exception $e) {
                    $needs[] = array('id' => 'minicart_markup_fail', 'message' => $e->getMessage(), 'name' => $t['name']);
                } catch (Throwable $e) {
                    $needs[] = array('id' => 'minicart_markup_fail', 'message' => $e->getMessage(), 'name' => $t['name']);
                }
            } else {
                $done[] = array('id' => 'minicart_markup_' . $t['name'], 'message' => $msg, 'applied' => false);
            }
        }

        /* header: render minicart into #minicart-content when empty AJAX target sits beside [[!msMiniCart]] */
        foreach ($this->collectTargets() as $t) {
            if ($t['source'] !== 'chunk' && $t['source'] !== 'template') {
                continue;
            }
            if (!preg_match('/\[\[\s*!?msMiniCart/i', $t['code'])) {
                continue;
            }
            if (strpos($t['code'], 'id="minicart-content"') === false
                && strpos($t['code'], "id='minicart-content'") === false) {
                continue;
            }
            $body = $t['code'];
            $orig = $body;
            /* Move [[!msMiniCart…]] inside empty #minicart-content */
            $body = preg_replace(
                '/\[\[\s*!?msMiniCart\??[^\]]*\]\]\s*<div\s+id=["\']minicart-content["\']\s*>\s*<\/div>/i',
                '<div id="minicart-content">[[!msMiniCart]]</div>',
                $body,
                1
            );
            if ($body === null || $body === $orig) {
                continue;
            }
            $msg = 'Moved [[!msMiniCart]] into #minicart-content @ ' . $t['source'] . ':' . $t['name'];
            if ($apply) {
                try {
                    $this->persistTarget($t, $body, $orig, $journal);
                    $done[] = array('id' => 'minicart_container_' . $t['name'], 'message' => $msg, 'applied' => true);
                } catch (Exception $e) {
                    $needs[] = array('id' => 'minicart_container_fail', 'message' => $e->getMessage());
                } catch (Throwable $e) {
                    $needs[] = array('id' => 'minicart_container_fail', 'message' => $e->getMessage());
                }
            } else {
                $done[] = array('id' => 'minicart_container_' . $t['name'], 'message' => $msg, 'applied' => false);
            }
        }

        return array('done' => $done, 'needs' => $needs);
    }

    /**
     * @param string $name
     * @param string $code
     * @return bool
     */
    protected function isMiniCartMarkupTarget($name, $code)
    {
        $n = strtolower((string)$name);
        if (strpos($n, 'minicart') !== false || strpos($n, 'mini_cart') !== false
            || strpos($n, 'mini-cart') !== false || $n === 'customminicart'
            || strpos($n, 'tpl.msminicart') !== false
        ) {
            return true;
        }
        /* Content heuristic: dropdown panel, not a full cart page */
        if (preg_match('/id=["\']minicart-content["\']/i', $code)
            && (preg_match('/cart\/(?:remove|change)/i', $code) || stripos($code, 'msDynamicCount') !== false)
        ) {
            return true;
        }
        return false;
    }

    /**
     * Full cart page / tpl.msCart(.row) — not the header dropdown.
     *
     * @param string $name
     * @param string $code
     * @return bool
     */
    protected function isCartPageMarkupTarget($name, $code)
    {
        if ($this->isMiniCartMarkupTarget($name, $code)) {
            return false;
        }
        $n = strtolower((string)$name);
        $nameHit = (bool)preg_match(
            '/(^|[.\-_])(mscart|ms_cart|cart)([.\-_]|$)|tpl\.mscart|customcart|page\.?cart|cart\.?page|корзин/u',
            $n
        );
        /* Avoid product listing / category templates named *cart* coincidentally */
        if ($nameHit && preg_match('/product|category|catalog|listing|search/i', $n)
            && !preg_match('/cart\/(?:remove|change)/i', $code)
        ) {
            $nameHit = false;
        }
        $bodyHit = false;
        if (preg_match('/id=["\']msCart["\']/i', $code)
            || preg_match('/ms[23]_cart_is_empty/i', $code)
            || (preg_match('/\[\[\s*!?msCart\b/i', $code) && preg_match('/cart\/(?:remove|change)/i', $code))
            || (preg_match('/cart\/(?:remove|change)/i', $code)
                && (preg_match('/name=["\']key["\']/i', $code) || preg_match('/product\.key|product\.product_key/i', $code)))
        ) {
            $bodyHit = true;
        }
        /* cart/add alone is product card — ignore */
        if (!$nameHit && !$bodyHit) {
            return false;
        }
        if ($bodyHit && !preg_match('/cart\/(?:remove|change)|ms[23]_cart_is_empty|id=["\']msCart["\']/i', $code)
            && !preg_match('/\[\[\s*!?msCart\b/i', $code)
        ) {
            return false;
        }
        return $nameHit || $bodyHit;
    }

    /**
     * Rewrite Fenom/HTML cart markup from MS2 field names to MS3.
     * Minicart mode strips nested [[!msOrder]] (belongs on cart page / #msOrder mount).
     *
     * @param string $body
     * @param array $opts strip_order (bool)
     * @return string
     */
    protected function rewriteCartMarkup($body, array $opts = array())
    {
        $stripOrder = !empty($opts['strip_order']);
        $body = str_replace('ms2_cart_is_empty', 'ms3_cart_is_empty', $body);
        $body = str_replace('class="ms2_form', 'class="ms3_form', $body);
        $body = str_replace("class='ms2_form", "class='ms3_form", $body);
        $body = str_replace('name="ms2_action"', 'name="ms3_action"', $body);
        $body = str_replace("name='ms2_action'", "name='ms3_action'", $body);
        $body = str_replace('name="key"', 'name="product_key"', $body);
        $body = str_replace("name='key'", "name='product_key'", $body);
        /* Prefer MS3 product_key placeholder; keep id="{$product.key}" as row id if still used */
        $body = str_replace('value="{$product.key}"', 'value="{$product.product_key}"', $body);
        $body = str_replace("value='{\$product.key}'", "value='{\$product.product_key}'", $body);
        $body = str_replace('id="{$product.key}"', 'id="{$product.product_key}"', $body);
        $body = str_replace('{$product.key}', '{$product.product_key}', $body);
        /* Ensure data-ms3-form on cart forms */
        if (strpos($body, 'ms3_form') !== false && strpos($body, 'data-ms3-form') === false) {
            $body = preg_replace('/class="ms3_form([^"]*)"/', 'class="ms3_form$1" data-ms3-form', $body);
            $body = preg_replace("/class='ms3_form([^']*)'/", "class='ms3_form$1' data-ms3-form", $body);
        }
        /* Replace msDynamicCount call with MS3 qty input */
        $qty = '<div class="quantity d-flex align-items-center">'
            . '<input class="form-control ms3-cart-qty-input" type="number" name="count" value="{$product.count}" min="0" data-ms3-qty="input">'
            . '</div>'
            . '<input type="hidden" name="ms3_action" value="cart/change">';
        $body = preg_replace(
            '/\[\[\s*!?msDynamicCount\s*\?[\s\S]*?\]\]/i',
            $qty,
            $body
        );
        /*
         * Theme mistake / MS2 leftover: full [[!msOrder]] + #orderSuccess lived inside the
         * minicart dropdown → broken layout, nested checkout, delete/SSR targets wrong.
         * Order form belongs in #msOrder (fancybox / cart page), not in #minicart-content.
         */
        if ($stripOrder) {
            $body = preg_replace('/\[\[\s*!?msOrder\??[^\]]*\]\]/i', '', $body);
            $body = preg_replace(
                '/<div\s+id=["\']orderSuccess["\'][\s\S]*?<\/div>/i',
                '',
                $body
            );
        }
        /* Remove button: keep hidden ms3_action; drop duplicate name on <button> */
        $body = preg_replace(
            '/(<button\b[^>]*\bproduct-item_remove\b[^>]*)\s+name=["\']ms3_action["\']\s+value=["\']cart\/remove["\']/i',
            '$1',
            $body
        );
        /* MS2 link class leftovers on cart rows */
        $body = str_replace('ms2_product_link', 'ms3_link', $body);
        $body = str_replace('class="ms2_cost', 'class="ms3_cost', $body);
        $body = str_replace("class='ms2_cost", "class='ms3_cost", $body);
        return $body;
    }

    /**
     * @param string $body
     * @return string
     */
    protected function rewriteMiniCartMarkup($body)
    {
        return $this->rewriteCartMarkup($body, array('strip_order' => true));
    }

    /**
     * Heal dedicated cart page + tpl.msCart(.row/.empty): same MS3 wiring as minicart,
     * but keep [[!msOrder]], mount #msCart selector for SSR, and soft-refresh via live-sync.
     */
    protected function healCartPage($apply, array &$journal)
    {
        $done = array();
        $needs = array();

        foreach ($this->collectTargets() as $t) {
            if ($t['source'] !== 'chunk' && $t['source'] !== 'template') {
                continue;
            }
            if (!$this->isCartPageMarkupTarget($t['name'], $t['code'])) {
                continue;
            }
            $body = $t['code'];
            $orig = $body;
            $body = $this->rewriteCartMarkup($body, array('strip_order' => false));

            /* Ensure outer #msCart wrapper + selector for MS3 CartUI.renderCart */
            if (preg_match('/\[\[\s*!?msCart\b/i', $body)) {
                if (!preg_match('/id=["\']msCart["\']/i', $body)) {
                    $body2 = preg_replace(
                        '/\[\[\s*!?msCart\??([^\]]*)\]\]/i',
                        '<div id="msCart">[[!msCart?selector=`#msCart`$1]]</div>',
                        $body,
                        1
                    );
                    if (is_string($body2) && $body2 !== '') {
                        $body = $body2;
                        /* Fix accidental &? / empty prop after selector inject */
                        $body = str_replace(
                            '[[!msCart?selector=`#msCart`&]]',
                            '[[!msCart?selector=`#msCart`]]',
                            $body
                        );
                        $body = preg_replace(
                            '/\[\[\s*!msCart\?selector=`#msCart`\s+([^\]]+)\]\]/i',
                            '[[!msCart?selector=`#msCart`&$1]]',
                            $body,
                            1
                        );
                    }
                } elseif (!preg_match('/\[\[\s*!?msCart\?[^\]]*selector=/i', $body)) {
                    $body2 = preg_replace(
                        '/\[\[\s*!?msCart\??\s*([^\]]*)\]\]/i',
                        '[[!msCart?selector=`#msCart`&$1]]',
                        $body,
                        1
                    );
                    if (is_string($body2) && $body2 !== '') {
                        $body = $body2;
                        $body = str_replace(
                            '[[!msCart?selector=`#msCart`&]]',
                            '[[!msCart?selector=`#msCart`]]',
                            $body
                        );
                        $body = str_replace(
                            '[[!msCart?selector=`#msCart`&?',
                            '[[!msCart?selector=`#msCart`&',
                            $body
                        );
                    }
                }
            }

            /* Inline order form on cart page: give it a stable #msOrder if missing */
            if (preg_match('/\[\[\s*!?msOrder\b/i', $body)
                && !preg_match('/id=["\']msOrder["\']/i', $body)
            ) {
                $body2 = preg_replace(
                    '/\[\[\s*!?msOrder\??([^\]]*)\]\]/i',
                    '<div id="msOrder">[[!msOrder$1]]</div>',
                    $body,
                    1
                );
                if (is_string($body2) && $body2 !== '') {
                    $body = $body2;
                }
            }

            if ($body === $orig) {
                continue;
            }
            $msg = 'Cart page «' . $t['name'] . '» updated for MS3 (actions, product_key, #msCart)';
            if ($apply) {
                try {
                    $this->persistTarget($t, $body, $orig, $journal);
                    $done[] = array('id' => 'cart_page_' . $t['name'], 'message' => $msg, 'applied' => true);
                } catch (Exception $e) {
                    $needs[] = array('id' => 'cart_page_fail', 'message' => $e->getMessage(), 'name' => $t['name']);
                } catch (Throwable $e) {
                    $needs[] = array('id' => 'cart_page_fail', 'message' => $e->getMessage(), 'name' => $t['name']);
                }
            } else {
                $done[] = array('id' => 'cart_page_' . $t['name'], 'message' => $msg, 'applied' => false);
            }
        }

        return array('done' => $done, 'needs' => $needs);
    }

    /**
     * Strip MS2 dynamic minicart JS; neutralize miniShop2.* calls in theme scripts.
     */
    protected function healThemeScripts($apply, array &$journal)
    {
        $done = array();
        $needs = array();
        foreach ($this->collectTargets() as $t) {
            if ($t['source'] !== 'chunk' && $t['source'] !== 'template') {
                continue;
            }
            $body = $t['code'];
            $orig = $body;
            $changed = false;
            if (preg_match('/msminicartdynamic\.js/i', $body)) {
                $body = preg_replace(
                    '/\s*<script[^>]+msminicartdynamic\.js[^>]*>\s*<\/script>/i',
                    "\n<!-- mmx: removed msminicartdynamic.js (MS2) -->",
                    $body
                );
                $changed = true;
            }
            if (preg_match('/components\/minishop2\/js/i', $body)) {
                $body = preg_replace(
                    '/\s*<script[^>]+components\/minishop2\/js[^>]*>\s*<\/script>/i',
                    "\n<!-- mmx: removed minishop2.js — MiniShop3 plugin registers ms3 assets -->",
                    $body
                );
                $changed = true;
            }
            if (preg_match('/miniShop2\.(Cart|Order)\.initialize\s*\(\s*\)/', $body)) {
                $body = preg_replace(
                    '/miniShop2\.(Cart|Order)\.initialize\s*\(\s*\)\s*;?/',
                    '/* mmx: miniShop2.$1.initialize removed — use MiniShop3 */',
                    $body
                );
                $changed = true;
            }
            /* Hover AJAX that POSTed for minicart HTML → show server-rendered #minicart-content */
            if (preg_match('/#msMiniCart[\'"]\)\.mouseenter[\s\S]{0,800}?miniShop2\.Cart\.initialize/i', $body)
                || preg_match('/#msMiniCart[\'"]\)\.mouseenter[\s\S]{0,800}?#minicart-content[\'"]\)\.html\s*\(\s*data\s*\)/i', $body)) {
                $replacement = "<script>\n"
                    . "    \$('#msMiniCart').mouseenter(function(e) {\n"
                    . "        e.preventDefault();\n"
                    . "        \$('body').addClass('no-overflow');\n"
                    . "        \$('#minicart-content').stop(true, true).slideDown();\n"
                    . "    });\n"
                    . "    \$(\"#minicart-content\").mouseleave(function(){\n"
                    . "        \$('body').removeClass('no-overflow');\n"
                    . "        \$(this).slideUp();\n"
                    . "    });\n"
                    . "</script>";
                $body2 = preg_replace(
                    '/<script>\s*\$\([\'"]#msMiniCart[\'"]\)\.mouseenter\([\s\S]*?#minicart-content[\'"]\)\.mouseleave\([\s\S]*?<\/script>/i',
                    $replacement,
                    $body,
                    1
                );
                if ($body2 && $body2 !== $body) {
                    $body = $body2;
                    $changed = true;
                } else {
                    $needs[] = array(
                        'id' => 'minicart_ajax_js',
                        'message' => 'Theme JS still AJAX-loads minicart into #minicart-content @ '
                            . $t['source'] . ':' . $t['name']
                            . ' — replace with slideDown on server-rendered [[!msMiniCart]].',
                        'severity' => 'soft',
                        'name' => $t['name'],
                    );
                }
            }
            if (!$changed || $body === $orig) {
                continue;
            }
            $msg = 'Theme scripts cleaned of MS2 minicart/shop JS @ ' . $t['source'] . ':' . $t['name'];
            if ($apply) {
                try {
                    $this->persistTarget($t, $body, $orig, $journal);
                    $done[] = array('id' => 'theme_scripts_' . $t['name'], 'message' => $msg, 'applied' => true);
                } catch (Exception $e) {
                    $needs[] = array('id' => 'theme_scripts_fail', 'message' => $e->getMessage());
                } catch (Throwable $e) {
                    $needs[] = array('id' => 'theme_scripts_fail', 'message' => $e->getMessage());
                }
            } else {
                $done[] = array('id' => 'theme_scripts_' . $t['name'], 'message' => $msg, 'applied' => false);
            }
        }
        return array('done' => $done, 'needs' => $needs);
    }

    /**
     * Snippets still pointing at quarantined/missing extras (Tickets, …) Fatal on getService→null.
     * Stub them so product/category pages render; Attention notes theme cleanup.
     */
    protected function stubOrphanComponentSnippets($apply, array &$journal)
    {
        $done = array();
        $needs = array();
        $core = defined('MODX_CORE_PATH') ? MODX_CORE_PATH : '';
        if ($core === '') {
            return array('done' => $done, 'needs' => $needs);
        }
        /* component folder name => snippet name prefixes / exact names */
        $map = array(
            'tickets' => array(
                'TicketComments', 'TicketForm', 'TicketLatest', 'TicketMeta',
                'getTickets', 'getTicketsSections', 'getComments', 'subscribeAuthor',
            ),
            'looked' => array('looked'),
            'xlike' => array('xLike', 'xlike'),
            'minishop2' => array(), /* shop snippets remapped separately */
            'msminicartdynamic' => array('msMiniCartDynamic', 'msDynamicCount'),
        );
        foreach ($map as $comp => $names) {
            $alive = is_dir($core . 'components/' . $comp . '/');
            if ($alive) {
                continue;
            }
            /* Collect by static_file path even if name list incomplete */
            foreach ($this->collectTargets() as $t) {
                if ($t['source'] !== 'snippet') {
                    continue;
                }
                $snip = $this->getSnippet($t['name']);
                if (!$snip) {
                    continue;
                }
                $file = str_replace('\\', '/', (string)$snip->get('static_file'));
                $code = (string)$snip->get('snippet');
                $svc = ($comp === 'tickets') ? 'tickets' : $comp;
                $hit = (stripos($file, 'components/' . $comp . '/') !== false)
                    || (stripos($code, 'components/' . $comp . '/') !== false)
                    || (stripos($code, "getService('" . $svc . "'") !== false)
                    || (stripos($code, 'getService("' . $svc . '"') !== false)
                    || in_array($t['name'], $names, true);
                if (!$hit && $names) {
                    foreach ($names as $n) {
                        if (strcasecmp($t['name'], $n) === 0) {
                            $hit = true;
                            break;
                        }
                    }
                }
                if (!$hit) {
                    continue;
                }
                /* Already stubbed */
                if (preg_match('/^\s*<\?php\s+return\s+[\'\"]{2}\s*;/s', $code)
                    || trim($code) === "<?php\nreturn '';\n"
                    || strpos($code, 'mmx: stub') !== false) {
                    continue;
                }
                $msg = 'Snippet «' . $t['name'] . '» stubs — component «' . $comp . '» missing/quarantined (prevents Fatal initialize-on-null)';
                $needs[] = array(
                    'id' => 'orphan_snip_' . $comp,
                    'severity' => 'soft',
                    'message' => 'Theme still calls [[' . $t['name'] . ']] but «' . $comp
                        . '» is quarantined — stubbed empty; remove tags from templates when ready.',
                    'name' => $t['name'],
                );
                if ($apply) {
                    $stub = "<?php\n/* mmx: stub — component {$comp} unavailable after MODX 3 migrate */\nreturn '';\n";
                    $before = array(
                        'static' => $snip->get('static'),
                        'static_file' => $snip->get('static_file'),
                        'snippet' => $code,
                    );
                    $snip->set('static', 0);
                    $snip->set('static_file', '');
                    $snip->set('snippet', $stub);
                    if ($snip->save()) {
                        $journal['items'][] = array(
                            'type' => 'snippet_stub_orphan',
                            'name' => $t['name'],
                            'component' => $comp,
                            'before' => $before,
                        );
                        $done[] = array(
                            'id' => 'orphan_stub_' . $t['name'],
                            'message' => $msg,
                            'applied' => true,
                        );
                    }
                } else {
                    $done[] = array('id' => 'orphan_stub_' . $t['name'], 'message' => $msg, 'applied' => false);
                }
            }
        }
        return array('done' => $done, 'needs' => $needs);
    }

    /**
     * MiniShop3 frontend assets are registered by its plugin — ensure it is enabled.
     */
    protected function enableMiniShop3Plugin($apply, array &$journal)
    {
        $done = array();
        $failed = array();
        $classes = array('modPlugin');
        if (class_exists('\\MODX\\Revolution\\modPlugin')) {
            array_unshift($classes, '\\MODX\\Revolution\\modPlugin');
        }
        $plugin = null;
        foreach ($classes as $cls) {
            try {
                $plugin = $this->modx->getObject($cls, array('name' => 'MiniShop3'));
                if ($plugin) {
                    break;
                }
            } catch (Exception $e) {
            } catch (Throwable $e) {
            }
        }
        if (!$plugin) {
            return array('done' => $done, 'failed' => $failed);
        }
        if (!(int)$plugin->get('disabled')) {
            $done[] = array(
                'id' => 'ms3_plugin_ok',
                'message' => 'MiniShop3 plugin already enabled (registers frontend assets)',
                'applied' => false,
            );
            return array('done' => $done, 'failed' => $failed);
        }
        $msg = 'Enabled MiniShop3 plugin (frontend CSS/JS)';
        if ($apply) {
            $plugin->set('disabled', 0);
            if ($plugin->save()) {
                $journal['items'][] = array('type' => 'plugin_enable', 'name' => 'MiniShop3');
                $done[] = array('id' => 'ms3_plugin_enable', 'message' => $msg, 'applied' => true);
            } else {
                $failed[] = array('id' => 'ms3_plugin_enable', 'message' => 'Could not enable MiniShop3 plugin');
            }
        } else {
            $done[] = array('id' => 'ms3_plugin_enable', 'message' => $msg, 'applied' => false);
        }
        return array('done' => $done, 'failed' => $failed);
    }

    /**
     * Product listing cards often embed [[msGallery]] (full page widget). Swap to thumb/image for grids.
     */
    protected function healListingThumbs($apply, array &$journal)
    {
        $done = array();
        $needs = array();
        $pattern = '/\[\[\s*!?\s*msGallery\s*\?\s*&product=`\[\[\+id\]\]`\s*\]\]/i';
        $replacement = '<img src="[[+thumb:default=`[[+image]]`:default=`assets/images/product-no-pic.jpg`]]" alt="[[+pagetitle]]" class="img-fluid">';
        foreach ($this->collectTargets() as $t) {
            if ($t['source'] !== 'chunk' && $t['source'] !== 'template') {
                continue;
            }
            /* Only listing-ish names or when gallery is clearly used as card thumb */
            $name = strtolower($t['name']);
            $listing = (strpos($name, 'product') !== false || strpos($name, 'card') !== false
                || strpos($name, 'row') !== false || strpos($name, 'list') !== false
                || strpos($name, 'grid') !== false || strpos($name, 'teaser') !== false);
            if (!$listing && !preg_match('/msGallery\?[^]]*product=`\[\[\+id\]\]`/i', $t['code'])) {
                continue;
            }
            if (!preg_match($pattern, $t['code'])) {
                continue;
            }
            $code = preg_replace($pattern, $replacement, $t['code']);
            if ($code === null || $code === $t['code']) {
                continue;
            }
            $msg = 'Listing «' . $t['name'] . '»: msGallery(product) → thumb/image (MS3 gallery is a full widget)';
            if ($apply) {
                try {
                    $this->persistTarget($t, $code, $t['code'], $journal);
                    $done[] = array('id' => 'listing_thumb_' . $t['name'], 'message' => $msg, 'applied' => true);
                } catch (Exception $e) {
                    $needs[] = array('id' => 'listing_thumb_fail', 'message' => $e->getMessage(), 'name' => $t['name']);
                } catch (Throwable $e) {
                    $needs[] = array('id' => 'listing_thumb_fail', 'message' => $e->getMessage(), 'name' => $t['name']);
                }
            } else {
                $done[] = array('id' => 'listing_thumb_' . $t['name'], 'message' => $msg, 'applied' => false);
            }
        }
        return array('done' => $done, 'needs' => $needs);
    }

    protected function patchPhpThumbOn($apply, array &$journal)
    {
        $done = array();
        $failed = array();
        $path = (defined('MODX_CORE_PATH') ? MODX_CORE_PATH : '') . 'components/phpthumbon/model/phpthumbon/phpthumbon.class.php';
        if (!is_file($path)) {
            return array('done' => $done, 'failed' => $failed);
        }
        $src = (string)file_get_contents($path);
        $makeDirUsesHandler = (bool)preg_match(
            '/function\s+makeDir\s*\([^)]*\)\s*\{[^}]*modFileHandler/s',
            $src
        );
        if (!$makeDirUsesHandler) {
            $done[] = array(
                'id' => 'phpthumbon_ok',
                'message' => 'phpThumbOn::makeDir already MODX-3-safe (no modFileHandler)',
                'applied' => false,
            );
            return array('done' => $done, 'failed' => $failed);
        }
        $newMethod = "    public function makeDir(\$dir)\n"
            . "    {\n"
            . "        \$flag = true;\n"
            . "        if (!file_exists(\$dir)) {\n"
            . "            /* MODX 3: modFileHandler removed */\n"
            . "            if (!@mkdir(\$dir, 0755, true) && !is_dir(\$dir)) {\n"
            . "                \$flag = false;\n"
            . "                \$this->modx->log(modX::LOG_LEVEL_ERROR, '[phpthumbon] Could not create cache directory ' . \$dir);\n"
            . "            }\n"
            . "        }\n"
            . "        return \$flag;\n"
            . "    }";
        $src2 = preg_replace('/public function makeDir\(\$dir\)\s*\{.*?\n    \}/s', trim($newMethod), $src, 1);
        if (!$src2 || $src2 === $src) {
            /* Soft: site may already work if thumbs were cached; do not hard-fail heal */
            $done[] = array(
                'id' => 'phpthumbon_patch_skip',
                'message' => 'phpThumbOn::makeDir still references modFileHandler but auto-patch did not match — patch manually or ignore if thumbs already render',
                'applied' => false,
            );
            return array('done' => $done, 'failed' => $failed);
        }
        if ($apply) {
            $bak = $path . '.__mmx_pre_modx3.bak';
            if (!is_file($bak)) {
                @copy($path, $bak);
            }
            if (@file_put_contents($path, $src2) === false) {
                $failed[] = array('id' => 'phpthumbon_patch', 'message' => 'phpThumbOn write failed');
            } else {
                $journal['items'][] = array('type' => 'file_patch', 'path' => $path);
                $done[] = array(
                    'id' => 'phpthumbon_patch',
                    'message' => 'phpThumbOn::makeDir patched for MODX 3 (mkdir instead of modFileHandler)',
                    'applied' => true,
                );
            }
        } else {
            $done[] = array(
                'id' => 'phpthumbon_patch',
                'message' => 'phpThumbOn needs makeDir patch for MODX 3',
                'applied' => false,
            );
        }
        return array('done' => $done, 'failed' => $failed);
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
                $nameField = $source === 'template' ? 'templatename' : 'name';
                $name = (string)$el->get($nameField);
                if ($name === '' && $source === 'template') {
                    $name = (string)$el->get('name');
                }
                $out[] = array(
                    'source' => $source,
                    'id' => (int)$el->get('id'),
                    'name' => $name,
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
        $el = $this->modx->getObject($t['class'], (int)$t['id']);
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
            'name' => $t['name'],
            'field' => $t['field'],
            'before' => $orig,
        );
    }

    protected function getSnippet($name)
    {
        $classes = array('modSnippet');
        if (class_exists('\\MODX\\Revolution\\modSnippet')) {
            array_unshift($classes, '\\MODX\\Revolution\\modSnippet');
        }
        foreach ($classes as $cls) {
            try {
                $obj = $this->modx->getObject($cls, array('name' => $name));
                if ($obj) {
                    return $obj;
                }
            } catch (Exception $e) {
            } catch (Throwable $e) {
            }
        }
        return null;
    }

    /**
     * @param string $name
     * @return object|null
     */
    protected function newSnippet($name)
    {
        if (!is_object($this->modx) || !method_exists($this->modx, 'newObject')) {
            return null;
        }
        $classes = array('modSnippet');
        if (class_exists('\\MODX\\Revolution\\modSnippet')) {
            array_unshift($classes, '\\MODX\\Revolution\\modSnippet');
        }
        foreach ($classes as $cls) {
            try {
                $obj = $this->modx->newObject($cls);
                if ($obj) {
                    $obj->set('name', $name);
                    return $obj;
                }
            } catch (Exception $e) {
            } catch (Throwable $e) {
            }
        }
        return null;
    }

    /**
     * MS3 ms3_products.php reads $scriptProperties['return'] (not merged defaults).
     * Empty properties after PM recreate → empty HTML listing despite products in DB.
     *
     * @param object $snip
     * @param string $name
     */
    protected function applyMs3SnippetDefaults($snip, $name)
    {
        if (!is_object($snip) || !method_exists($snip, 'setProperties')) {
            return;
        }
        $map = array(
            'msProducts' => array(
                'tpl' => array(
                    'name' => 'tpl', 'desc' => '', 'type' => 'textfield',
                    'options' => array(), 'value' => 'tpl.msProducts.row', 'lexicon' => null,
                ),
                'return' => array(
                    'name' => 'return', 'desc' => '', 'type' => 'textfield',
                    'options' => array(), 'value' => 'data', 'lexicon' => null,
                ),
            ),
            'msCart' => array(
                'tpl' => array(
                    'name' => 'tpl', 'desc' => '', 'type' => 'textfield',
                    'options' => array(), 'value' => 'tpl.msCart', 'lexicon' => null,
                ),
            ),
            'msGallery' => array(
                'tpl' => array(
                    'name' => 'tpl', 'desc' => '', 'type' => 'textfield',
                    'options' => array(), 'value' => 'tpl.msGallery', 'lexicon' => null,
                ),
                /* MS3 ms3_gallery defaults return=data → prints "Array" in templates */
                'return' => array(
                    'name' => 'return', 'desc' => '', 'type' => 'textfield',
                    'options' => array(), 'value' => 'tpl', 'lexicon' => null,
                ),
            ),
            'msOrder' => array(
                'tpl' => array(
                    'name' => 'tpl', 'desc' => '', 'type' => 'textfield',
                    'options' => array(), 'value' => 'tpl.msOrder', 'lexicon' => null,
                ),
            ),
        );
        if (!isset($map[$name])) {
            return;
        }
        $existing = array();
        try {
            if (method_exists($snip, 'getProperties')) {
                $existing = (array)$snip->getProperties();
            }
        } catch (Exception $e) {
            $existing = array();
        } catch (Throwable $e) {
            $existing = array();
        }
        foreach ($map[$name] as $key => $prop) {
            $cur = isset($existing[$key]) ? trim((string)$existing[$key]) : '';
            if ($cur === '') {
                $existing[$key] = $prop['value'];
            }
        }
        /* Prefer full property meta when available */
        $meta = $map[$name];
        foreach ($existing as $key => $val) {
            if (isset($meta[$key]) && is_array($meta[$key])) {
                $meta[$key]['value'] = is_array($val) && isset($val['value']) ? $val['value'] : $val;
            } else {
                $meta[$key] = array(
                    'name' => $key, 'desc' => '', 'type' => 'textfield',
                    'options' => array(),
                    'value' => is_array($val) && isset($val['value']) ? $val['value'] : $val,
                    'lexicon' => null,
                );
            }
        }
        $snip->setProperties($meta);
    }

    /**
     * @param object $snip
     * @param string $name
     * @return bool
     */
    protected function snippetNeedsMs3Defaults($snip, $name)
    {
        if (!in_array($name, array('msProducts', 'msCart', 'msGallery', 'msOrder'), true)) {
            return false;
        }
        $props = array();
        try {
            if (method_exists($snip, 'getProperties')) {
                $props = (array)$snip->getProperties();
            }
        } catch (Exception $e) {
            return true;
        } catch (Throwable $e) {
            return true;
        }
        if ($name === 'msProducts') {
            $ret = isset($props['return']) ? trim((string)$props['return']) : '';
            return $ret === '';
        }
        if ($name === 'msGallery') {
            $ret = isset($props['return']) ? trim((string)$props['return']) : '';
            return ($ret === '' || $ret === 'data');
        }
        $tpl = isset($props['tpl']) ? trim((string)$props['tpl']) : '';
        return $tpl === '';
    }

    /**
     * MS3 gallery snippet defaults to return=data (array). Themes need return=tpl or literal "Array".
     */
    protected function healMsGalleryCalls($apply, array &$journal)
    {
        $done = array();
        $needs = array();
        foreach ($this->collectTargets() as $t) {
            if ($t['source'] !== 'chunk' && $t['source'] !== 'template') {
                continue;
            }
            $code = $t['code'];
            if (!preg_match('/\[\[\s*!?\s*msGallery\b/i', $code)) {
                continue;
            }
            $orig = $code;
            /* Add &return=`tpl` when missing inside msGallery tags */
            $code = preg_replace_callback(
                '/\[\[\s*(!?)\s*msGallery(\s*\?[^]]*?)?\]\]/i',
                function ($m) {
                    $bang = $m[1];
                    $props = isset($m[2]) ? $m[2] : '';
                    if (preg_match('/&return=`[^`]*`/i', $props) || preg_match('/&return=`[^`]*`/i', $props)) {
                        $props = preg_replace('/&return=`data`/i', '&return=`tpl`', $props);
                        if (!preg_match('/&return=`/i', $props)) {
                            $props .= "\n                                        &return=`tpl`";
                        }
                    } else {
                        if ($props === '' || $props === null) {
                            $props = "?\n                                        &return=`tpl`";
                        } else {
                            $props .= "\n                                        &return=`tpl`";
                        }
                    }
                    return '[[' . $bang . 'msGallery' . $props . ']]';
                },
                $code
            );
            if ($code === null || $code === $orig) {
                continue;
            }
            $msg = 'msGallery → &return=`tpl` @ ' . $t['source'] . ':' . $t['name'];
            if ($apply) {
                try {
                    $this->persistTarget($t, $code, $orig, $journal);
                    $done[] = array('id' => 'msGallery_return_tpl_' . $t['name'], 'message' => $msg, 'applied' => true);
                } catch (Exception $e) {
                    $needs[] = array('id' => 'msGallery_return_fail', 'message' => $e->getMessage());
                } catch (Throwable $e) {
                    $needs[] = array('id' => 'msGallery_return_fail', 'message' => $e->getMessage());
                }
            } else {
                $done[] = array('id' => 'msGallery_return_tpl_' . $t['name'], 'message' => $msg, 'applied' => false);
            }
        }
        return array('done' => $done, 'needs' => $needs);
    }

    /**
     * Theme chunks embed $("#…") / .fancybox() in header before jQuery + deferred plugins.js.
     * Wait for window load and required jQuery plugins (not just jQuery itself).
     */
    protected function healJqueryGuards($apply, array &$journal)
    {
        $done = array();
        $needs = array();
        foreach ($this->collectTargets() as $t) {
            if ($t['source'] !== 'chunk' && $t['source'] !== 'template') {
                continue;
            }
            $code = $t['code'];
            $orig = $code;
            $self = $this;
            /*
             * Always re-emit the WaitJq bootstrap when present.
             * Partial renames left `(!need||!deps.length)` → ReferenceError; Fenom also eats `{need=`.
             */
            if (strpos($code, 'mmxWaitJqDeps') !== false) {
                $boot = $this->mmxWaitJqBootstrap();
                $code2 = preg_replace(
                    '/\(function\s+mmxWaitJqDeps\s*\(\s*fn\s*,\s*(?:need|deps)\s*\)\s*\{[\s\S]*?\}\s*\)(?=\s*\(\s*function\s*\(\s*\$\s*\))/',
                    $boot,
                    $code
                );
                if (is_string($code2) && $code2 !== '') {
                    $code = $code2;
                }
                /* Belt-and-suspenders for any leftover identifier from partial heals */
                $code = str_replace('(!need||!deps.length)', '(!deps||!deps.length)', $code);
                $code = str_replace('(!need||!need.length)', '(!deps||!deps.length)', $code);
                $code = str_replace('mmxWaitJqDeps(fn,need){need=need||[];', 'mmxWaitJqDeps(fn,deps){deps=deps||[];', $code);
                $code = str_replace('mmxWaitJqDeps(fn,need)', 'mmxWaitJqDeps(fn,deps)', $code);
            }
            /* Upgrade legacy mmxWaitJq that only waited for jQuery (broke .fancybox / deferred plugins) */
            if (strpos($code, 'mmxWaitJq') !== false && strpos($code, 'mmxWaitJqDeps') === false) {
                $code = preg_replace(
                    '/\(function mmxWaitJq\(fn\)\{\s*if\s*\(window\.jQuery\)\s*\{\s*fn\(window\.jQuery\);\s*\}\s*else\s*\{\s*setTimeout\(function\(\)\{\s*mmxWaitJq\(fn\);\s*\},\s*\d+\);\s*\}\s*\}\)\(function\(\$\)\{/i',
                    $this->mmxWaitJqBootstrap() . '(function($){',
                    $code
                );
            }
            $code = preg_replace_callback(
                '/(?:\{ignore\}\s*)?<script(\s[^>]*)?>\s*([\s\S]*?)<\/script>(?:\s*\{\/ignore\})?/i',
                function ($m) use ($self, $code) {
                    $attrs = isset($m[1]) ? $m[1] : '';
                    $body = $m[2];
                    if (stripos($attrs, 'src=') !== false) {
                        return $m[0];
                    }
                    /* Re-wrap already-guarded scripts for Fenom safety */
                    if (strpos($body, 'mmxWaitJqDeps') !== false) {
                        $script = '<script' . $attrs . '>' . $body . '</script>';
                        return $self->fenomSafeScript($script, $code);
                    }
                    if (strpos($body, 'mmxWaitJq') !== false && strpos($body, 'mmxWaitJqDeps') === false) {
                        return $m[0];
                    }
                    $trim = ltrim($body);
                    if ($trim === '' || ($trim[0] !== '$' && stripos($trim, 'jQuery') !== 0)) {
                        /* Still Fenom-protect any inline script with curly braces */
                        if (strpos($body, '{') !== false) {
                            $script = '<script' . $attrs . '>' . $body . '</script>';
                            return $self->fenomSafeScript($script, $code);
                        }
                        return $m[0];
                    }
                    $deps = array();
                    if (preg_match('/\.fancybox\s*\(/', $body)) {
                        $deps[] = 'fancybox';
                    }
                    if (preg_match('/\.(slick|owlCarousel|select2)\s*\(/', $body, $pm)) {
                        $deps[] = $pm[1];
                    }
                    $depJs = $deps ? ("['" . implode("','", $deps) . "']") : '[]';
                    $script = "<script>\n" . $self->mmxWaitJqBootstrap()
                        . "(function($){\n"
                        . $body
                        . "\n}, " . $depJs . ");\n</script>";
                    return $self->fenomSafeScript($script, $code);
                },
                $code
            );
            if ($code === null || $code === $orig) {
                continue;
            }
            $msg = 'jQuery/plugin wait-guard (Fenom-safe deps) @ ' . $t['source'] . ':' . $t['name'];
            if ($apply) {
                try {
                    $this->persistTarget($t, $code, $orig, $journal);
                    $done[] = array('id' => 'jquery_guard_' . $t['name'], 'message' => $msg, 'applied' => true);
                } catch (Exception $e) {
                    $needs[] = array('id' => 'jquery_guard_fail', 'message' => $e->getMessage());
                } catch (Throwable $e) {
                    $needs[] = array('id' => 'jquery_guard_fail', 'message' => $e->getMessage());
                }
            } else {
                $done[] = array('id' => 'jquery_guard_' . $t['name'], 'message' => $msg, 'applied' => false);
            }
        }
        return array('done' => $done, 'needs' => $needs);
    }

    /**
     * Returns an IIFE factory: mmxWaitJqDeps(fn, needArray).
     * Call as: mmxWaitJqBootstrap() . '(function($){ ... }, ["fancybox"]);'
     *
     * @return string
     */
    protected function mmxWaitJqBootstrap()
    {
        /* Param must NOT be named `need` — Fenom treats `{need=` inside <script> as a tag and blanks the chunk. */
        return '(function mmxWaitJqDeps(fn,deps){'
            . 'deps=deps||[];'
            . 'if((!deps||!deps.length)&&typeof fn==="function"){'
            . 'var s=Function.prototype.toString.call(fn);'
            . 'if(/\\.fancybox\\s*\\(/.test(s))deps.push("fancybox");'
            . '}'
            . 'function has(d){'
            . 'if(d==="fancybox")return!(!window.jQuery||!jQuery.fn||!jQuery.fn.fancybox);'
            . 'if(d==="slick")return!(!window.jQuery||!jQuery.fn||!jQuery.fn.slick);'
            . 'if(d==="owlCarousel")return!(!window.jQuery||!jQuery.fn||!jQuery.fn.owlCarousel);'
            . 'if(d==="select2")return!(!window.jQuery||!jQuery.fn||!jQuery.fn.select2);'
            . 'return true;'
            . '}'
            . 'function ready(){if(!window.jQuery)return false;for(var i=0;i<deps.length;i++)if(!has(deps[i]))return false;return true;}'
            . 'function tick(){if(ready()){try{fn(window.jQuery);}catch(e){if(window.console)console.error("mmxWaitJq",e);}}else setTimeout(tick,50);}'
            . 'if(document.readyState==="complete")tick();else window.addEventListener("load",tick);'
            . '})';
    }

    /**
     * Wrap inline <script> for Fenom chunks so `{…}` in JS is not parsed as Fenom tags.
     *
     * @param string $scriptHtml full <script>…</script>
     * @param string $chunkCode
     * @return string
     */
    protected function fenomSafeScript($scriptHtml, $chunkCode)
    {
        $isFenom = (bool)preg_match('/\{(?:if|foreach|else|include|var|set)\b/', $chunkCode)
            || (bool)preg_match('/\{\$[a-zA-Z_]/', $chunkCode);
        if (!$isFenom) {
            return $scriptHtml;
        }
        if (stripos($scriptHtml, '{ignore}') !== false) {
            return $scriptHtml;
        }
        return "{ignore}\n" . $scriptHtml . "\n{/ignore}";
    }

    /**
     * MiniShop3 reads FormData(form).get('ms3_action') — submit button name/value is NOT included.
     * Inject matching hidden inputs into each ms3_form that only has the action on a button.
     */
    protected function healMs3ActionInputs($apply, array &$journal)
    {
        $done = array();
        $needs = array();
        foreach ($this->collectTargets() as $t) {
            if ($t['source'] !== 'chunk' && $t['source'] !== 'template') {
                continue;
            }
            $orig = $t['code'];
            $code = $this->injectMs3ActionHiddenInputs($orig);
            if ($code === $orig) {
                continue;
            }
            $msg = 'Hidden ms3_action inputs for cart FormData @ ' . $t['source'] . ':' . $t['name'];
            if ($apply) {
                try {
                    $this->persistTarget($t, $code, $orig, $journal);
                    $done[] = array('id' => 'ms3_action_' . $t['name'], 'message' => $msg, 'applied' => true);
                } catch (Exception $e) {
                    $needs[] = array('id' => 'ms3_action_fail', 'message' => $e->getMessage());
                } catch (Throwable $e) {
                    $needs[] = array('id' => 'ms3_action_fail', 'message' => $e->getMessage());
                }
            } else {
                $done[] = array('id' => 'ms3_action_' . $t['name'], 'message' => $msg, 'applied' => false);
            }
        }
        return array('done' => $done, 'needs' => $needs);
    }

    /**
     * @param string $code
     * @return string
     */
    public function injectMs3ActionHiddenInputs($code)
    {
        if ($code === '' || stripos($code, 'ms3_action') === false) {
            return $code;
        }
        return preg_replace_callback(
            '/<form\b([^>]*)>([\s\S]*?)<\/form>/i',
            function ($m) {
                $attrs = $m[1];
                $inner = $m[2];
                $isMs3 = (bool)preg_match('/\bms3_form\b/i', $attrs)
                    || (bool)preg_match('/\bdata-ms3-form\b/i', $attrs)
                    || (bool)preg_match('/\bms3_form\b/i', $inner);
                if (!$isMs3) {
                    return $m[0];
                }
                if (preg_match('/<input\b[^>]*\bname=["\']ms3_action["\']/i', $inner)) {
                    return $m[0];
                }
                $action = '';
                if (preg_match('/<(?:button|input)\b[^>]*\bname=["\']ms3_action["\'][^>]*\bvalue=["\']([^"\']+)["\']/i', $inner, $vm)
                    || preg_match('/<(?:button|input)\b[^>]*\bvalue=["\']([^"\']+)["\'][^>]*\bname=["\']ms3_action["\']/i', $inner, $vm)
                ) {
                    $action = $vm[1];
                }
                if ($action === '') {
                    return $m[0];
                }
                $safe = htmlspecialchars($action, ENT_QUOTES, 'UTF-8');
                $hidden = "\n" . '<input type="hidden" name="ms3_action" value="' . $safe . '">';
                return '<form' . $attrs . '>' . $hidden . $inner . '</form>';
            },
            $code
        );
    }

    /**
     * Capture-phase polyfill: before ms3.js reads FormData, copy submitter ms3_action into a hidden field.
     * Covers Fenom/static markup Heal cannot rewrite and multi-button forms.
     */
    protected function healMs3SubmitterPolyfill($apply, array &$journal)
    {
        $done = array();
        $needs = array();
        $marker = 'data-mmx-ms3-submitter';
        $snippet = $this->ms3SubmitterPolyfillScript() . $this->ms3CartLiveSyncScript();
        $injected = false;
        $candidates = array();
        foreach ($this->collectTargets() as $t) {
            if ($t['source'] !== 'chunk' && $t['source'] !== 'template') {
                continue;
            }
            $code = $t['code'];
            if (strpos($code, $marker) !== false && strpos($code, 'data-mmx-ms3-cart-sync') !== false) {
                $injected = true;
                break;
            }
            /* Upgrade older polyfill-only inject */
            if (strpos($code, $marker) !== false && strpos($code, 'data-mmx-ms3-cart-sync') === false) {
                $orig = $code;
                $code = str_replace('</script>', '</script>' . $this->ms3CartLiveSyncScript(), $code);
                /* only first polyfill script close */
                if ($code === $orig) {
                    $code = $orig . "\n" . $this->ms3CartLiveSyncScript();
                } else {
                    /* str_replace all is too broad — re-do surgically */
                    $code = $orig;
                    $pos = strpos($code, $marker);
                    if ($pos !== false) {
                        $end = strpos($code, '</script>', $pos);
                        if ($end !== false) {
                            $code = substr($code, 0, $end + 9) . $this->ms3CartLiveSyncScript() . substr($code, $end + 9);
                        }
                    }
                }
                if ($code !== $orig) {
                    $msg = 'MS3 cart live-sync after polyfill @ ' . $t['source'] . ':' . $t['name'];
                    if ($apply) {
                        try {
                            $this->persistTarget($t, $code, $orig, $journal);
                            $done[] = array('id' => 'ms3_cart_sync_' . $t['name'], 'message' => $msg, 'applied' => true);
                            $injected = true;
                        } catch (Exception $e) {
                            $needs[] = array('id' => 'ms3_cart_sync_fail', 'message' => $e->getMessage());
                        } catch (Throwable $e) {
                            $needs[] = array('id' => 'ms3_cart_sync_fail', 'message' => $e->getMessage());
                        }
                    } else {
                        $done[] = array('id' => 'ms3_cart_sync_' . $t['name'], 'message' => $msg, 'applied' => false);
                        $injected = true;
                    }
                }
                break;
            }
            $score = 0;
            if (preg_match('/<\/body>/i', $code)) {
                $score += 100;
            }
            if (preg_match('/minishop3\/js|ms3\.js/i', $code)) {
                $score += 50;
            }
            if (preg_match('/footer|scripts|head|layout|wrapper|base/i', $t['name'])) {
                $score += 30;
            }
            if (preg_match('/plugins\.js|jquery/i', $code)) {
                $score += 10;
            }
            if ($score < 30) {
                continue;
            }
            $candidates[] = array('t' => $t, 'score' => $score);
        }
        if (!$injected && $candidates) {
            usort($candidates, function ($a, $b) {
                return $b['score'] - $a['score'];
            });
            $t = $candidates[0]['t'];
            $orig = $t['code'];
            $code = $orig;
            if (preg_match('/<\/body>/i', $code)) {
                $code = preg_replace('/<\/body>/i', $snippet . "\n</body>", $code, 1);
            } else {
                $code .= "\n" . $snippet;
            }
            $msg = 'MS3 submitter FormData polyfill @ ' . $t['source'] . ':' . $t['name'];
            if ($apply && $code !== $orig) {
                try {
                    $this->persistTarget($t, $code, $orig, $journal);
                    $done[] = array('id' => 'ms3_submitter_' . $t['name'], 'message' => $msg, 'applied' => true);
                    $injected = true;
                } catch (Exception $e) {
                    $needs[] = array('id' => 'ms3_submitter_fail', 'message' => $e->getMessage());
                } catch (Throwable $e) {
                    $needs[] = array('id' => 'ms3_submitter_fail', 'message' => $e->getMessage());
                }
            } elseif ($code !== $orig) {
                $done[] = array('id' => 'ms3_submitter_' . $t['name'], 'message' => $msg, 'applied' => false);
                $injected = true;
            }
        }
        if (!$injected) {
            $needs[] = array(
                'id' => 'ms3_submitter_missing',
                'message' => 'Could not place MS3 submitter polyfill — add hidden name=ms3_action to cart forms manually.',
                'severity' => 'hard',
            );
        }
        return array('done' => $done, 'needs' => $needs);
    }

    /**
     * @return string
     */
    protected function ms3SubmitterPolyfillScript()
    {
        return '<script data-mmx-ms3-submitter="1">'
            . '(function(){if(window.__mmxMs3Submitter)return;window.__mmxMs3Submitter=1;'
            . 'document.addEventListener("submit",function(e){'
            . 'var f=e.target;if(!f||!f.matches||!f.matches("[data-ms3-form],.ms3_form"))return;'
            . 'var s=e.submitter||null;'
            . 'if(!s||s.name!=="ms3_action"){'
            . 'var ae=document.activeElement;'
            . 'if(ae&&ae.name==="ms3_action"&&f.contains(ae))s=ae;'
            . '}'
            . 'if(!s||s.name!=="ms3_action"||!s.value)return;'
            . 'var h=f.querySelector("input[type=hidden][name=ms3_action]");'
            . 'if(!h){h=document.createElement("input");h.type="hidden";h.name="ms3_action";'
            . 'h.setAttribute("data-mmx-ms3-action","1");f.appendChild(h);}'
            . 'h.value=s.value;'
            . '},true);})();'
            . '</script>';
    }

    /**
     * When MS3 omits data.render HTML, still sync badge + minicart + full #msCart page
     * from ms3:cart:updated (soft fetch of current document fragments).
     *
     * @return string
     */
    protected function ms3CartLiveSyncScript()
    {
        return '<script data-mmx-ms3-cart-sync="1">'
            . '(function(){if(window.__mmxMs3CartSync)return;window.__mmxMs3CartSync=1;'
            . 'function mmxSwap(sel,doc){var a=document.querySelector(sel);var b=doc&&doc.querySelector(sel);'
            . 'if(a&&b){a.innerHTML=b.innerHTML;return true;}return false;}'
            . 'document.addEventListener("ms3:cart:updated",function(e){'
            . 'var st=e&&e.detail&&e.detail.status;if(!st)return;'
            . 'var n=parseInt(st.total_count,10);if(isNaN(n))n=0;'
            . 'var badge=document.querySelector("#msMiniCartCount");'
            . 'if(badge)badge.textContent=String(n);'
            . 'var cost=document.querySelector(".ms3_total_cost, #msCart .ms3_total_cost, #msMiniCart .ms3_total_cost");'
            . 'if(cost&&st.total_cost!=null){var tc=String(st.total_cost);'
            . 'if(cost.getAttribute("data-raw")==null)cost.textContent=tc;}'
            . 'var panel=document.querySelector("#minicart-content");'
            . 'var pageCart=document.querySelector("#msCart");'
            . 'if(e.detail&&e.detail.render){/* CartUI.renderCart already applied for registered selectors */'
            . 'if(window.ms3&&ms3.refresh)try{ms3.refresh();}catch(err){}'
            . 'return;}'
            . 'if(panel&&n===0){'
            . 'panel.innerHTML="<div class=\\"mc-wrapper\\"><div id=\\"mobCloseBtn\\"><button type=\\"button\\">'
            . 'Закрыть <i class=\\"icon-cross2\\"></i></button></div>Корзина пуста</div>";'
            . 'var close=document.getElementById("mobCloseBtn");'
            . 'if(close)close.onclick=function(){if(window.jQuery)jQuery("#minicart-content").slideUp();'
            . 'else panel.style.display="none";};'
            . '}'
            . 'if(pageCart&&n===0){'
            . 'var empty=pageCart.querySelector(".ms3_cart_is_empty, .ms2_cart_is_empty, [data-ms3-cart-empty]");'
            . 'if(!empty){pageCart.innerHTML="<div class=\\"alert alert-warning ms3_cart_is_empty\\">Корзина пуста</div>";}'
            . '}'
            . '/* SSR HTML missing: soft-refresh minicart + full cart page fragments */'
            . 'if((panel&&n>0)||pageCart){'
            . 'try{fetch(location.href,{credentials:"same-origin",headers:{"X-Requested-With":"XMLHttpRequest"}})'
            . '.then(function(r){return r.text();}).then(function(html){'
            . 'var d=document.implementation.createHTMLDocument("");d.documentElement.innerHTML=html;'
            . 'if(panel&&n>0)mmxSwap("#minicart-content",d);'
            . 'if(pageCart)mmxSwap("#msCart",d);'
            . 'var b2=d.querySelector("#msMiniCartCount");'
            . 'if(b2&&badge)badge.textContent=b2.textContent.trim();'
            . 'if(window.ms3&&ms3.refresh)ms3.refresh();'
            . '}).catch(function(){});}catch(err){}'
            . '}'
            . '});})();'
            . '</script>';
    }

    /**
     * Cross-check theme scripts: jQuery plugins, DOM ids/classes vs markup, leftover MS2 hooks.
     * Reports needs_attention; auto-wraps unguarded plugin calls when safe.
     */
    protected function auditFrontBindings($apply, array &$journal)
    {
        $done = array();
        $needs = array();
        $markupIds = array();
        $markupClasses = array();
        $scriptRefs = array();
        foreach ($this->collectTargets() as $t) {
            if ($t['source'] !== 'chunk' && $t['source'] !== 'template') {
                continue;
            }
            $code = $t['code'];
            if (preg_match_all('/\bid=["\']([^"\']+)["\']/i', $code, $im)) {
                foreach ($im[1] as $id) {
                    $markupIds[$id] = true;
                }
            }
            if (preg_match_all('/\bclass=["\']([^"\']+)["\']/i', $code, $cm)) {
                foreach ($cm[1] as $clsList) {
                    foreach (preg_split('/\s+/', trim($clsList)) as $cls) {
                        if ($cls !== '') {
                            $markupClasses[$cls] = true;
                        }
                    }
                }
            }
            if (preg_match_all('/<script\b[^>]*>([\s\S]*?)<\/script>/i', $code, $sm)) {
                foreach ($sm[1] as $js) {
                    if (preg_match_all('/(?:jQuery|\$)\s*\(\s*[\'"]#([^\'"]+)[\'"]\s*\)/', $js, $jm)) {
                        foreach ($jm[1] as $id) {
                            $scriptRefs[] = array('type' => 'id', 'value' => $id, 'from' => $t['source'] . ':' . $t['name']);
                        }
                    }
                    if (preg_match_all('/(?:jQuery|\$)\s*\(\s*[\'"]\.([a-zA-Z_][\w-]*)[\'"]\s*\)/', $js, $jm)) {
                        foreach ($jm[1] as $cls) {
                            $scriptRefs[] = array('type' => 'class', 'value' => $cls, 'from' => $t['source'] . ':' . $t['name']);
                        }
                    }
                    if (preg_match('/miniShop2\./', $js) && !preg_match('/mmx:\s*miniShop2/', $js)) {
                        $needs[] = array(
                            'id' => 'ms2_js_live',
                            'message' => 'Live miniShop2.* call in ' . $t['source'] . ':' . $t['name'],
                            'severity' => 'hard',
                            'name' => $t['name'],
                        );
                    }
                    if (preg_match('/\.(fancybox|slick|owlCarousel|select2)\s*\(/', $js)
                        && strpos($js, 'mmxWaitJqDeps') === false
                        && strpos($js, 'mmxWaitJq') === false
                    ) {
                        $needs[] = array(
                            'id' => 'jq_plugin_unguarded',
                            'message' => 'jQuery plugin call without wait-guard in '
                                . $t['source'] . ':' . $t['name']
                                . ' — Heal jquery guards should wrap; re-run Heal front if still raw.',
                            'severity' => 'soft',
                            'name' => $t['name'],
                        );
                    }
                }
            }
        }
        $missing = 0;
        foreach ($scriptRefs as $ref) {
            if ($ref['type'] === 'id' && empty($markupIds[$ref['value']])) {
                /* Dynamic ids (product-{$id}) often absent as literals — skip braced/placeholders */
                if (strpos($ref['value'], '{') !== false || strpos($ref['value'], '$') !== false) {
                    continue;
                }
                $missing++;
                if ($missing <= 25) {
                    $needs[] = array(
                        'id' => 'missing_dom_id',
                        'message' => 'Script #' . $ref['value'] . ' in ' . $ref['from']
                            . ' — no matching id= in chunks/templates (check rename after MS3).',
                        'severity' => 'soft',
                    );
                }
            }
            if ($ref['type'] === 'class' && empty($markupClasses[$ref['value']])) {
                /* Common utility / state classes may be toggled only in JS */
                if (preg_match('/^(active|open|show|hide|hidden|visible|disabled|selected|loading|no-overflow)$/i', $ref['value'])) {
                    continue;
                }
                $missing++;
                if ($missing <= 40) {
                    $needs[] = array(
                        'id' => 'missing_dom_class',
                        'message' => 'Script .' . $ref['value'] . ' in ' . $ref['from']
                            . ' — class not found in markup (ms2→ms3 rename?).',
                        'severity' => 'soft',
                    );
                }
            }
        }
        /* Cart form sanity */
        $hasCartBtn = false;
        $hasHiddenAction = false;
        foreach ($this->collectTargets() as $t) {
            if ($t['source'] !== 'chunk' && $t['source'] !== 'template') {
                continue;
            }
            if (preg_match('/name=["\']ms3_action["\'][^>]*value=["\']cart\/add["\']/i', $t['code'])
                || preg_match('/value=["\']cart\/add["\'][^>]*name=["\']ms3_action["\']/i', $t['code'])
            ) {
                $hasCartBtn = true;
            }
            if (preg_match('/<input[^>]+type=["\']hidden["\'][^>]+name=["\']ms3_action["\']/i', $t['code'])
                || preg_match('/<input[^>]+name=["\']ms3_action["\'][^>]+type=["\']hidden["\']/i', $t['code'])
            ) {
                $hasHiddenAction = true;
            }
        }
        if ($hasCartBtn && !$hasHiddenAction && strpos(json_encode($done), 'ms3_action') === false) {
            $needs[] = array(
                'id' => 'cart_action_formdata',
                'message' => 'Cart buttons use name=ms3_action on submit controls — MS3 FormData needs a hidden input (Heal injects it).',
                'severity' => 'hard',
            );
        }
        if ($apply && $missing === 0 && $hasCartBtn) {
            $done[] = array(
                'id' => 'front_bindings_ok',
                'message' => 'Front binding audit: cart actions present; no critical missing id/class hits beyond soft notes',
                'applied' => true,
            );
            $journal['items'][] = array('type' => 'front_bindings_audit', 'missing_refs' => $missing);
        } elseif (!$apply) {
            $done[] = array(
                'id' => 'front_bindings_dry',
                'message' => 'Front binding audit ready (' . count($scriptRefs) . ' script refs)',
                'applied' => false,
            );
        }
        return array('done' => $done, 'needs' => $needs);
    }

    /**
     * Scan theme asset JS under assets/ (templates, themes) for MS2 API + unguarded plugin usage.
     */
    protected function healAssetScripts($apply, array &$journal)
    {
        $done = array();
        $needs = array();
        if ($this->siteRoot === '' || !is_dir($this->siteRoot . 'assets/')) {
            return array('done' => $done, 'needs' => $needs);
        }
        $roots = array(
            $this->siteRoot . 'assets/templates/',
            $this->siteRoot . 'assets/theme/',
            $this->siteRoot . 'assets/themes/',
            $this->siteRoot . 'assets/js/',
        );
        $files = array();
        foreach ($roots as $root) {
            if (!is_dir($root)) {
                continue;
            }
            try {
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
                );
                $n = 0;
                foreach ($it as $f) {
                    if (!$f->isFile()) {
                        continue;
                    }
                    $name = $f->getFilename();
                    if (!preg_match('/\.(js|jsx)$/i', $name)) {
                        continue;
                    }
                    if (preg_match('/minishop[23]|node_modules|vendor/i', $f->getPathname())) {
                        continue;
                    }
                    if (++$n > 120) {
                        break;
                    }
                    $files[] = $f->getPathname();
                }
            } catch (Exception $e) {
            } catch (Throwable $e) {
            }
        }
        foreach ($files as $path) {
            $src = (string)@file_get_contents($path);
            if ($src === '') {
                continue;
            }
            $rel = str_replace('\\', '/', $path);
            if ($this->siteRoot !== '') {
                $rel = str_replace(str_replace('\\', '/', $this->siteRoot), '', $rel);
            }
            $orig = $src;
            $changed = false;
            if (preg_match('/miniShop2\.(Cart|Order)\.initialize\s*\(\s*\)/', $src)) {
                $src = preg_replace(
                    '/miniShop2\.(Cart|Order)\.initialize\s*\(\s*\)\s*;?/',
                    '/* mmx: miniShop2.$1.initialize removed */',
                    $src
                );
                $changed = true;
            }
            if (preg_match('/msminicartdynamic/i', $src)) {
                $needs[] = array(
                    'id' => 'asset_msminicart',
                    'message' => 'Asset still references msminicartdynamic: ' . $rel,
                    'severity' => 'soft',
                );
            }
            if (preg_match('/\.(fancybox|slick|owlCarousel)\s*\(/', $src)
                && strpos($src, 'mmxWaitJqDeps') === false
            ) {
                $needs[] = array(
                    'id' => 'asset_jq_plugin',
                    'message' => 'Theme asset uses jQuery plugin — ensure plugins.js loads before callers: ' . $rel,
                    'severity' => 'soft',
                );
            }
            /* Rewrite ms2_form token in string literals used for selectors */
            if (strpos($src, 'ms2_form') !== false || strpos($src, 'ms2_action') !== false) {
                $src2 = str_replace(
                    array('ms2_form', 'ms2_action', 'ms2_product_link'),
                    array('ms3_form', 'ms3_action', 'ms3_link'),
                    $src
                );
                if ($src2 !== $src) {
                    $src = $src2;
                    $changed = true;
                }
            }
            if (!$changed || $src === $orig) {
                continue;
            }
            $msg = 'Theme asset JS MS2→MS3 selectors: ' . $rel;
            if ($apply) {
                $bak = $path . '.__mmx_jsbak';
                if (!is_file($bak)) {
                    @copy($path, $bak);
                }
                if (@file_put_contents($path, $src) === false) {
                    $needs[] = array('id' => 'asset_js_write', 'message' => 'write failed ' . $rel);
                    continue;
                }
                $journal['items'][] = array('type' => 'asset_js', 'path' => $rel);
                $done[] = array('id' => 'asset_js_' . md5($rel), 'message' => $msg, 'applied' => true);
            } else {
                $done[] = array('id' => 'asset_js_' . md5($rel), 'message' => $msg, 'applied' => false);
            }
        }
        return array('done' => $done, 'needs' => $needs);
    }

    /**
     * Canonical header minicart markup: bag + live count + SSR target for MS3.
     *
     * @return string
     */
    protected function miniCartTriggerHtml()
    {
        return '<div id="msMiniCart" class="header-right-items header-cart">'
            . "\n"
            . '    <a href="javascript:void(0)" class="header-cart" title="Корзина">'
            . "\n"
            . '        <i class="icon-bag"></i>'
            . "\n"
            . '        <span id="msMiniCartCount" class="total item-counter ms3_total_count">'
            . '[[!msCart?tpl=`tpl.mmxMiniCartCount`&selector=`#msMiniCartCount`]]'
            . '</span>'
            . "\n"
            . '    </a>'
            . "\n"
            . '    <div id="minicart-content" style="display:none">'
            . '[[!msMiniCart?selector=`#minicart-content`]]'
            . '</div>'
            . "\n"
            . '</div>';
    }

    /**
     * Restore #msMiniCart header trigger (icon + counter) that themes expect for hover/slideDown.
     * Heal previously moved [[!msMiniCart]] into #minicart-content alone — button disappeared.
     */
    protected function healMiniCartTrigger($apply, array &$journal)
    {
        $done = array();
        $needs = array();
        $trigger = $this->miniCartTriggerHtml();
        foreach ($this->collectTargets() as $t) {
            if ($t['source'] !== 'chunk' && $t['source'] !== 'template') {
                continue;
            }
            $code = $t['code'];
            if (strpos($code, 'minicart-content') === false && !preg_match('/\[\[\s*!?msMiniCart/i', $code)) {
                continue;
            }
            $orig = $t['code'];
            /* Ensure dropdown starts hidden (theme CSS has no display:none; JS uses slideDown) */
            if (preg_match('/id=["\']minicart-content["\'](?![^>]*display\s*:\s*none)/i', $code)
                && !preg_match('/id=["\']minicart-content["\'][^>]*style=["\'][^"\']*display\s*:\s*none/i', $code)
            ) {
                $code = preg_replace(
                    '/(<div\s+id=["\']minicart-content["\'])([^>]*>)/i',
                    '$1 style="display:none"$2',
                    $code,
                    1
                );
            }
            /* Upgrade existing trigger: live count + MS3 render selectors */
            if (preg_match('/id=["\']msMiniCart["\']/', $code)) {
                $countSpan = '<span id="msMiniCartCount" class="total item-counter ms3_total_count">'
                    . '[[!msCart?tpl=`tpl.mmxMiniCartCount`&selector=`#msMiniCartCount`]]'
                    . '</span>';
                /* Prefer anchoring on icon-bag so wishlist counters are not touched */
                $code2 = preg_replace(
                    '/(<div\s+id=["\']msMiniCart["\'][\s\S]*?<i class=["\']icon-bag["\']><\/i>\s*)'
                    . '<span[^>]*>\s*(?:0|\[\[!msCart[\s\S]*?\]\])\s*<\/span>/i',
                    '$1' . $countSpan,
                    $code,
                    1
                );
                if (is_string($code2) && $code2 !== '' && $code2 !== $code) {
                    $code = $code2;
                } elseif (strpos($code, 'id="msMiniCartCount"') === false
                    && strpos($code, "id='msMiniCartCount'") === false
                ) {
                    $code = preg_replace(
                        '/(<div\s+id=["\']msMiniCart["\'][\s\S]*?<i class=["\']icon-bag["\']><\/i>\s*)'
                        . '<span([^>]*\bms3_total_count\b[^>]*)>/i',
                        '$1<span id="msMiniCartCount" class="total item-counter ms3_total_count">',
                        $code,
                        1
                    );
                }
                if (!preg_match('/\[\[\s*!?msMiniCart\?[^\]]*selector=/i', $code)) {
                    $code = preg_replace(
                        '/\[\[\s*!?msMiniCart\??\s*([^\]]*)\]\]/i',
                        '[[!msMiniCart?selector=`#minicart-content`&$1]]',
                        $code,
                        1
                    );
                    /* Trim empty trailing & when call had no props */
                    $code = str_replace(
                        '[[!msMiniCart?selector=`#minicart-content`&]]',
                        '[[!msMiniCart?selector=`#minicart-content`]]',
                        $code
                    );
                    $code = str_replace(
                        '[[!msMiniCart?selector=`#minicart-content`&?',
                        '[[!msMiniCart?selector=`#minicart-content`&',
                        $code
                    );
                }
                if ($code !== $orig) {
                    $msg = 'Wired minicart count + MS3 selector @ ' . $t['source'] . ':' . $t['name'];
                    if ($apply) {
                        try {
                            $this->persistTarget($t, $code, $orig, $journal);
                            $done[] = array('id' => 'minicart_wire_' . $t['name'], 'message' => $msg, 'applied' => true);
                        } catch (Exception $e) {
                            $needs[] = array('id' => 'minicart_trigger_fail', 'message' => $e->getMessage());
                        } catch (Throwable $e) {
                            $needs[] = array('id' => 'minicart_trigger_fail', 'message' => $e->getMessage());
                        }
                    } else {
                        $done[] = array('id' => 'minicart_wire_' . $t['name'], 'message' => $msg, 'applied' => false);
                    }
                }
                continue;
            }
            /* Common broken pattern after prior Heal: wrapper with only #minicart-content + snippet */
            $code2 = preg_replace(
                '/<div\s+class=["\']header-right-items["\']\s*>\s*'
                . '<div\s+id=["\']minicart-content["\']\s*>\s*\[\[\s*!?msMiniCart\??[^\]]*\]\]\s*<\/div>\s*<\/div>/i',
                $trigger,
                $code,
                1
            );
            if ($code2 && $code2 !== $code) {
                $code = $code2;
            } else {
                /* Empty #minicart-content sibling of missing trigger — inject trigger wrapper */
                $code2 = preg_replace(
                    '/<div\s+class=["\']header-right-items["\']\s*>\s*'
                    . '<div\s+id=["\']minicart-content["\']\s*>\s*<\/div>\s*<\/div>/i',
                    $trigger,
                    $code,
                    1
                );
                if ($code2 && $code2 !== $code) {
                    $code = $code2;
                }
            }
            if ($code === $orig) {
                continue;
            }
            $msg = 'Restored #msMiniCart cart trigger @ ' . $t['source'] . ':' . $t['name'];
            if ($apply) {
                try {
                    $this->persistTarget($t, $code, $orig, $journal);
                    $done[] = array('id' => 'minicart_trigger_' . $t['name'], 'message' => $msg, 'applied' => true);
                } catch (Exception $e) {
                    $needs[] = array('id' => 'minicart_trigger_fail', 'message' => $e->getMessage());
                } catch (Throwable $e) {
                    $needs[] = array('id' => 'minicart_trigger_fail', 'message' => $e->getMessage());
                }
            } else {
                $done[] = array('id' => 'minicart_trigger_' . $t['name'], 'message' => $msg, 'applied' => false);
            }
        }
        return array('done' => $done, 'needs' => $needs);
    }

    /**
     * Count tpl chunk + mount [[!msOrder]] into #msOrder outside the dropdown;
     * strip duplicate bare msOrder calls once the modal mount exists.
     */
    protected function healMiniCartMs3Wiring($apply, array &$journal)
    {
        $done = array();
        $needs = array();

        /* Tiny Fenom tpl so header badge SSR-updates via ms3Config.render.cart */
        if (!$this->chunkExists('tpl.mmxMiniCartCount')) {
            $msg = 'Created chunk tpl.mmxMiniCartCount ({$total.count})';
            if ($apply) {
                try {
                    /** @var modChunk $chunk */
                    $chunk = $this->modx->newObject('modChunk');
                    $chunk->fromArray(array(
                        'name' => 'tpl.mmxMiniCartCount',
                        'description' => 'MigrateMODX: header minicart qty badge for MS3 SSR',
                        'snippet' => '{$total.count}',
                    ));
                    if (method_exists($chunk, 'set')) {
                        $chunk->set('snippet', '{$total.count}');
                    }
                    if ($chunk->save()) {
                        $journal['items'][] = array('type' => 'chunk_create', 'name' => 'tpl.mmxMiniCartCount');
                        $done[] = array('id' => 'minicart_count_tpl', 'message' => $msg, 'applied' => true);
                    }
                } catch (Exception $e) {
                    $needs[] = array('id' => 'minicart_count_tpl_fail', 'message' => $e->getMessage());
                } catch (Throwable $e) {
                    $needs[] = array('id' => 'minicart_count_tpl_fail', 'message' => $e->getMessage());
                }
            } else {
                $done[] = array('id' => 'minicart_count_tpl', 'message' => $msg, 'applied' => false);
            }
        }

        /* Mount order form for fancybox href="#msOrder" (was wrongly inside customMiniCart) */
        $mountTargets = array();
        foreach ($this->collectTargets() as $t) {
            if ($t['source'] !== 'chunk') {
                continue;
            }
            $n = strtolower($t['name']);
            if ($n === 'modals' || $n === 'footer' || $n === 'scripts') {
                $mountTargets[] = $t;
            }
        }
        $hasMsOrderId = false;
        foreach ($this->collectTargets() as $t) {
            if (preg_match('/id=["\']msOrder["\']/', $t['code'])) {
                $hasMsOrderId = true;
                break;
            }
        }
        if (!$hasMsOrderId && $mountTargets) {
            $t = $mountTargets[0];
            foreach ($mountTargets as $cand) {
                if (strtolower($cand['name']) === 'modals') {
                    $t = $cand;
                    break;
                }
            }
            $orig = $t['code'];
            $mount = "\n<div id=\"msOrder\" style=\"display:none\">[[!msOrder]]</div>\n";
            if (stripos($orig, '[[!msOrder') === false && stripos($orig, '[[msOrder') === false) {
                $code = rtrim($orig) . $mount;
                $msg = 'Mounted [[!msOrder]] into #' . $t['name'] . ' as #msOrder';
                if ($apply) {
                    try {
                        $this->persistTarget($t, $code, $orig, $journal);
                        $done[] = array('id' => 'msorder_mount', 'message' => $msg, 'applied' => true);
                        $hasMsOrderId = true;
                    } catch (Exception $e) {
                        $needs[] = array('id' => 'msorder_mount_fail', 'message' => $e->getMessage());
                    } catch (Throwable $e) {
                        $needs[] = array('id' => 'msorder_mount_fail', 'message' => $e->getMessage());
                    }
                } else {
                    $done[] = array('id' => 'msorder_mount', 'message' => $msg, 'applied' => false);
                    $hasMsOrderId = true;
                }
            }
        }

        /*
         * Drop duplicate bare [[!msOrder]] only from layout chrome (header/footer/scripts)
         * once a dedicated #msOrder mount exists. Never strip cart/order pages — those ARE checkout.
         */
        if ($hasMsOrderId || $this->chunkExists('modals')) {
            foreach ($this->collectTargets() as $t) {
                if ($t['source'] !== 'chunk') {
                    continue;
                }
                $n = strtolower($t['name']);
                if ($this->isMiniCartMarkupTarget($t['name'], $t['code'])) {
                    continue;
                }
                if ($this->isCartPageMarkupTarget($t['name'], $t['code'])) {
                    continue;
                }
                if ($n === 'modals' || preg_match('/order|checkout|оформ|заказ/u', $n)) {
                    continue;
                }
                if (!preg_match('/^(header|footer|scripts|head|layout|wrapper|base|sidebar)/i', $n)
                    && !preg_match('/(header|footer|scripts)$/i', $n)
                ) {
                    continue;
                }
                if (!preg_match('/\[\[\s*!?msOrder\??[^\]]*\]\]/', $t['code'])) {
                    continue;
                }
                if (preg_match('/id=["\']msOrder["\']/', $t['code'])) {
                    continue;
                }
                $orig = $t['code'];
                $code = preg_replace('/\s*\[\[\s*!?msOrder\??[^\]]*\]\]\s*/i', "\n", $orig);
                if ($code === null || $code === $orig) {
                    continue;
                }
                $msg = 'Removed duplicate [[!msOrder]] from layout @ ' . $t['source'] . ':' . $t['name'];
                if ($apply) {
                    try {
                        $this->persistTarget($t, $code, $orig, $journal);
                        $done[] = array('id' => 'msorder_dedupe_' . $t['name'], 'message' => $msg, 'applied' => true);
                    } catch (Exception $e) {
                        $needs[] = array('id' => 'msorder_dedupe_fail', 'message' => $e->getMessage());
                    } catch (Throwable $e) {
                        $needs[] = array('id' => 'msorder_dedupe_fail', 'message' => $e->getMessage());
                    }
                } else {
                    $done[] = array('id' => 'msorder_dedupe_' . $t['name'], 'message' => $msg, 'applied' => false);
                }
            }
        }

        /* Cached [[$header]] freezes minicart SSR — shop headers must be uncached */
        foreach ($this->collectTargets() as $t) {
            if ($t['source'] !== 'template') {
                continue;
            }
            if (!preg_match('/\[\[\s*\$header(\b|[^\]]*)\]\]/', $t['code'])) {
                continue;
            }
            $orig = $t['code'];
            $code = preg_replace('/\[\[\s*\$header(\s*[^\]]*)?\]\]/', '[[!$header$1]]', $orig);
            if ($code === null || $code === $orig) {
                continue;
            }
            $msg = 'Uncached [[!$header]] so minicart/count SSR stays live @ template:' . $t['name'];
            if ($apply) {
                try {
                    $this->persistTarget($t, $code, $orig, $journal);
                    $done[] = array('id' => 'header_uncache_' . $t['name'], 'message' => $msg, 'applied' => true);
                } catch (Exception $e) {
                    $needs[] = array('id' => 'header_uncache_fail', 'message' => $e->getMessage());
                } catch (Throwable $e) {
                    $needs[] = array('id' => 'header_uncache_fail', 'message' => $e->getMessage());
                }
            } else {
                $done[] = array('id' => 'header_uncache_' . $t['name'], 'message' => $msg, 'applied' => false);
            }
        }

        return array('done' => $done, 'needs' => $needs);
    }

    /**
     * Backfill empty ms3_order_products.product_key — without it remove/change are impossible
     * and minicart rows render with empty product_key (delete appears broken).
     */
    protected function healMs3CartProductKeys($apply, array &$journal)
    {
        $done = array();
        $needs = array();
        $class = 'MiniShop3\\Model\\msOrderProduct';
        if (!class_exists($class)) {
            return array('done' => $done, 'needs' => $needs);
        }
        $empty = 0;
        try {
            $empty = (int)$this->modx->getCount($class, array('product_key:=' => ''));
        } catch (Exception $e) {
            return array('done' => $done, 'needs' => $needs);
        } catch (Throwable $e) {
            return array('done' => $done, 'needs' => $needs);
        }
        if ($empty < 1) {
            return array('done' => $done, 'needs' => $needs);
        }
        $msg = 'Backfill empty MS3 cart product_key on ' . $empty . ' order product row(s)';
        if (!$apply) {
            $done[] = array('id' => 'ms3_cart_keys', 'message' => $msg, 'applied' => false);
            return array('done' => $done, 'needs' => $needs);
        }
        $fixed = 0;
        try {
            foreach ($this->modx->getIterator($class, array('product_key:=' => '')) as $item) {
                $pid = (int)$item->get('product_id');
                $opts = $item->get('options');
                if (!is_array($opts)) {
                    $opts = array();
                }
                /* Match CartItemManager::generateProductKey defaults (id,options) */
                $keyData = (string)$pid . json_encode($opts);
                $key = 'ms' . md5($keyData);
                $item->set('product_key', $key);
                if ($item->save()) {
                    $fixed++;
                }
            }
        } catch (Exception $e) {
            $needs[] = array('id' => 'ms3_cart_keys_fail', 'message' => $e->getMessage());
            return array('done' => $done, 'needs' => $needs);
        } catch (Throwable $e) {
            $needs[] = array('id' => 'ms3_cart_keys_fail', 'message' => $e->getMessage());
            return array('done' => $done, 'needs' => $needs);
        }
        $journal['items'][] = array('type' => 'ms3_cart_product_keys', 'fixed' => $fixed);
        $done[] = array(
            'id' => 'ms3_cart_keys',
            'message' => $msg . ' → fixed ' . $fixed,
            'applied' => true,
        );
        return array('done' => $done, 'needs' => $needs);
    }

    /**
     * Strip leftover miniShop2 front config, disable MS2-only plugins (swords maps, discounts),
     * neutralize addPackage/getService miniShop2 in elements — MS3 rails only.
     */
    protected function healPurgeMs2FrontTraces($apply, array &$journal)
    {
        $done = array();
        $needs = array();
        $failed = array();
        foreach ($this->collectTargets() as $t) {
            $code = $t['code'];
            $orig = $code;
            $changed = false;
            /* Head: dead miniShop2Config block */
            if (preg_match('/miniShop2Config\s*=/', $code)) {
                $code2 = preg_replace(
                    '/\s*<script[^>]*>\s*miniShop2Config\s*=\s*\{[\s\S]*?\}\s*;?\s*<\/script>/i',
                    "\n<!-- mmx: removed miniShop2Config (use MiniShop3) -->",
                    $code
                );
                if ($code2 && $code2 !== $code) {
                    $code = $code2;
                    $changed = true;
                }
            }
            /* addPackage / getService miniShop2 */
            if (preg_match('/addPackage\s*\(\s*[\'"]minishop2[\'"]/i', $code)
                || preg_match('/getService\s*\(\s*[\'"]miniShop2[\'"]/i', $code)
            ) {
                $code2 = preg_replace(
                    '/\$modx\s*->\s*addPackage\s*\(\s*[\'"]minishop2[\'"][^;]*;/i',
                    '/* mmx: addPackage minishop2 removed */',
                    $code
                );
                $code2 = preg_replace(
                    '/\$modx\s*->\s*getService\s*\(\s*[\'"]miniShop2[\'"][^;]*;/i',
                    '/* mmx: getService miniShop2 removed — use MiniShop3 plugin */',
                    $code2
                );
                if ($code2 && $code2 !== $code) {
                    $code = $code2;
                    $changed = true;
                }
            }
            /* assets URLs pointing at /components/minishop2/ */
            if (preg_match('#components/minishop2/#i', $code)) {
                $code2 = preg_replace(
                    '#/assets/components/minishop2/#i',
                    '/assets/components/minishop3/',
                    $code
                );
                $code2 = preg_replace(
                    '#components/minishop2/#i',
                    'components/minishop3/',
                    $code2
                );
                if ($code2 && $code2 !== $code) {
                    $code = $code2;
                    $changed = true;
                }
            }
            if (!$changed || $code === $orig) {
                continue;
            }
            $msg = 'Purged miniShop2 front traces @ ' . $t['source'] . ':' . $t['name'];
            if ($apply) {
                try {
                    $this->persistTarget($t, $code, $orig, $journal);
                    $done[] = array('id' => 'purge_ms2_' . $t['name'], 'message' => $msg, 'applied' => true);
                } catch (Exception $e) {
                    $failed[] = array('id' => 'purge_ms2_fail', 'message' => $e->getMessage());
                } catch (Throwable $e) {
                    $failed[] = array('id' => 'purge_ms2_fail', 'message' => $e->getMessage());
                }
            } else {
                $done[] = array('id' => 'purge_ms2_' . $t['name'], 'message' => $msg, 'applied' => false);
            }
        }
        /* Disable live plugins that still map MS2 fields (swords) or call miniShop2 */
        if (is_object($this->modx) && method_exists($this->modx, 'getCollection')) {
            try {
                $cls = class_exists('\\MODX\\Revolution\\modPlugin')
                    ? '\\MODX\\Revolution\\modPlugin'
                    : 'modPlugin';
                foreach ($this->modx->getCollection($cls) as $plugin) {
                    if ((int)$plugin->get('disabled') === 1) {
                        continue;
                    }
                    $name = (string)$plugin->get('name');
                    if (strcasecmp($name, 'MiniShop3') === 0) {
                        continue;
                    }
                    $pcode = (string)$plugin->get('plugincode');
                    $hit = (bool)preg_match('/[\'"]swords[\'"]\s*=>/', $pcode)
                        || (bool)preg_match('/getService\s*\(\s*[\'"]miniShop2[\'"]/', $pcode)
                        || (bool)preg_match('/addPackage\s*\(\s*[\'"]minishop2[\'"]/i', $pcode)
                        || (bool)preg_match('/components\/minishop2\//i', $pcode);
                    if (!$hit) {
                        continue;
                    }
                    $msg = 'Disabled MS2-legacy plugin «' . $name . '» (swords/minishop2)';
                    if ($apply) {
                        $plugin->set('disabled', 1);
                        if ($plugin->save()) {
                            $journal['items'][] = array('type' => 'disable_ms2_plugin', 'name' => $name);
                            $done[] = array('id' => 'disable_plugin_' . $name, 'message' => $msg, 'applied' => true);
                        }
                    } else {
                        $done[] = array('id' => 'disable_plugin_' . $name, 'message' => $msg, 'applied' => false);
                    }
                }
            } catch (Exception $e) {
            } catch (Throwable $e) {
            }
        }
        return array('done' => $done, 'needs' => $needs, 'failed' => $failed);
    }

    /**
     * Quiet PHP 8 warnings from theme sort snippets + HitsPage + pdoPage $showLog.
     */
    protected function healPhp8ThemeNoise($apply, array &$journal)
    {
        $done = array();
        $needs = array();
        /* sortLink / Sort — isset $_GET */
        foreach (array('sortLink', 'Sort') as $name) {
            $snip = $this->getSnippet($name);
            if (!$snip) {
                continue;
            }
            $code = (string)$snip->get('snippet');
            $orig = $code;
            if ($name === 'sortLink') {
                if (strpos($code, "\$_GET['sort']") !== false && strpos($code, 'isset($_GET') === false) {
                    $code = str_replace(
                        "if(\$_GET['sort'] == \$field and \$_GET['dir'] == 'ASC') \$arr=' <i class=\"fa fa-angle-up\"></i>';\n"
                        . "if(\$_GET['sort'] == \$field and \$_GET['dir'] == 'DESC') \$arr=' <i class=\"fa fa-angle-down\"></i>';",
                        "\$arr='';\n"
                        . "\$_sort = isset(\$_GET['sort']) ? \$_GET['sort'] : '';\n"
                        . "\$_dir = isset(\$_GET['dir']) ? \$_GET['dir'] : '';\n"
                        . "if(\$_sort == \$field and \$_dir == 'ASC') \$arr=' <i class=\"fa fa-angle-up\"></i>';\n"
                        . "if(\$_sort == \$field and \$_dir == 'DESC') \$arr=' <i class=\"fa fa-angle-down\"></i>';",
                        $code
                    );
                    $code = preg_replace(
                        '/if\s*\(\s*!\s*\$_GET\s*\[\s*\$field\s*\]\s*\)/',
                        'if(empty($_GET[$field]))',
                        $code
                    );
                    $code = str_replace("\$_GET['dir']", '$_dir', $code);
                    $code = str_replace('isset($tv)', 'isset($tv) /* mmx */', $code);
                    if (strpos($code, "\$tv=\$_GET") === false && strpos($code, "\$tv='&tv") !== false) {
                        $code = str_replace(
                            "if(isset(\$tv)) \$tv='&tv=1';",
                            "\$tv = !empty(\$tv) ? '&tv=1' : '';",
                            $code
                        );
                    }
                    $code = str_replace(
                        "if(\$type=='number') \$typ='&type=number';",
                        "\$typ = (isset(\$type) && \$type=='number') ? '&type=number' : '';",
                        $code
                    );
                }
            }
            if ($name === 'Sort') {
                if (strpos($code, "\$_GET['sort']") !== false && strpos($code, 'isset($_GET') === false) {
                    $code = "\$sort = isset(\$_GET['sort']) ? \$_GET['sort'] : null;\n"
                        . "\$type = isset(\$_GET['type']) ? \$_GET['type'] : null;\n"
                        . "\$tv = isset(\$_GET['tv']) ? \$_GET['tv'] : null;\n"
                        . "\$dir = isset(\$_GET['dir']) ? \$_GET['dir'] : 'ASC';\n"
                        . "if (!isset(\$defsort)) { \$defsort = ''; }\n"
                        . "if (!isset(\$defdir)) { \$defdir = 'ASC'; }\n"
                        . "if (!isset(\$integer)) { \$integer = null; }\n"
                        . "if (isset(\$sort)) {\n"
                        . "\tif (\$tv == 1) {\n"
                        . "\t\tif (\$integer == 1) { \$integer = '&sortdirTVType=`integer`'; }\n"
                        . "\t\t\$output = \"\\n\\t\\t&sortbyTV=`{\$sort}`\\n\\t\\t&sortdirTV=`{\$dir}`\\n\\t\\t{\$integer}\\n\\t\\t\";\n"
                        . "\t} else {\n"
                        . "\t\t\$output = \"\\n\\t\\t&sortby=`{\$sort}`\\n\\t\\t&sortdir=`{\$dir}`\\n\\t\\t\";\n"
                        . "\t}\n"
                        . "} else {\n"
                        . "\tif (\$defsort == '') { \$defsort = 'menuindex'; }\n"
                        . "\tif (\$defdir == '') { \$defdir = 'ASC'; }\n"
                        . "\t\$output = \"\\n\\t&sortby=`{\$defsort}`\\n\\t&sortdir=`{\$defdir}`\\n\\t\";\n"
                        . "}\n"
                        . "return \$output;";
                }
            }
            if ($code === $orig) {
                continue;
            }
            $msg = 'PHP8-safe ' . $name . ' snippet (isset $_GET)';
            if ($apply) {
                $snip->set('snippet', $code);
                if ($snip->save()) {
                    $journal['items'][] = array('type' => 'php8_snippet', 'name' => $name);
                    $done[] = array('id' => 'php8_' . $name, 'message' => $msg, 'applied' => true);
                }
            } else {
                $done[] = array('id' => 'php8_' . $name, 'message' => $msg, 'applied' => false);
            }
        }
        /* HitsPage — guard undefined $modx->hpCount */
        if (is_object($this->modx) && method_exists($this->modx, 'getObject')) {
            try {
                $cls = class_exists('\\MODX\\Revolution\\modPlugin')
                    ? '\\MODX\\Revolution\\modPlugin'
                    : 'modPlugin';
                $plugin = $this->modx->getObject($cls, array('name' => 'HitsPage'));
                if ($plugin && !(int)$plugin->get('disabled')) {
                    $pcode = (string)$plugin->get('plugincode');
                    if (strpos($pcode, 'hpCount') !== false && strpos($pcode, 'property_exists') === false
                        && strpos($pcode, 'isset($modx->hpCount)') === false
                    ) {
                        $fixed = preg_replace(
                            '/if\s*\(\s*\$modx\s*->\s*hpCount\s*===\s*true\s*\)/',
                            'if (isset($modx->hpCount) && $modx->hpCount === true)',
                            $pcode
                        );
                        if ($fixed && $fixed !== $pcode) {
                            $msg = 'HitsPage: guard undefined $modx->hpCount';
                            if ($apply) {
                                $plugin->set('plugincode', $fixed);
                                if ($plugin->save()) {
                                    $journal['items'][] = array('type' => 'php8_plugin', 'name' => 'HitsPage');
                                    $done[] = array('id' => 'php8_HitsPage', 'message' => $msg, 'applied' => true);
                                }
                            } else {
                                $done[] = array('id' => 'php8_HitsPage', 'message' => $msg, 'applied' => false);
                            }
                        }
                    }
                }
            } catch (Exception $e) {
            } catch (Throwable $e) {
            }
        }
        /* pdoPage $showLog */
        $pdo = $this->getSnippet('pdoPage');
        if ($pdo) {
            $code = (string)$pdo->get('snippet');
            $orig = $code;
            if (strpos($code, '$showLog') !== false && !preg_match('/\$showLog\s*=/', $code)) {
                $code = preg_replace(
                    '/(<\?php\s*)/i',
                    "\$1\nif (!isset(\$showLog)) { \$showLog = 0; }\n",
                    $code,
                    1
                );
                if ($code === $orig) {
                    $code = "<?php\nif (!isset(\$showLog)) { \$showLog = 0; }\n"
                        . ltrim(preg_replace('/^<\?php\s*/i', '', $orig));
                }
            }
            if ($code !== $orig) {
                $msg = 'pdoPage: init \$showLog for PHP8';
                if ($apply) {
                    $pdo->set('snippet', $code);
                    if ($pdo->save()) {
                        $journal['items'][] = array('type' => 'php8_snippet', 'name' => 'pdoPage');
                        $done[] = array('id' => 'php8_pdoPage', 'message' => $msg, 'applied' => true);
                    }
                } else {
                    $done[] = array('id' => 'php8_pdoPage', 'message' => $msg, 'applied' => false);
                }
            }
        }
        return array('done' => $done, 'needs' => $needs);
    }

    /**
     * Shop products must not appear in pdoMenu/Wayfinder (theme mega-menu dumps hundreds of SKUs).
     * Typical MS2 sites keep products with hidemenu=1; after migrate/undelete they often stay 0.
     */
    protected function healProductHidemenu($apply, array &$journal)
    {
        $done = array();
        $failed = array();
        $pdo = $this->pdo();
        if (!$pdo) {
            return array('done' => $done, 'failed' => $failed);
        }
        $table = $this->resourceTable();
        if ($table === '') {
            return array('done' => $done, 'failed' => $failed);
        }
        $safe = '`' . str_replace('`', '``', $table) . '`';
        $keys = array(
            'msProduct',
            'MiniShop3\\Model\\msProduct',
        );
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        try {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM ' . $safe . ' WHERE `hidemenu` = 0 AND `deleted` = 0'
                . ' AND `class_key` IN (' . $placeholders . ')'
            );
            $stmt->execute($keys);
            $n = (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            $failed[] = array('id' => 'product_hidemenu', 'message' => $e->getMessage());
            return array('done' => $done, 'failed' => $failed);
        } catch (Throwable $e) {
            $failed[] = array('id' => 'product_hidemenu', 'message' => $e->getMessage());
            return array('done' => $done, 'failed' => $failed);
        }
        if ($n === 0) {
            $done[] = array(
                'id' => 'product_hidemenu_ok',
                'message' => 'All shop products already hidemenu=1 (nav stays categories-only)',
                'applied' => false,
            );
            return array('done' => $done, 'failed' => $failed);
        }
        $msg = 'Set hidemenu=1 on ' . $n . ' shop products (exclude from pdoMenu nav)';
        if ($apply) {
            try {
                $stmt = $pdo->prepare(
                    'UPDATE ' . $safe . ' SET `hidemenu` = 1 WHERE `hidemenu` = 0 AND `deleted` = 0'
                    . ' AND `class_key` IN (' . $placeholders . ')'
                );
                $stmt->execute($keys);
                $updated = (int)$stmt->rowCount();
                $journal['items'][] = array('type' => 'product_hidemenu', 'updated' => $updated);
                $done[] = array(
                    'id' => 'product_hidemenu',
                    'message' => 'Set hidemenu=1 on ' . $updated . ' shop products (exclude from pdoMenu nav)',
                    'applied' => true,
                );
            } catch (Exception $e) {
                $failed[] = array('id' => 'product_hidemenu', 'message' => $e->getMessage());
            } catch (Throwable $e) {
                $failed[] = array('id' => 'product_hidemenu', 'message' => $e->getMessage());
            }
        } else {
            $done[] = array('id' => 'product_hidemenu', 'message' => $msg, 'applied' => false);
        }
        return array('done' => $done, 'failed' => $failed);
    }

    /**
     * @return PDO|null
     */
    protected function pdo()
    {
        if (!is_object($this->modx)) {
            return null;
        }
        try {
            if (method_exists($this->modx, 'getConnection')) {
                $conn = $this->modx->getConnection();
                if ($conn instanceof PDO) {
                    return $conn;
                }
                if (is_object($conn) && method_exists($conn, 'getConnection')) {
                    $inner = $conn->getConnection();
                    if ($inner instanceof PDO) {
                        return $inner;
                    }
                }
            }
            if (isset($this->modx->pdo) && $this->modx->pdo instanceof PDO) {
                return $this->modx->pdo;
            }
        } catch (Exception $e) {
        } catch (Throwable $e) {
        }
        return null;
    }

    /**
     * @return string table name without quotes
     */
    protected function resourceTable()
    {
        try {
            if (class_exists('\\MODX\\Revolution\\modResource')) {
                $t = $this->modx->getTableName('\\MODX\\Revolution\\modResource');
            } else {
                $t = $this->modx->getTableName('modResource');
            }
            return trim(str_replace(array('`', '"'), '', (string)$t));
        } catch (Exception $e) {
            return '';
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * Wire SimpleSearchForm to the themed SearchForm chunk (header-search-box / search-field).
     * SimpleSearch::getChunk prefers package file searchform.chunk.tpl over the DB chunk —
     * sync themed markup into that file or the form stays a bare fieldset.
     */
    protected function healSearchFormTpl($apply, array &$journal)
    {
        $done = array();
        $needs = array();
        $tplName = '';
        $themed = '';
        foreach (array('SearchForm', 'tpl.SimpleSearchForm', 'simpleSearchForm') as $cand) {
            $ch = $this->getChunk($cand);
            if ($ch) {
                $tplName = $cand;
                $themed = (string)$ch->get('snippet');
                break;
            }
        }
        if ($tplName === '' || $themed === '') {
            $needs[] = array(
                'id' => 'search_tpl_missing',
                'message' => 'No themed SearchForm chunk — header SimpleSearchForm keeps default markup',
                'severity' => 'soft',
            );
            return array('done' => $done, 'needs' => $needs);
        }
        /* Sync DB theme chunk → SimpleSearch package file (getChunk resolves file first) */
        $core = defined('MODX_CORE_PATH') ? MODX_CORE_PATH : '';
        $pkgFile = $core . 'components/simplesearch/elements/chunks/searchform.chunk.tpl';
        if ($core !== '' && is_dir($core . 'components/simplesearch/')) {
            $looksThemed = (strpos($themed, 'search-field') !== false || strpos($themed, 'header-search-box') !== false);
            $fileNow = is_file($pkgFile) ? (string)@file_get_contents($pkgFile) : '';
            $fileIsStock = ($fileNow === '' || strpos($fileNow, '<fieldset>') !== false)
                && strpos($fileNow, 'search-field') === false;
            if ($looksThemed && $fileIsStock) {
                $msg = 'Synced themed SearchForm → simplesearch searchform.chunk.tpl';
                if ($apply) {
                    $dir = dirname($pkgFile);
                    if (!is_dir($dir)) {
                        @mkdir($dir, 0755, true);
                    }
                    if (!is_file($pkgFile . '.__mmx_bak') && is_file($pkgFile)) {
                        @copy($pkgFile, $pkgFile . '.__mmx_bak');
                    }
                    if (@file_put_contents($pkgFile, $themed) !== false) {
                        $journal['items'][] = array('type' => 'simplesearch_tpl_file', 'path' => 'searchform.chunk.tpl');
                        $done[] = array('id' => 'search_tpl_file', 'message' => $msg, 'applied' => true);
                    } else {
                        $needs[] = array('id' => 'search_tpl_file_fail', 'message' => 'Could not write ' . $pkgFile);
                    }
                } else {
                    $done[] = array('id' => 'search_tpl_file', 'message' => $msg, 'applied' => false);
                }
            } elseif ($looksThemed && !$fileIsStock) {
                $done[] = array(
                    'id' => 'search_tpl_file_ok',
                    'message' => 'simplesearch searchform.chunk.tpl already themed',
                    'applied' => false,
                );
            }
        }
        foreach ($this->collectTargets() as $t) {
            if ($t['source'] !== 'chunk' && $t['source'] !== 'template') {
                continue;
            }
            $code = $t['code'];
            if (!preg_match('/\[\[\s*!?SimpleSearchForm\b/i', $code)) {
                continue;
            }
            $orig = $code;
            $code = preg_replace_callback(
                '/\[\[\s*(!?)\s*SimpleSearchForm(\s*\?[^]]*?)?\]\]/i',
                function ($m) use ($tplName) {
                    $bang = $m[1];
                    $props = isset($m[2]) ? $m[2] : '';
                    if ($props === '' || $props === null) {
                        $props = '?';
                    }
                    if (preg_match('/&tpl\s*=/i', $props)) {
                        return $m[0];
                    }
                    $props = rtrim($props);
                    $props .= "\n                                    &tpl=`" . $tplName . "`";
                    return '[[' . $bang . 'SimpleSearchForm' . $props . ']]';
                },
                $code
            );
            if ($code === null || $code === $orig) {
                continue;
            }
            $msg = 'SimpleSearchForm → &tpl=`' . $tplName . '` @ ' . $t['source'] . ':' . $t['name'];
            if ($apply) {
                try {
                    $this->persistTarget($t, $code, $orig, $journal);
                    $done[] = array('id' => 'search_tpl_' . $t['name'], 'message' => $msg, 'applied' => true);
                } catch (Exception $e) {
                    $needs[] = array('id' => 'search_tpl_fail', 'message' => $e->getMessage());
                } catch (Throwable $e) {
                    $needs[] = array('id' => 'search_tpl_fail', 'message' => $e->getMessage());
                }
            } else {
                $done[] = array('id' => 'search_tpl_' . $t['name'], 'message' => $msg, 'applied' => false);
            }
        }
        return array('done' => $done, 'needs' => $needs);
    }

    /**
     * Category grid cards: drop RAND() (images flicker across requests), fix empty/no-pic paths.
     */
    protected function healCategoryCards($apply, array &$journal)
    {
        $done = array();
        $needs = array();
        $noPhoto = 'assets/images/no-photo.jpg';
        if ($this->siteRoot !== '' && is_file($this->siteRoot . $noPhoto)) {
            /* Ensure common broken fallbacks resolve */
            foreach (array(
                'assets/img/product/product-no-pic.jpg',
                'assets/images/product-no-pic.jpg',
            ) as $rel) {
                $dest = $this->siteRoot . $rel;
                if (is_file($dest)) {
                    continue;
                }
                if ($apply) {
                    $dir = dirname($dest);
                    if (!is_dir($dir)) {
                        @mkdir($dir, 0755, true);
                    }
                    @copy($this->siteRoot . $noPhoto, $dest);
                }
            }
        }
        foreach ($this->collectTargets() as $t) {
            if ($t['source'] !== 'chunk' && $t['source'] !== 'template') {
                continue;
            }
            $code = $t['code'];
            $orig = $code;
            $changed = false;
            /* Unstable random category thumb */
            if (preg_match('/sortby=`RAND\(\)`/i', $code) || preg_match('/sortby=["\']RAND\(\)["\']/i', $code)) {
                $code = preg_replace('/&sortby=`RAND\(\)`/i', '&sortby=`menuindex`&sortdir=`ASC`', $code);
                $code = preg_replace('/&sortby=["\']RAND\(\)["\']/i', '&sortby=`menuindex`&sortdir=`ASC`', $code);
                $changed = ($code !== $orig);
            }
            /* Broken default no-pic paths inside category cards */
            if (preg_match('/product-no-pic\.jpg/i', $code)) {
                $code2 = preg_replace(
                    '#(?:/)?assets/(?:img/product|images)/product-no-pic\.jpg#i',
                    $noPhoto,
                    $code
                );
                if ($code2 && $code2 !== $code) {
                    $code = $code2;
                    $changed = true;
                }
            }
            /* Strengthen empty image fallback on categoryCardMainImage-like chunks */
            $name = strtolower($t['name']);
            if ((strpos($name, 'category') !== false && strpos($name, 'image') !== false)
                || preg_match('/\+image:phpthumbon/i', $code)
            ) {
                if (preg_match('/\[\[\+image:phpthumbon=`([^`]+)`\]\]/', $code)
                    && strpos($code, 'default=') === false
                ) {
                    $code2 = preg_replace(
                        '/\[\[\+image:phpthumbon=`([^`]+)`\]\]/',
                        '[[+image:phpthumbon=`$1`:default=`/' . $noPhoto . '`]]',
                        $code
                    );
                    if ($code2 && $code2 !== $code) {
                        $code = $code2;
                        $changed = true;
                    }
                }
            }
            if (!$changed || $code === $orig) {
                continue;
            }
            $msg = 'Category card thumbs stabilized @ ' . $t['source'] . ':' . $t['name'];
            if ($apply) {
                try {
                    $this->persistTarget($t, $code, $orig, $journal);
                    $done[] = array('id' => 'category_card_' . $t['name'], 'message' => $msg, 'applied' => true);
                } catch (Exception $e) {
                    $needs[] = array('id' => 'category_card_fail', 'message' => $e->getMessage());
                } catch (Throwable $e) {
                    $needs[] = array('id' => 'category_card_fail', 'message' => $e->getMessage());
                }
            } else {
                $done[] = array('id' => 'category_card_' . $t['name'], 'message' => $msg, 'applied' => false);
            }
        }
        if ($apply && $this->siteRoot !== '' && is_file($this->siteRoot . $noPhoto)) {
            $done[] = array(
                'id' => 'category_nopic_aliases',
                'message' => 'Ensured product-no-pic aliases → ' . $noPhoto,
                'applied' => true,
            );
            $journal['items'][] = array('type' => 'nopic_aliases', 'src' => $noPhoto);
        }
        return array('done' => $done, 'needs' => $needs);
    }

    /**
     * PHP 8+: optional parameter before required (Deprecated → breaks hero MIGX etc.).
     * Patches known vendor files; also scans live components for the signature pattern.
     */
    protected function healPhp8Signatures($apply, array &$journal)
    {
        $done = array();
        $failed = array();
        $core = defined('MODX_CORE_PATH') ? MODX_CORE_PATH : '';
        if ($core === '') {
            return array('done' => $done, 'failed' => $failed);
        }
        $targets = array(
            $core . 'components/migx/model/migx/migx.class.php',
        );
        /* Discover other component class files with the PHP8 signature smell */
        $compRoot = $core . 'components/';
        if (is_dir($compRoot)) {
            foreach (scandir($compRoot) as $d) {
                if ($d === '.' || $d === '..' || $d[0] === '.' || $d === 'migratemodx' || $d === 'minishop3') {
                    continue;
                }
                if (strpos($d, '.__') !== false) {
                    continue;
                }
                $dir = $compRoot . $d . '/';
                if (!is_dir($dir)) {
                    continue;
                }
                try {
                    $it = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
                    );
                    $n = 0;
                    foreach ($it as $f) {
                        if (!$f->isFile() || substr($f->getFilename(), -4) !== '.php') {
                            continue;
                        }
                        if (++$n > 80) {
                            break;
                        }
                        $path = $f->getPathname();
                        $src = @file_get_contents($path);
                        if ($src && (
                            preg_match('/function\s+\w+\s*\([^)]*=\s*[^,)]+[^)]*,\s*(?:[\w\\\\\|?]+\s+)?&?\$[a-zA-Z_][\w]*\s*\)/s', $src)
                            || preg_match('/Deprecated.*optional parameter/i', $src)
                        )) {
                            $targets[] = $path;
                        }
                    }
                } catch (Exception $e) {
                } catch (Throwable $e) {
                }
            }
        }
        $targets = array_values(array_unique($targets));
        foreach ($targets as $path) {
            if (!is_file($path)) {
                continue;
            }
            $src = (string)file_get_contents($path);
            $fixed = $this->fixPhp8OptionalBeforeRequired($src);
            if ($fixed === $src) {
                continue;
            }
            $rel = str_replace('\\', '/', $path);
            if (defined('MODX_CORE_PATH')) {
                $rel = str_replace(str_replace('\\', '/', MODX_CORE_PATH), 'core/', $rel);
            }
            $msg = 'PHP8 optional-before-required patched: ' . $rel;
            if ($apply) {
                $bak = $path . '.__mmx_php8bak';
                if (!is_file($bak)) {
                    @copy($path, $bak);
                }
                if (@file_put_contents($path, $fixed) === false) {
                    $failed[] = array('id' => 'php8_sig', 'message' => 'write failed ' . $rel);
                    continue;
                }
                $journal['items'][] = array('type' => 'php8_signature', 'path' => $rel);
                $done[] = array('id' => 'php8_sig', 'message' => $msg, 'applied' => true);
            } else {
                $done[] = array('id' => 'php8_sig', 'message' => $msg, 'applied' => false);
            }
        }
        return array('done' => $done, 'failed' => $failed);
    }

    /**
     * Give trailing required params a default null when they follow an optional param.
     *
     * @param string $src
     * @return string
     */
    protected function fixPhp8OptionalBeforeRequired($src)
    {
        return preg_replace_callback(
            '/function\s+(\w+)\s*\(([^)]*)\)/s',
            function ($m) {
                $name = $m[1];
                $args = $m[2];
                if (strpos($args, '=') === false) {
                    return $m[0];
                }
                $parts = $this->splitPhpParams($args);
                if (count($parts) < 2) {
                    return $m[0];
                }
                $seenDefault = false;
                $changed = false;
                foreach ($parts as $i => $p) {
                    $p = trim($p);
                    if ($p === '') {
                        continue;
                    }
                    if (strpos($p, '=') !== false) {
                        $seenDefault = true;
                        continue;
                    }
                    if ($seenDefault) {
                        /* required after optional — add = null (typed params too: array $config) */
                        if (strpos($p, '=') === false && preg_match('/&?\$[a-zA-Z_][\w]*/', $p)) {
                            $parts[$i] = rtrim($p) . ' = null';
                            $changed = true;
                        }
                    }
                }
                if (!$changed) {
                    return $m[0];
                }
                return 'function ' . $name . '(' . implode(', ', $parts) . ')';
            },
            $src
        );
    }

    /**
     * @param string $args
     * @return string[]
     */
    protected function splitPhpParams($args)
    {
        $parts = array();
        $buf = '';
        $depth = 0;
        $len = strlen($args);
        for ($i = 0; $i < $len; $i++) {
            $ch = $args[$i];
            if ($ch === '(' || $ch === '[' || $ch === '{') {
                $depth++;
            } elseif ($ch === ')' || $ch === ']' || $ch === '}') {
                $depth = max(0, $depth - 1);
            }
            if ($ch === ',' && $depth === 0) {
                $parts[] = $buf;
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }
        if (trim($buf) !== '') {
            $parts[] = $buf;
        }
        return $parts;
    }

    protected function getChunk($name)
    {
        $classes = array('modChunk');
        if (class_exists('\\MODX\\Revolution\\modChunk')) {
            array_unshift($classes, '\\MODX\\Revolution\\modChunk');
        }
        foreach ($classes as $cls) {
            try {
                $obj = $this->modx->getObject($cls, array('name' => $name));
                if ($obj) {
                    return $obj;
                }
            } catch (Exception $e) {
            } catch (Throwable $e) {
            }
        }
        return null;
    }

    protected function chunkExists($name)
    {
        return (bool)$this->getChunk($name);
    }

    protected function clearElementCaches()
    {
        try {
            if (isset($this->modx->cacheManager) && is_object($this->modx->cacheManager)) {
                $this->modx->cacheManager->refresh();
            }
        } catch (Exception $e) {
        } catch (Throwable $e) {
        }
        $base = defined('MODX_CORE_PATH') ? MODX_CORE_PATH . 'cache/' : '';
        foreach (array('scripts', 'includes', 'elements', 'resource') as $dir) {
            $path = $base . $dir;
            if (!is_dir($path)) {
                continue;
            }
            try {
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($it as $f) {
                    $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
                }
            } catch (Exception $e) {
            } catch (Throwable $e) {
            }
        }
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }
}
