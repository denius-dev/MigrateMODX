<?php
/**
 * Reset MigrateMODX progress after snapshot restore (file-only, no modX bootstrap).
 */
class MmxPostRollback
{
    /**
     * @param string $storePath migratemodx-data/
     * @param string $snapshotId
     * @param string $reason manager_rollback|recovery_rollback|stale_modx2_after_modx3_run
     * @return array{ok:bool,run_file?:string,flag_file?:string,archived?:string[]}
     */
    public static function resetMigrateProgress($storePath, $snapshotId = '', $reason = 'rollback')
    {
        $storePath = rtrim(str_replace('\\', '/', (string)$storePath), '/') . '/';
        if (!is_dir($storePath)) {
            @mkdir($storePath, 0755, true);
        }
        $stamp = date('Ymd-His');
        $archived = array();
        foreach (array(
            'migrate-run.json',
            'last-attention.json',
            'last-audit.json',
            'attention.md',
            'post-upgrade-diff.json',
            'ms2-retire.json',
            'ms3-catalog-repair.json',
            'vendor-compat.json',
        ) as $name) {
            $path = $storePath . $name;
            if (!is_file($path)) {
                continue;
            }
            $dest = $storePath . 'archive-' . $stamp . '-' . $name;
            if (@rename($path, $dest)) {
                $archived[] = basename($dest);
            } else {
                @copy($path, $dest);
                @unlink($path);
                $archived[] = basename($dest);
            }
        }

        $run = array(
            'id' => 'post-rollback-' . $stamp,
            'goal' => 'modx3',
            'started_at' => date('c'),
            'status' => 'reset_after_rollback',
            'phase' => 1,
            'steps' => array(),
            'upgrade_ready' => false,
            'hard_blocked' => false,
            'reset_from_snapshot' => (string)$snapshotId,
            'reset_reason' => (string)$reason,
            'reset_at' => date('c'),
        );
        $runFile = $storePath . 'migrate-run.json';
        @file_put_contents(
            $runFile,
            json_encode($run, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );

        $flagFile = $storePath . 'post-rollback.json';
        @file_put_contents(
            $flagFile,
            json_encode(array(
                'pending_audit' => true,
                'snapshot_id' => (string)$snapshotId,
                'reason' => (string)$reason,
                'at' => date('c'),
            ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );

        return array(
            'ok' => is_file($runFile),
            'run_file' => $runFile,
            'flag_file' => $flagFile,
            'archived' => $archived,
            'reason' => $reason,
        );
    }

    /**
     * @param string $storePath
     * @return array|null
     */
    public static function readFlag($storePath)
    {
        $storePath = rtrim(str_replace('\\', '/', (string)$storePath), '/') . '/';
        $flagFile = $storePath . 'post-rollback.json';
        if (!is_file($flagFile)) {
            return null;
        }
        $data = json_decode((string)file_get_contents($flagFile), true);
        return is_array($data) ? $data : null;
    }

    /**
     * @param string $storePath
     * @param array $patch
     * @return void
     */
    public static function updateFlag($storePath, array $patch)
    {
        $storePath = rtrim(str_replace('\\', '/', (string)$storePath), '/') . '/';
        $flagFile = $storePath . 'post-rollback.json';
        $cur = self::readFlag($storePath);
        if (!is_array($cur)) {
            $cur = array();
        }
        foreach ($patch as $k => $v) {
            $cur[$k] = $v;
        }
        @file_put_contents($flagFile, json_encode($cur, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    /**
     * True when migrate-run claims MODX 3 progress but live site is still/again MODX 2.
     *
     * @param array|null $run
     * @param int $liveMajor
     * @return bool
     */
    public static function looksStaleAfterRollback($run, $liveMajor)
    {
        if ($liveMajor >= 3 || !is_array($run)) {
            return false;
        }
        if (!empty($run['core_applied_at']) || !empty($run['ms3_data_ok']) || !empty($run['ms2_retired_at'])) {
            return true;
        }
        if (!empty($run['core_version']) && preg_match('/^3\b/', (string)$run['core_version'])) {
            return true;
        }
        if (!empty($run['status']) && $run['status'] === 'reset_after_rollback') {
            return false;
        }
        return false;
    }
}
