<?php
class MmxHealthChecker
{
    /** @var modX */
    protected $modx;
    /** @var MmxStateJournal */
    protected $journal;

    public function __construct($modx, $journal = null)
    {
        $this->modx = $modx;
        $this->journal = $journal;
    }

    public function run()
    {
        $ver = $this->modx->getVersionData();
        $issues = array();

        $logFile = $this->modx->getOption('cache_path', null, MODX_CORE_PATH . 'cache/') . 'logs/error.log';
        $logTail = '';
        if (is_file($logFile)) {
            $logTail = $this->tail($logFile, 80);
            if (preg_match('/Fenom|parse error|Fatal error|Uncaught/i', $logTail)) {
                $issues[] = array(
                    'severity' => 'blocker',
                    'code' => 'error_log',
                    'message' => 'Recent fatal/parse errors in error.log (see details)',
                );
            }
        }

        /* namespaces with missing paths */
        $nsClass = class_exists('\\MODX\\Revolution\\modNamespace') ? '\\MODX\\Revolution\\modNamespace' : 'modNamespace';
        foreach ($this->modx->getCollection($nsClass) as $ns) {
            $path = $ns->getCorePath();
            if ($path && !is_dir($path) && $ns->get('name') !== 'core') {
                $issues[] = array(
                    'severity' => 'warning',
                    'code' => 'ns_path',
                    'message' => 'Namespace path missing: ' . $ns->get('name') . ' → ' . $path,
                );
            }
        }

        $front = array('ok' => null, 'status' => null);
        $url = $this->modx->getOption('site_url');
        if ($url) {
            $ctx = stream_context_create(array('http' => array('timeout' => 10, 'ignore_errors' => true)));
            $body = @file_get_contents($url, false, $ctx);
            $front['ok'] = $body !== false && $body !== '';
            if (isset($http_response_header[0])) {
                $front['status'] = $http_response_header[0];
            }
            if ($body !== false && preg_match('/Fenom|ParseError|Fatal error/i', $body)) {
                $issues[] = array(
                    'severity' => 'blocker',
                    'code' => 'front_error',
                    'message' => 'Homepage response contains PHP/Fenom error text',
                );
            }
        }

        $result = array(
            'modx_version' => isset($ver['full_version']) ? $ver['full_version'] : '',
            'php_version' => PHP_VERSION,
            'issues' => $issues,
            'front' => $front,
            'error_log_tail' => $logTail,
            'checked_at' => date('c'),
        );
        if ($this->journal) {
            $this->journal->write(array(
                'phase' => count($issues) ? 'health_failed' : 'health_ok',
                'last_error' => count($issues) ? $issues[0]['message'] : '',
            ));
            $this->journal->log('Health check: ' . count($issues) . ' issues');
        }
        return $result;
    }

    protected function tail($file, $lines = 50)
    {
        $data = @file($file);
        if (!$data) {
            return '';
        }
        return implode('', array_slice($data, -$lines));
    }
}
