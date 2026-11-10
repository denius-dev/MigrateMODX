<?php
class MmxKnowledgeBase
{
    protected $packages = array();
    protected $version = '1.0.0';

    public function __construct($jsonPath)
    {
        if (is_file($jsonPath)) {
            $data = json_decode((string)file_get_contents($jsonPath), true);
            if (is_array($data)) {
                $this->version = isset($data['version']) ? $data['version'] : '1.0.0';
                $this->packages = isset($data['packages']) && is_array($data['packages']) ? $data['packages'] : array();
            }
        }
    }

    public function getVersion()
    {
        return $this->version;
    }

    public function lookup($signature)
    {
        $raw = strtolower((string)$signature);
        $sig = preg_replace('/[^a-z0-9_\-]/', '', $raw);
        if ($sig === '') {
            return null;
        }
        if (isset($this->packages[$sig])) {
            return $this->packages[$sig];
        }
        /* signature like minishop2-2.5.0-pl → minishop2 */
        if (preg_match('/^([a-z][a-z0-9_\-]*)-\d+/', $sig, $m) && isset($this->packages[$m[1]])) {
            return $this->packages[$m[1]];
        }
        foreach ($this->packages as $key => $row) {
            if ($key === $sig) {
                return $row;
            }
            if (!empty($row['aliases']) && is_array($row['aliases'])) {
                foreach ($row['aliases'] as $alias) {
                    if (strtolower((string)$alias) === $sig || strtolower((string)$alias) === $raw) {
                        return $row;
                    }
                }
            }
        }
        return null;
    }

    public function all()
    {
        return $this->packages;
    }

    /**
     * Package names marked for automatic quarantine at a migration phase.
     *
     * @param string $phase prepare|post_ms3
     * @return string[]
     */
    public function namesForQuarantine($phase)
    {
        $phase = strtolower(trim((string)$phase));
        $out = array();
        foreach ($this->packages as $name => $row) {
            if (!is_array($row)) {
                continue;
            }
            $q = isset($row['quarantine']) ? strtolower((string)$row['quarantine']) : '';
            if ($q === '') {
                /* Infer: setup killers from risks; other incompatible → post_ms3 */
                $risks = !empty($row['risks']) && is_array($row['risks']) ? $row['risks'] : array();
                $status = isset($row['status']) ? (string)$row['status'] : '';
                $blocksSetup = in_array('blocks_setup', $risks, true)
                    || in_array('blocks_manager', $risks, true);
                if ($blocksSetup) {
                    $q = 'prepare';
                } elseif (in_array($status, array('incompatible', 'abandoned'), true)
                    || (!empty($row['risks']) && in_array('ms2_extra', $risks, true))) {
                    $q = 'post_ms3';
                }
            }
            if ($q === $phase) {
                $out[] = strtolower((string)$name);
            }
        }
        $out = array_values(array_unique($out));
        sort($out);
        return $out;
    }

    /**
     * Default KB path next to this component.
     *
     * @return string
     */
    public static function defaultPath()
    {
        return dirname(__FILE__) . '/../../data/kb/packages.json';
    }
}
