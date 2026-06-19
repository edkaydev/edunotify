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
        } catch (\Exception $e) {
            $data = null;
        }
        set_config('bot_status_cache', json_encode($data), 'local_edunotify');
        set_config('bot_status_cache_time', (string)time(), 'local_edunotify');
        return $data;
    }

    public static function send_notification(array $payload): ?array {
        return api_client::call('/api/send', 'POST', $payload);
    }

    public static function start(): bool {
        global $CFG;
        $botdir = __DIR__ . '/../bot';
        $serverjs = realpath($botdir . '/server.js');
        if (!$serverjs || !file_exists($serverjs)) {
            self::$lastError = 'Bot server.js not found at ' . $botdir;
            return false;
        }

        $port = get_config('local_edunotify', 'botport') ?: 5001;
        $apikey = get_config('local_edunotify', 'apikey') ?: '';
        $countrycode = get_config('local_edunotify', 'countrycode') ?: '256';
        $batchsize = get_config('local_edunotify', 'batchsize') ?: 25;
        $batchpause = get_config('local_edunotify', 'batchpause') ?: 60;
        $quietstart = get_config('local_edunotify', 'quietstart') ?: '22:00';
        $quietend = get_config('local_edunotify', 'quietend') ?: '07:00';
        $wwwroot = $CFG->wwwroot ?? '';

        $env = [
            'EDUNOTIFY_BOT_PORT' => $port,
            'EDUNOTIFY_API_KEY' => $apikey,
            'COUNTRY_CODE' => $countrycode,
            'EDUNOTIFY_BATCH_SIZE' => $batchsize,
            'EDUNOTIFY_BATCH_PAUSE_MS' => $batchpause * 1000,
            'EDUNOTIFY_QUIET_START' => $quietstart,
            'EDUNOTIFY_QUIET_END' => $quietend,
            'EDUNOTIFY_OPTOUT_URL' => $wwwroot . '/local/edunotify/editphone.php',
            'PATH' => getenv('PATH'),
        ];

        $envstr = '';
        foreach ($env as $k => $v) {
            $envstr .= escapeshellarg($k) . '=' . escapeshellarg((string)$v) . ' ';
        }

        $logfile = $botdir . '/bot.log';
        $cmd = 'nohup ' . $envstr . 'node ' . escapeshellarg($serverjs) . ' >> ' . escapeshellarg($logfile) . ' 2>&1 & echo $!';
        $output = shell_exec($cmd);
        $pid = trim($output ?? '');

        if (is_numeric($pid) && (int)$pid > 0) {
            set_config('bot_pid', (int)$pid, 'local_edunotify');
            self::clear_cache();
            return true;
        }

        self::$lastError = 'Failed to start bot process (PID was: ' . $pid . ')';
        return false;
    }

    public static function stop(): bool {
        $pid = self::get_pid();
        if ($pid) {
            exec('kill ' . $pid . ' 2>/dev/null');
            sleep(1);
            $stillrunning = trim(shell_exec('ps -p ' . $pid . ' 2>/dev/null | wc -l') ?? '');
            if ((int)$stillrunning > 1) {
                exec('kill -9 ' . $pid . ' 2>/dev/null');
            }
            unset_config('bot_pid', 'local_edunotify');
            self::clear_cache();
            return true;
        }
        exec('pkill -f "node.*server\.js" 2>/dev/null');
        unset_config('bot_pid', 'local_edunotify');
        self::clear_cache();
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
            $running = trim(shell_exec('ps -p ' . (int)$pid . ' 2>/dev/null | wc -l') ?? '');
            if ((int)$running > 1) {
                return (int)$pid;
            }
            unset_config('bot_pid', 'local_edunotify');
        }
        $pgrep = trim(shell_exec('pgrep -f "node.*server\.js" 2>/dev/null') ?? '');
        if (is_numeric($pgrep) && (int)$pgrep > 0) {
            set_config('bot_pid', (int)$pgrep, 'local_edunotify');
            return (int)$pgrep;
        }
        return null;
    }

    private static function clear_cache(): void {
        unset_config('bot_status_cache', 'local_edunotify');
        unset_config('bot_status_cache_time', 'local_edunotify');
    }
}
