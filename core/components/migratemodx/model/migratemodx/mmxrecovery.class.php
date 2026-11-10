<?php
require_once dirname(__FILE__) . '/mmxpaths.class.php';

/**
 * Activate / deactivate standalone Recovery Console.
 */
class MmxRecovery
{
    protected $storePath;
    protected $siteRoot;
    protected $assetsPath;

    public function __construct($storePath, $siteRoot, $assetsPath)
    {
        $this->storePath = rtrim(str_replace('\\', '/', $storePath), '/') . '/';
        $this->siteRoot = rtrim(str_replace('\\', '/', $siteRoot), '/') . '/';
        $this->assetsPath = rtrim(str_replace('\\', '/', $assetsPath), '/') . '/';
    }

    public function lockFile()
    {
        return $this->storePath . 'recovery.lock';
    }

    public function status($siteUrl = '')
    {
        $lock = $this->lockFile();
        if (!is_file($lock)) {
            return array('active' => false);
        }
        $data = json_decode((string)file_get_contents($lock), true);
        if (!is_array($data)) {
            return array('active' => false);
        }
        $token = isset($data['token']) ? (string)$data['token'] : '';
        $paths = $this->buildUrls($siteUrl, $token);
        return array(
            'active' => !empty($data['active']),
            'token' => $token,
            'url_path' => $paths['url_path'],
            'url' => $paths['url'],
            'shortcut' => $paths['shortcut'],
            'shortcut_url' => $paths['shortcut_url'],
            'created_at' => isset($data['created_at']) ? $data['created_at'] : null,
        );
    }

    public function activate($password = null, $siteUrl = '')
    {
        if (!is_dir($this->storePath)) {
            @mkdir($this->storePath, 0755, true);
        }
        $token = bin2hex(random_bytes(16));
        if ($password === null || $password === '') {
            $password = $this->randomPassword(12);
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $data = array(
            'active' => true,
            'token' => $token,
            'password_hash' => $hash,
            'store_path' => $this->storePath,
            'site_root' => $this->siteRoot,
            'created_at' => date('c'),
            'failures' => 0,
            'locked_until' => 0,
        );
        file_put_contents($this->lockFile(), json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        /* root shortcut — absolute path from site root */
        $shortcut = $this->siteRoot . 'migratemodx-recovery.php';
        $code = "<?php\n\$token = " . var_export($token, true) . ";\n"
            . "header('Location: assets/components/migratemodx/recovery/index.php?token=' . rawurlencode(\$token));\n"
            . "exit;\n";
        @file_put_contents($shortcut, $code);

        /* One-click first aid for empty manager after /setup/ (token-gated, no FTP). */
        $firstAid = $this->siteRoot . 'migratemodx-firstaid.php';
        $faCode = "<?php\n\$token = " . var_export($token, true) . ";\n"
            . "\$q = isset(\$_GET['go']) ? '&go=1' : '';\n"
            . "header('Location: assets/components/migratemodx/recovery/firstaid.php?token=' . rawurlencode(\$token) . \$q);\n"
            . "exit;\n";
        @file_put_contents($firstAid, $faCode);

        $paths = $this->buildUrls($siteUrl, $token);
        $base = rtrim(str_replace('\\', '/', (string)$siteUrl), '/');
        if ($base !== '' && !preg_match('#^https?://#i', $base)) {
            $base = 'https://' . ltrim($base, '/');
        }
        return array(
            'ok' => true,
            'token' => $token,
            'password' => $password,
            'url_path' => $paths['url_path'],
            'url' => $paths['url'],
            'shortcut' => $paths['shortcut'],
            'shortcut_url' => $paths['shortcut_url'],
            'firstaid' => 'migratemodx-firstaid.php',
            'firstaid_url' => $base !== '' ? $base . '/migratemodx-firstaid.php' : 'migratemodx-firstaid.php',
        );
    }

    /**
     * @return array{url_path:string,url:string,shortcut:string,shortcut_url:string}
     */
    public function buildUrls($siteUrl, $token)
    {
        $base = rtrim(str_replace('\\', '/', (string)$siteUrl), '/');
        if ($base !== '' && !preg_match('#^https?://#i', $base)) {
            $base = 'https://' . ltrim($base, '/');
        }
        $path = 'assets/components/migratemodx/recovery/index.php';
        if ($token !== '') {
            $path .= '?token=' . rawurlencode($token);
        }
        $shortcut = 'migratemodx-recovery.php';
        return array(
            'url_path' => $path,
            'url' => $base !== '' ? $base . '/' . $path : $path,
            'shortcut' => $shortcut,
            'shortcut_url' => $base !== '' ? $base . '/' . $shortcut : $shortcut,
        );
    }

    public function deactivate()
    {
        $lock = $this->lockFile();
        if (is_file($lock)) {
            $data = json_decode((string)file_get_contents($lock), true);
            if (is_array($data)) {
                $data['active'] = false;
                $data['token'] = '';
                $data['password_hash'] = '';
                file_put_contents($lock, json_encode($data, JSON_PRETTY_PRINT));
            } else {
                @unlink($lock);
            }
        }
        $shortcut = $this->siteRoot . 'migratemodx-recovery.php';
        if (is_file($shortcut)) {
            @unlink($shortcut);
        }
        $firstAid = $this->siteRoot . 'migratemodx-firstaid.php';
        if (is_file($firstAid)) {
            @unlink($firstAid);
        }
        return array('ok' => true, 'active' => false);
    }

    /**
     * Ensure root first-aid shortcut exists for an already-active Recovery lock.
     *
     * @return array{ok:bool,firstaid?:string,firstaid_url?:string}
     */
    public function ensureFirstAidShortcut($siteUrl = '')
    {
        $st = $this->status($siteUrl);
        if (empty($st['active']) || empty($st['token'])) {
            return array('ok' => false);
        }
        $token = (string)$st['token'];
        $firstAid = $this->siteRoot . 'migratemodx-firstaid.php';
        $faCode = "<?php\n\$token = " . var_export($token, true) . ";\n"
            . "\$q = isset(\$_GET['go']) ? '&go=1' : '';\n"
            . "header('Location: assets/components/migratemodx/recovery/firstaid.php?token=' . rawurlencode(\$token) . \$q);\n"
            . "exit;\n";
        @file_put_contents($firstAid, $faCode);
        $base = rtrim(str_replace('\\', '/', (string)$siteUrl), '/');
        if ($base !== '' && !preg_match('#^https?://#i', $base)) {
            $base = 'https://' . ltrim($base, '/');
        }
        return array(
            'ok' => true,
            'firstaid' => 'migratemodx-firstaid.php',
            'firstaid_url' => $base !== '' ? $base . '/migratemodx-firstaid.php' : 'migratemodx-firstaid.php',
        );
    }

    protected function randomPassword($len = 12)
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $out;
    }
}
