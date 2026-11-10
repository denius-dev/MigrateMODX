<?php
/**
 * Manager page Packages → MigrateMODX (plain JS UI, MODX 2 + 3).
 */
if (class_exists('\\MODX\\Revolution\\modExtraManagerController')) {
    abstract class MigrateModxManagerControllerBase extends \MODX\Revolution\modExtraManagerController
    {
    }
} else {
    abstract class MigrateModxManagerControllerBase extends modExtraManagerController
    {
    }
}

class MigratemodxIndexManagerController extends MigrateModxManagerControllerBase
{
    public function initialize()
    {
        $this->modx->lexicon->load('migratemodx:default');
        return parent::initialize();
    }

    public function getLanguageTopics()
    {
        return array('migratemodx:default');
    }

    public function checkPermissions()
    {
        return $this->modx->hasPermission('packages')
            || $this->modx->hasPermission('workspaces')
            || $this->modx->hasPermission('settings');
    }

    public function getPageTitle()
    {
        return $this->modx->lexicon('migratemodx');
    }

    public function loadCustomCssJs()
    {
        $this->modx->lexicon->load('migratemodx:default');
        $assetsUrl = $this->modx->getOption('migratemodx_assets_url', null, $this->modx->getOption('assets_url') . 'components/migratemodx/');
        $assetsPath = $this->modx->getOption('migratemodx_assets_path', null, $this->modx->getOption('assets_path') . 'components/migratemodx/');
        $v = function ($file) use ($assetsPath) {
            return is_file($assetsPath . $file) ? '?v=' . filemtime($assetsPath . $file) : '';
        };
        $this->addCss($assetsUrl . 'mgr/migratemodx-admin.css' . $v('mgr/migratemodx-admin.css'));
        $this->addLastJavascript($assetsUrl . 'mgr/migratemodx-admin.js' . $v('mgr/migratemodx-admin.js'));

        $lang = array();
        foreach ($this->modx->lexicon->fetch('migratemodx_admin_', true) as $k => $s) {
            $lang[$k] = $s;
        }
        $config = array(
            'connector' => $assetsUrl . 'connector.php',
            'token' => $this->modx->user->getUserToken('mgr'),
            'siteUrl' => $this->modx->getOption('site_url'),
            'lang' => $lang,
            'cultureKey' => $this->modx->getOption('manager_language', null, $this->modx->getOption('cultureKey', null, 'en')),
            'requireStaging' => (bool)$this->modx->getOption('migratemodx_require_staging', null, true),
            'targetModx' => (string)$this->modx->getOption('migratemodx_target_modx', null, 'auto'),
            'targetPhp' => (string)$this->modx->getOption('migratemodx_target_php', null, 'auto'),
        );
        $this->addHtml('<script>window.MigrateMODXAdmin = '
            . json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP)
            . ';</script>');
    }

    public function process(array $scriptProperties = array())
    {
        return '<div id="mmx-root" class="mmx"></div>';
    }

    public function getTemplateFile()
    {
        return '';
    }
}
