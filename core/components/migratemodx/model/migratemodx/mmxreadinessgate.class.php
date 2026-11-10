<?php
class MmxReadinessGate
{
    public function evaluate(array $report, array $accepted = array())
    {
        $blockers = array();
        $warnings = array();

        foreach ($this->collectIssues($report) as $issue) {
            $key = isset($issue['key']) ? $issue['key'] : (isset($issue['code']) ? $issue['code'] : md5(json_encode($issue)));
            if (!empty($accepted[$key])) {
                $issue['accepted'] = true;
                $warnings[] = $issue;
                continue;
            }
            if (isset($issue['severity']) && $issue['severity'] === 'blocker') {
                $blockers[] = $issue;
            } else {
                $warnings[] = $issue;
            }
        }

        $hasSnapshot = !empty($report['snapshots']['latest']);
        $phpReady = true;
        foreach ($blockers as $b) {
            if (isset($b['domain']) && $b['domain'] === 'php') {
                $phpReady = false;
                break;
            }
        }

        $upgradeReady = count($blockers) === 0 && $hasSnapshot;
        if (count($blockers) === 0 && !$hasSnapshot) {
            $warnings[] = array(
                'severity' => 'warning',
                'domain' => 'snapshot',
                'code' => 'no_snapshot',
                'key' => 'no_snapshot',
                'message' => 'Create a snapshot before upgrade (required gate).',
            );
        }

        $score = 100;
        $score -= count($blockers) * 15;
        $score -= min(40, count($warnings) * 2);
        if ($score < 0) {
            $score = 0;
        }

        return array(
            'score' => $score,
            'traffic' => count($blockers) ? 'red' : (count($warnings) ? 'yellow' : 'green'),
            'upgrade_ready' => $upgradeReady,
            'php_ready' => $phpReady && $this->noPhpBlockers($blockers),
            'blockers' => $blockers,
            'warnings' => $warnings,
        );
    }

    protected function noPhpBlockers(array $blockers)
    {
        foreach ($blockers as $b) {
            if (isset($b['domain']) && $b['domain'] === 'php') {
                return false;
            }
        }
        return true;
    }

    protected function collectIssues(array $report)
    {
        $issues = array();
        if (!empty($report['environment']['issues'])) {
            foreach ($report['environment']['issues'] as $i) {
                $i['domain'] = 'environment';
                $i['key'] = 'env:' . (isset($i['code']) ? $i['code'] : uniqid());
                $issues[] = $i;
            }
        }
        if (!empty($report['packages'])) {
            foreach ($report['packages'] as $p) {
                $name = isset($p['name']) ? $p['name'] : '';
                $verdict = isset($p['verdict']) ? $p['verdict'] : 'unknown';
                $resolution = isset($p['resolution']) ? $p['resolution'] : 'none';
                $alts = isset($p['alternatives']) ? $p['alternatives'] : array();
                $altNames = array();
                foreach ($alts as $a) {
                    $altNames[] = isset($a['name']) ? $a['name'] : (isset($a['signature']) ? $a['signature'] : '');
                }
                $altTxt = implode(', ', array_filter($altNames));

                if (in_array($verdict, array('fail', 'replace'), true) && $resolution === 'successor_installed') {
                    $issues[] = array(
                        'severity' => 'warning',
                        'domain' => 'package',
                        'code' => 'pkg_legacy_' . $name,
                        'key' => 'pkg_legacy:' . $name,
                        'message' => 'Legacy package "' . $name . '" still installed; successor present: '
                            . (isset($p['resolution_package']) ? $p['resolution_package'] : $altTxt)
                            . '. Finish migration, then uninstall the old package.',
                        'alternatives' => $alts,
                    );
                } elseif (in_array($verdict, array('fail', 'replace'), true) && in_array($resolution, array('none', 'path_known'), true)) {
                    $msg = 'Package "' . $name . '" cannot stay on MODX 3 as-is';
                    if ($verdict === 'replace' && $altTxt !== '') {
                        $msg .= ' — replace with: ' . $altTxt;
                    } elseif (!empty($p['migration'])) {
                        $msg .= ' — ' . $p['migration'];
                    } else {
                        $msg .= ' (verdict=' . $verdict . ')';
                    }
                    if (!empty($p['version'])) {
                        $msg .= ' [installed ' . $p['version'] . ']';
                    }
                    $issues[] = array(
                        'severity' => 'blocker',
                        'domain' => 'package',
                        'code' => 'pkg_' . $name,
                        'key' => 'pkg:' . $name,
                        'message' => $msg,
                        'alternatives' => $alts,
                    );
                } elseif ($verdict === 'update') {
                    $req = !empty($p['required_version']) ? $p['required_version'] : '?';
                    $issues[] = array(
                        'severity' => 'warning',
                        'domain' => 'package',
                        'code' => 'pkg_update_' . $name,
                        'key' => 'pkg_update:' . $name,
                        'message' => 'Package "' . $name . '" is installed as '
                            . (isset($p['version']) ? $p['version'] : '?')
                            . ' but MODX 3 needs at least ' . $req . ' — update before/during migration.',
                        'alternatives' => $alts,
                    );
                } elseif ($verdict === 'unknown') {
                    $issues[] = array(
                        'severity' => 'warning',
                        'domain' => 'package',
                        'code' => 'pkg_unknown_' . $name,
                        'key' => 'pkg_unknown:' . $name,
                        'message' => 'Package "' . $name . '" has unknown MODX 3 status'
                            . (!empty($p['version']) ? ' (installed ' . $p['version'] . ')' : ''),
                    );
                }
            }
        }
        if (!empty($report['heuristics'])) {
            foreach ($report['heuristics'] as $h) {
                $issues[] = array(
                    'severity' => isset($h['severity']) ? $h['severity'] : 'warning',
                    'domain' => 'modx3',
                    'code' => isset($h['code']) ? $h['code'] : 'heuristic',
                    'key' => 'modx3:' . (isset($h['code']) ? $h['code'] : 'x') . ':' . (isset($h['name']) ? $h['name'] : ''),
                    'message' => (isset($h['source']) ? $h['source'] . ' ' : '') . (isset($h['name']) ? $h['name'] . ': ' : '') . (isset($h['message']) ? $h['message'] : ''),
                );
            }
        }
        if (!empty($report['php'])) {
            foreach ($report['php'] as $h) {
                $issues[] = array(
                    'severity' => isset($h['severity']) ? $h['severity'] : 'warning',
                    'domain' => 'php',
                    'code' => isset($h['rule']) ? $h['rule'] : 'php',
                    'key' => 'php:' . (isset($h['rule']) ? $h['rule'] : 'x') . ':' . (isset($h['name']) ? $h['name'] : ''),
                    'message' => (isset($h['source']) ? $h['source'] . ' ' : '') . (isset($h['name']) ? $h['name'] . ': ' : '') . (isset($h['message']) ? $h['message'] : ''),
                );
            }
        }
        if (!empty($report['templates'])) {
            foreach ($report['templates'] as $h) {
                $issues[] = array(
                    'severity' => isset($h['severity']) ? $h['severity'] : 'warning',
                    'domain' => 'template',
                    'code' => isset($h['engine']) ? $h['engine'] : 'syntax',
                    'key' => 'tpl:' . (isset($h['source']) ? $h['source'] : '') . ':' . (isset($h['id']) ? $h['id'] : '') . ':' . md5(isset($h['message']) ? $h['message'] : ''),
                    'message' => (isset($h['source']) ? $h['source'] . ' ' : '') . (isset($h['name']) ? $h['name'] . ': ' : '') . (isset($h['message']) ? $h['message'] : ''),
                );
            }
        }
        return $issues;
    }
}
