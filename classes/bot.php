<?php
namespace local_edunotify;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/api_client.php');

class bot {
    private static $lastError = '';

    public static function is_running(): bool {
        $pid = self::get_pid();
        if ($pid) return true;

        $status = self::get_status();
        return $status !== null && ($status['status'] ?? '') === 'connected';
    }

    public static function get_status(): ?array {
        $cached = get_config('local_edunotify', 'bot_status_cache');
        $cachedTime = get_config('local_edunotify', 'bot_status_cache_time');
        if ($cached && $cachedTime && (time() - (int)$cachedTime) < 30) {
            $data = json_decode($cached, true);
            if (is_array($data)) return $data;
        }
        try {
            $data = api_client::call('/api/status', 'GET');
        } catch (\Throwable $e) {
            $data = null;
        }
        if (is_array($data)) {
            set_config('bot_status_cache', json_encode($data), 'local_edunotify');
            set_config('bot_status_cache_time', (string)time(), 'local_edunotify');
        }
        return $data;
    }

    public static function send_notification(array $payload): ?array {
        return api_client::call('/api/send', 'POST', $payload);
    }

    public static function check_requirements(): array {
        $cached = get_config('local_edunotify', 'reqcheck_cache');
        $cachedtime = get_config('local_edunotify', 'reqcheck_cache_time');
        if ($cached && $cachedtime && (time() - (int)$cachedtime) < 30) {
            $data = json_decode($cached, true);
            if (is_array($data)) return $data;
        }
        $checks = self::check_requirements_uncached();
        set_config('reqcheck_cache', json_encode($checks), 'local_edunotify');
        set_config('reqcheck_cache_time', (string)time(), 'local_edunotify');
        return $checks;
    }

    public static function check_requirements_uncached(): array {
        $botdir = __DIR__ . '/../bot';
        $hasnode = null;
        $hasnpm = null;
        $haschrome = null;
        try {
            $hasnode = trim((shell_exec('which node 2>/dev/null') ?? ''));
        } catch (\Throwable $e) {}
        try {
            $hasnpm = trim((shell_exec('which npm 2>/dev/null') ?? ''));
        } catch (\Throwable $e) {}
        $hasdeps = is_dir($botdir . '/node_modules');
        try {
            $haschrome = \local_edunotify\chrome_detector::detect();
        } catch (\Throwable $e) {}

        return [
            ['name' => 'Node.js', 'status' => !empty($hasnode), 'fixable' => false, 'fixed' => false, 'cmd' => 'sudo apt install -y nodejs npm'],
            ['name' => 'npm', 'status' => !empty($hasnpm), 'fixable' => false, 'fixed' => false, 'cmd' => 'sudo apt install -y nodejs npm'],
            ['name' => 'Bot dependencies', 'status' => $hasdeps, 'fixable' => true, 'fixed' => false, 'cmd' => 'cd ' . $botdir . ' && npm install'],
            ['name' => 'Chromium', 'status' => !empty($haschrome), 'fixable' => false, 'fixed' => false, 'cmd' => 'sudo apt install -y chromium-browser'],
        ];
    }

    public static function was_intentionally_stopped(): bool {
        return (bool)get_config('local_edunotify', 'intentional_stop');
    }

    public static function start(): array {
        global $CFG;
        $botdir = __DIR__ . '/../bot';
        set_config('intentional_stop', 0, 'local_edunotify');

        $checks = self::check_requirements_uncached();

        $allgood = true;
        foreach ($checks as &$check) {
            if (!$check['status']) {
                if ($check['fixable']) {
                    try {
                        $installCmd = 'cd ' . escapeshellarg($botdir) . ' && npm install 2>&1';
                        shell_exec($installCmd);
                    } catch (\Throwable $e) {}
                    $fixed = is_dir($botdir . '/node_modules');
                    $check['status'] = $fixed;
                    $check['fixed'] = $fixed;
                    if (!$fixed) {
                        $allgood = false;
                    }
                } else {
                    $allgood = false;
                }
            }
        }
        unset($check);
        // Requirements may have just changed (e.g. deps installed) - bust the display cache.
        unset_config('reqcheck_cache', 'local_edunotify');
        unset_config('reqcheck_cache_time', 'local_edunotify');

        if (!$allgood) {
            self::$lastError = '';
            foreach ($checks as $c) {
                if (!$c['status']) {
                    self::$lastError .= $c['name'] . ' is missing. ';
                }
            }
            return ['success' => false, 'checks' => $checks, 'error' => self::$lastError];
        }

        $serverjs = realpath($botdir . '/server.js');
        if (!$serverjs || !file_exists($serverjs)) {
            self::$lastError = 'Bot server.js not found';
            return ['success' => false, 'checks' => $checks, 'error' => self::$lastError];
        }

        $sessiondir = $botdir . '/session';
        if (!is_dir($sessiondir)) {
            @mkdir($sessiondir, 0755, true);
        }
        $sessionsub = $sessiondir . '/session';
        if (!is_dir($sessionsub)) {
            @mkdir($sessionsub, 0755, true);
        }

        $port = get_config('local_edunotify', 'botport') ?: 5001;
        $apikey = get_config('local_edunotify', 'apikey') ?: '';
        if (empty($apikey)) {
            try {
                $apikey = bin2hex(random_bytes(32));
            } catch (\Throwable $e) {
                $apikey = md5(time() . rand());
            }
            set_config('apikey', $apikey, 'local_edunotify');
        }
        $countrycode = get_config('local_edunotify', 'countrycode') ?: '256';
        $batchsize = get_config('local_edunotify', 'batchsize') ?: 20;
        $batchpause = get_config('local_edunotify', 'batchpause') ?: 90;
        $dailylimit = get_config('local_edunotify', 'dailylimit') ?: 500;
        $hourlylimit = get_config('local_edunotify', 'hourlylimit') ?: 80;
        $wwwroot = $CFG->wwwroot ?? '';

        $env = [
            'EDUNOTIFY_BOT_PORT' => $port,
            'EDUNOTIFY_API_KEY' => $apikey,
            'COUNTRY_CODE' => $countrycode,
            'EDUNOTIFY_BATCH_SIZE' => $batchsize,
            'EDUNOTIFY_BATCH_PAUSE_MS' => $batchpause * 1000,
            'EDUNOTIFY_DAILY_LIMIT' => $dailylimit,
            'EDUNOTIFY_HOURLY_LIMIT' => $hourlylimit,
            'EDUNOTIFY_OPTOUT_URL' => $wwwroot . '/local/edunotify/editphone.php',
            'PATH' => getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        ];

        $envstr = '';
        foreach ($env as $k => $v) {
            $envstr .= $k . '=' . escapeshellarg((string)$v) . ' ';
        }

        $logfile = $botdir . '/bot.log';
        $cmd = 'nohup env ' . $envstr . 'node ' . escapeshellarg($serverjs) . ' >> ' . escapeshellarg($logfile) . ' 2>&1 & echo $!';
        $pid = null;
        try {
            $output = shell_exec($cmd);
            $pid = trim($output ?? '');
        } catch (\Throwable $e) {
            self::$lastError = 'Failed to execute bot start: ' . $e->getMessage();
            return ['success' => false, 'checks' => $checks, 'error' => self::$lastError];
        }

        if (is_numeric($pid) && (int)$pid > 0) {
            set_config('bot_pid', (int)$pid, 'local_edunotify');
            self::clear_cache();

            $started = false;
            $status = null;
            // Poll up to ~9s total: first for the API to respond at all, then a
            // little longer specifically for a QR code if status is awaiting_scan,
            // since whatsapp-web.js can take a couple of seconds after launch to
            // generate one. This avoids handing back an empty QR box to the page.
            for ($i = 0; $i < 18; $i++) {
                usleep(500000);
                $status = self::get_status();
                if (is_array($status)) {
                    $started = true;
                    $hasqr = !empty($status['qr']);
                    $awaitingscan = ($status['status'] ?? '') === 'awaiting_scan';
                    if ($hasqr || !$awaitingscan) {
                        break;
                    }
                    // status is awaiting_scan but qr isn't populated yet - keep polling.
                    self::clear_cache();
                }
            }

            if ($started) {
                return [
                    'success' => true,
                    'checks' => $checks,
                    'pid' => (int)$pid,
                    'status' => $status,
                ];
            }

            self::$lastError = 'Bot process started but API not responding. Check bot.log.';
            return ['success' => false, 'checks' => $checks, 'error' => self::$lastError];
        }

        self::$lastError = 'Failed to start bot process';
        return ['success' => false, 'checks' => $checks, 'error' => self::$lastError];
    }

    public static function stop(): bool {
        $pid = self::get_pid();
        if ($pid) {
            try {
                exec('kill ' . $pid . ' 2>/dev/null');
                usleep(500000);
                exec('kill -0 ' . $pid . ' 2>/dev/null && kill -9 ' . $pid . ' 2>/dev/null');
            } catch (\Throwable $e) {}
            unset_config('bot_pid', 'local_edunotify');
            self::clear_cache();
            set_config('intentional_stop', 1, 'local_edunotify');
            return true;
        }
        try {
            exec('pkill -f "node.*server\.js" 2>/dev/null');
        } catch (\Throwable $e) {}
        unset_config('bot_pid', 'local_edunotify');
        self::clear_cache();
        set_config('intentional_stop', 1, 'local_edunotify');
        return true;
    }

    public static function reconnect(): ?array {
        $result = api_client::call('/api/reconnect', 'POST');
        self::clear_cache();
        return $result;
    }

    public static function logout(): bool {
        $result = api_client::call('/api/logout', 'POST');
        self::clear_cache();
        return $result !== null && !empty($result['success']);
    }

    public static function send_test(string $number, string $message): ?array {
        return api_client::call('/api/send-test', 'POST', [
            'number' => $number,
            'message' => $message,
        ]);
    }

    public static function get_last_error(): string {
        return self::$lastError;
    }

    public static function get_pid(): ?int {
        $pid = get_config('local_edunotify', 'bot_pid');
        if ($pid && is_numeric($pid) && (int)$pid > 0) {
            try {
                $running = trim((shell_exec('ps -p ' . (int)$pid . ' 2>/dev/null | wc -l') ?? ''));
                if ((int)$running > 1) {
                    return (int)$pid;
                }
            } catch (\Throwable $e) {}
            unset_config('bot_pid', 'local_edunotify');
        }
        try {
            $pgrep = trim((shell_exec('pgrep -f "node.*server\.js" 2>/dev/null') ?? ''));
            if (is_numeric($pgrep) && (int)$pgrep > 0) {
                set_config('bot_pid', (int)$pgrep, 'local_edunotify');
                return (int)$pgrep;
            }
        } catch (\Throwable $e) {}
        return null;
    }

    public static function clear_cache(): void {
        unset_config('bot_status_cache', 'local_edunotify');
        unset_config('bot_status_cache_time', 'local_edunotify');
        unset_config('reqcheck_cache', 'local_edunotify');
        unset_config('reqcheck_cache_time', 'local_edunotify');
    }
}
