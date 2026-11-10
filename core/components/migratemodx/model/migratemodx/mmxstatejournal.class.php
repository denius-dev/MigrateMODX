<?php
/**
 * Disk journal readable by CMP and Recovery Console without modX.
 */
class MmxStateJournal
{
    protected $dir;

    public function __construct($storePath)
    {
        $this->dir = rtrim(str_replace('\\', '/', $storePath), '/') . '/journals/';
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0755, true);
        }
    }

    public function stateFile()
    {
        return $this->dir . 'state.json';
    }

    public function read()
    {
        $f = $this->stateFile();
        if (!is_file($f)) {
            return array(
                'phase' => 'idle',
                'target_version' => '',
                'last_error' => '',
                'messages' => array(),
                'updated_at' => null,
            );
        }
        $data = json_decode((string)file_get_contents($f), true);
        return is_array($data) ? $data : array();
    }

    public function write(array $patch)
    {
        $cur = $this->read();
        $next = array_merge($cur, $patch);
        $next['updated_at'] = date('c');
        if (!isset($next['messages']) || !is_array($next['messages'])) {
            $next['messages'] = array();
        }
        file_put_contents($this->stateFile(), json_encode($next, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return $next;
    }

    public function log($message, $level = 'info')
    {
        $cur = $this->read();
        $msgs = isset($cur['messages']) && is_array($cur['messages']) ? $cur['messages'] : array();
        $msgs[] = array('t' => date('c'), 'level' => $level, 'msg' => (string)$message);
        if (count($msgs) > 500) {
            $msgs = array_slice($msgs, -500);
        }
        return $this->write(array('messages' => $msgs));
    }

    public function setError($error)
    {
        $this->log($error, 'error');
        return $this->write(array('last_error' => (string)$error));
    }
}
