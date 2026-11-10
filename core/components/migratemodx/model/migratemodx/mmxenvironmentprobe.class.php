<?php
class MmxEnvironmentProbe
{
    /** @var modX */
    protected $modx;

    public function __construct($modx)
    {
        $this->modx = $modx;
    }

    public function run($targetModx = '3.0.3', $targetPhp = '8.2')
    {
        $ver = $this->modx->getVersionData();
        $modxVersion = isset($ver['full_version']) ? $ver['full_version'] : (isset($ver['version']) ? $ver['version'] . '.' . $ver['major_version'] . '.' . $ver['minor_version'] : '0');
        $php = PHP_VERSION;
        $corePath = str_replace('\\', '/', rtrim(MODX_CORE_PATH, '/\\'));
        $siteRoot = str_replace('\\', '/', rtrim(MODX_BASE_PATH, '/\\'));
        $expectedCore = $siteRoot . '/core';
        $coreOk = (strcasecmp($corePath, $expectedCore) === 0) || (realpath($corePath) && realpath($expectedCore) && realpath($corePath) === realpath($expectedCore));

        $issues = array();
        $modxMajor = (int)(isset($ver['version']) ? $ver['version'] : 2);
        $modxMinor = (int)(isset($ver['major_version']) ? $ver['major_version'] : 0);
        $modxPatch = (int)(isset($ver['minor_version']) ? $ver['minor_version'] : 0);

        if ($modxMajor < 2 || ($modxMajor === 2 && $modxMinor < 6)) {
            $issues[] = array(
                'severity' => 'blocker',
                'code' => 'modx_too_old',
                'message' => 'MODX setup to 3.x requires at least 2.6.0. Current: ' . $modxVersion,
            );
        } elseif ($modxMajor === 2 && ($modxMinor < 8 || ($modxMinor === 8 && $modxPatch < 0))) {
            $issues[] = array(
                'severity' => 'warning',
                'code' => 'modx_not_28',
                'message' => 'Upgrade to latest 2.8.x before ' . $targetModx . '. Current: ' . $modxVersion,
            );
        }

        if (version_compare($php, $targetPhp, '<')) {
            $issues[] = array(
                'severity' => 'warning',
                'code' => 'php_below_target',
                'message' => 'Host PHP is ' . $php . '; target after migration is ' . $targetPhp . '. Prep code on 7.4 first, then switch PHP on the host.',
            );
        }
        if (version_compare($php, '7.2.5', '<')) {
            $issues[] = array(
                'severity' => 'blocker',
                'code' => 'php_below_modx3',
                'message' => 'MODX 3.0 requires PHP >= 7.2.5. Current: ' . $php,
            );
        }

        if (!$coreOk) {
            $issues[] = array(
                'severity' => 'blocker',
                'code' => 'custom_core_path',
                'message' => 'MODX 3 requires core/ in the project root (not renamed/moved). Current: ' . $corePath,
            );
        }

        if (defined('XPDO_DSN') && stripos((string)XPDO_DSN, 'sqlsrv') !== false) {
            $issues[] = array(
                'severity' => 'blocker',
                'code' => 'sqlsrv',
                'message' => 'sqlsrv support was removed in MODX 3.',
            );
        }

        $free = @disk_free_space(MODX_BASE_PATH);
        if ($free !== false && $free < 200 * 1024 * 1024) {
            $issues[] = array(
                'severity' => 'blocker',
                'code' => 'disk_low',
                'message' => 'Less than 200MB free disk space for snapshot + distro.',
            );
        }

        return array(
            'modx_version' => $modxVersion,
            'modx_major' => $modxMajor,
            'php_version' => $php,
            'target_modx' => $targetModx,
            'target_php' => $targetPhp,
            'core_path' => $corePath,
            'core_in_root' => $coreOk,
            'base_path' => $siteRoot,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'disk_free' => $free,
            'issues' => $issues,
        );
    }
}
