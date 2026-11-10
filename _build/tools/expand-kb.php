<?php
/**
 * One-shot: expand packages.json with quarantine phases + common/stand extras.
 */
$path = dirname(__DIR__, 2) . '/core/components/migratemodx/data/kb/packages.json';
$data = json_decode((string)file_get_contents($path), true);
if (!is_array($data) || empty($data['packages'])) {
    fwrite(STDERR, "Bad packages.json\n");
    exit(1);
}

$prepare = array('tickets', 'admintools', 'batcher');
$postMs3 = array(
    'looked', 'xlike', 'msminicartdynamic', 'msdiscount', 'msoptionsprice', 'msoptionsprice2',
    'ms2gallery', 'minishop2', 'msearch2', 'msfacets', 'seofilter', 'msorderdiscount',
    'loginfields', 'mschart', 'msemailtab', 'msgallerysearch', 'mspyacassa', 'mstools',
    'msproductremains', 'msbalance', 'msbonus', 'msbonus2', 'mspromocode', 'mspromocode2',
    'mssetincart', 'msliveprice', 'msinform', 'msproductscopy', 'mscarousel', 'msvendor',
);

$new = array(
    'autoredirector' => array(
        'title' => 'autoRedirector', 'status' => 'compatible_from',
        'notes' => 'Verify on MODX 3 / PHP 8.', 'actions' => array('update'),
    ),
    'botaway' => array(
        'title' => 'botaway', 'status' => 'unknown',
        'notes' => 'Legacy bot filter — verify or remove.', 'actions' => array('verify'),
    ),
    'botblockx' => array(
        'title' => 'BotBlockX', 'status' => 'unknown',
        'notes' => 'Legacy bot filter — verify or remove.', 'actions' => array('verify'),
    ),
    'ckeditor' => array(
        'title' => 'CKEditor', 'status' => 'compatible_from',
        'notes' => 'Prefer TinyMCE RTE on MODX 3 if CKEditor breaks.',
        'actions' => array('update'),
        'alternatives' => array(array('name' => 'TinyMCE RTE', 'signature' => 'tinymcerte')),
    ),
    'console' => array(
        'title' => 'Console', 'status' => 'compatible_from', 'actions' => array('update'),
    ),
    'controlerrorlog' => array(
        'title' => 'controlErrorLog', 'status' => 'compatible_from', 'actions' => array('update'),
    ),
    'dateago' => array(
        'title' => 'DateAgo', 'status' => 'compatible_from', 'actions' => array('update'),
    ),
    'debugparser' => array(
        'title' => 'debugParser', 'status' => 'compatible_from',
        'notes' => 'Dev-only; safe to disable on production MODX 3.', 'actions' => array('optional'),
    ),
    'flip' => array(
        'title' => 'flip', 'status' => 'unknown', 'actions' => array('verify'),
    ),
    'googlesitemap' => array(
        'title' => 'GoogleSiteMap', 'status' => 'compatible_from', 'actions' => array('update'),
    ),
    'hitspage' => array(
        'title' => 'HitsPage', 'status' => 'compatible_from', 'actions' => array('update'),
    ),
    'icontv' => array(
        'title' => 'iconTV', 'status' => 'compatible_from', 'actions' => array('update'),
    ),
    'importx' => array(
        'title' => 'ImportX', 'status' => 'compatible_from', 'actions' => array('update'),
    ),
    'jevix' => array(
        'title' => 'Jevix', 'status' => 'compatible_from',
        'notes' => 'HTML sanitizer — test PHP 8.', 'actions' => array('update'),
    ),
    'loginfields' => array(
        'title' => 'LoginFields', 'status' => 'incompatible', 'modx3' => false,
        'quarantine' => 'post_ms3',
        'notes' => 'Injects short modUser map — Undefined array key on MODX 3.',
        'actions' => array('remove'), 'risks' => array('breaks_front'),
    ),
    'logrotation' => array(
        'title' => 'logRotation', 'status' => 'compatible_from', 'actions' => array('update'),
    ),
    'moddevtools' => array(
        'title' => 'modDevTools', 'status' => 'compatible_from', 'actions' => array('update'),
    ),
    'modrelation' => array(
        'title' => 'modRelation', 'status' => 'compatible_from',
        'notes' => 'Often in extension_packages — verify map on MODX 3.', 'actions' => array('update'),
    ),
    'mschart' => array(
        'title' => 'msChart', 'status' => 'incompatible', 'modx3' => false,
        'quarantine' => 'post_ms3',
        'notes' => 'miniShop2 chart extra — not for MiniShop3.',
        'actions' => array('remove'), 'risks' => array('ms2_extra'),
    ),
    'msemailtab' => array(
        'title' => 'msEmailTab', 'status' => 'incompatible', 'modx3' => false,
        'quarantine' => 'post_ms3',
        'notes' => 'miniShop2 manager tab.',
        'actions' => array('remove'), 'risks' => array('ms2_extra'),
    ),
    'msexportusersexcel' => array(
        'title' => 'msExportUsersExcel', 'status' => 'compatible_from',
        'notes' => 'Not shop-core; verify MODX 3.', 'actions' => array('update'),
    ),
    'msgallerysearch' => array(
        'title' => 'msGallerySearch', 'status' => 'incompatible', 'modx3' => false,
        'quarantine' => 'post_ms3',
        'notes' => 'Depends on ms2 gallery stack.',
        'actions' => array('remove'), 'risks' => array('ms2_extra'),
    ),
    'msorderdiscount' => array(
        'title' => 'msOrderDiscount', 'status' => 'incompatible', 'modx3' => false,
        'quarantine' => 'post_ms3',
        'notes' => 'miniShop2 order discounts — pollutes maps / getService.',
        'actions' => array('remove'), 'risks' => array('ms2_extra', 'breaks_front'),
    ),
    'mspyacassa' => array(
        'title' => 'msPyacassa', 'status' => 'incompatible', 'modx3' => false,
        'quarantine' => 'post_ms3',
        'notes' => 'miniShop2 payment — use MiniShop3 payment modules.',
        'actions' => array('remove'), 'risks' => array('ms2_extra'),
    ),
    'mstelegram' => array(
        'title' => 'msTelegram', 'status' => 'compatible_from',
        'notes' => 'Often wired to ms2 orders — re-test after MiniShop3.', 'actions' => array('verify'),
    ),
    'mstimestamp' => array(
        'title' => 'msTimeStamp', 'status' => 'compatible_from', 'actions' => array('update'),
    ),
    'mstools' => array(
        'title' => 'msTools', 'status' => 'incompatible', 'modx3' => false,
        'quarantine' => 'post_ms3',
        'notes' => 'miniShop2 toolbox — typically breaks after MS3.',
        'actions' => array('remove'), 'risks' => array('ms2_extra'),
    ),
    'recaptchav3' => array(
        'title' => 'reCaptchaV3', 'status' => 'compatible_from', 'actions' => array('update'),
    ),
    'sendex' => array(
        'title' => 'Sendex', 'status' => 'compatible_from',
        'notes' => 'Newsletter — confirm MODX 3 build.', 'actions' => array('update'),
    ),
    'seopro' => array(
        'title' => 'SEO Pro', 'status' => 'compatible_from', 'actions' => array('update'),
    ),
    'simplerating' => array(
        'title' => 'FiveStarRating / SimpleRating', 'status' => 'compatible_from',
        'aliases' => array('fivestarrating'), 'actions' => array('update'),
    ),
    'simplesearch' => array(
        'title' => 'SimpleSearch', 'status' => 'compatible_from',
        'notes' => 'Prefer msCatalyst on shop sites.', 'actions' => array('update'),
    ),
    'sweetalert2' => array(
        'title' => 'SweetAlert2', 'status' => 'compatible_from', 'actions' => array('update'),
    ),
    'tagelementplugin' => array(
        'title' => 'tagElementPlugin', 'status' => 'compatible_from', 'actions' => array('update'),
    ),
    'tinycompressor' => array(
        'title' => 'tinyCompressor', 'status' => 'compatible_from', 'actions' => array('update'),
    ),
    'translit' => array(
        'title' => 'translit', 'status' => 'compatible', 'modx3' => true, 'actions' => array('update'),
    ),
    'units' => array(
        'title' => 'Units', 'status' => 'unknown',
        'notes' => 'Legacy units extra — verify.', 'actions' => array('verify'),
    ),
    'xbuttons' => array(
        'title' => 'xButtons', 'status' => 'compatible_from', 'actions' => array('update'),
    ),
    'msoptionsprice' => array(
        'title' => 'msOptionsPrice', 'status' => 'replaced_by', 'successor' => 'minishop3',
        'quarantine' => 'post_ms3', 'modx3' => false, 'actions' => array('replace'),
        'risks' => array('ms2_extra'),
    ),
    'msproductremains' => array(
        'title' => 'msProductRemains', 'status' => 'incompatible', 'quarantine' => 'post_ms3',
        'modx3' => false, 'actions' => array('remove'), 'risks' => array('ms2_extra'),
    ),
    'msbalance' => array(
        'title' => 'msBalance', 'status' => 'incompatible', 'quarantine' => 'post_ms3',
        'modx3' => false, 'actions' => array('remove'), 'risks' => array('ms2_extra'),
    ),
    'msbonus' => array(
        'title' => 'msBonus', 'status' => 'incompatible', 'quarantine' => 'post_ms3',
        'modx3' => false, 'actions' => array('remove'), 'risks' => array('ms2_extra'),
    ),
    'msbonus2' => array(
        'title' => 'msBonus2', 'status' => 'incompatible', 'quarantine' => 'post_ms3',
        'modx3' => false, 'actions' => array('remove'), 'risks' => array('ms2_extra'),
    ),
    'mspromocode' => array(
        'title' => 'msPromoCode', 'status' => 'incompatible', 'quarantine' => 'post_ms3',
        'modx3' => false, 'actions' => array('remove'), 'risks' => array('ms2_extra'),
    ),
    'mspromocode2' => array(
        'title' => 'msPromoCode2', 'status' => 'incompatible', 'quarantine' => 'post_ms3',
        'modx3' => false, 'actions' => array('remove'), 'risks' => array('ms2_extra'),
    ),
    'mssetincart' => array(
        'title' => 'msSetInCart', 'status' => 'incompatible', 'quarantine' => 'post_ms3',
        'modx3' => false, 'actions' => array('remove'), 'risks' => array('ms2_extra'),
    ),
    'msliveprice' => array(
        'title' => 'msLivePrice', 'status' => 'incompatible', 'quarantine' => 'post_ms3',
        'modx3' => false, 'actions' => array('remove'), 'risks' => array('ms2_extra'),
    ),
    'msinform' => array(
        'title' => 'msInform', 'status' => 'incompatible', 'quarantine' => 'post_ms3',
        'modx3' => false, 'actions' => array('remove'), 'risks' => array('ms2_extra'),
    ),
    'msproductscopy' => array(
        'title' => 'msProductsCopy', 'status' => 'incompatible', 'quarantine' => 'post_ms3',
        'modx3' => false, 'actions' => array('remove'), 'risks' => array('ms2_extra'),
    ),
    'mscarousel' => array(
        'title' => 'msCarousel', 'status' => 'incompatible', 'quarantine' => 'post_ms3',
        'modx3' => false, 'actions' => array('remove'), 'risks' => array('ms2_extra'),
    ),
    'msvendor' => array(
        'title' => 'msVendor (legacy)', 'status' => 'replaced_by', 'successor' => 'minishop3',
        'quarantine' => 'post_ms3', 'actions' => array('replace'),
    ),
    'currencyrate' => array(
        'title' => 'currencyRate', 'status' => 'compatible_from', 'actions' => array('update'),
    ),
    'frontendmanager' => array(
        'title' => 'frontendManager', 'status' => 'compatible_from', 'actions' => array('update'),
    ),
);

foreach ($new as $k => $row) {
    if (!isset($data['packages'][$k])) {
        $data['packages'][$k] = $row;
        continue;
    }
    foreach ($row as $fk => $fv) {
        if (!isset($data['packages'][$k][$fk])) {
            $data['packages'][$k][$fk] = $fv;
        }
    }
}

foreach ($prepare as $n) {
    if (isset($data['packages'][$n])) {
        $data['packages'][$n]['quarantine'] = 'prepare';
    }
}
foreach ($postMs3 as $n) {
    if (isset($data['packages'][$n])) {
        $data['packages'][$n]['quarantine'] = 'post_ms3';
    }
}

$data['version'] = '1.2.0';
ksort($data['packages']);
file_put_contents(
    $path,
    json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n"
);

$prep = $post = 0;
foreach ($data['packages'] as $p) {
    if (!empty($p['quarantine']) && $p['quarantine'] === 'prepare') {
        $prep++;
    }
    if (!empty($p['quarantine']) && $p['quarantine'] === 'post_ms3') {
        $post++;
    }
}
echo 'packages=' . count($data['packages']) . " version={$data['version']} prepare=$prep post_ms3=$post\n";
