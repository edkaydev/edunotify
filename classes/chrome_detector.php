<?php
namespace local_edunotify;

defined('MOODLE_INTERNAL') || die();

class chrome_detector {
    public static function detect(): ?string {
        $env = getenv('PUPPETEER_EXECUTABLE_PATH');
        if (!empty($env) && file_exists($env)) {
            return $env;
        }
        $candidates = [
            '/usr/bin/google-chrome-stable', '/usr/bin/google-chrome',
            '/usr/bin/chromium-browser', '/usr/bin/chromium',
            '/snap/bin/chromium', '/snap/bin/google-chrome',
            '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        ];
        foreach ($candidates as $p) {
            if (file_exists($p)) return $p;
        }
        foreach (['/var/www', getenv('HOME') ?: '/root'] as $home) {
            $cache = $home . '/.cache/puppeteer';
            if (is_dir($cache)) {
                $dirs = glob($cache . '/chrome/*/chrome-linux64/chrome');
                if (!empty($dirs)) return $dirs[0];
                $dirs = glob($cache . '/chrome/*/chrome-mac-x64/Chromium.app/Contents/MacOS/Chromium');
                if (!empty($dirs)) return $dirs[0];
            }
        }
        return null;
    }

    public static function auto_install(): ?string {
        exec('sudo apt-get install -y -qq chromium-browser 2>/dev/null || sudo apt-get install -y -qq chromium 2>/dev/null');
        return self::detect();
    }
}
