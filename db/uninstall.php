<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_local_edunotify_uninstall() {
    global $DB;
    $dbman = $DB->get_manager();

    $botdir = __DIR__ . '/../bot';
    $pidfile = $botdir . '/bot.pid';
    if (file_exists($pidfile)) {
        $pid = trim(file_get_contents($pidfile));
        if (is_numeric($pid)) {
            exec("kill $pid 2>/dev/null");
            exec("kill -9 $pid 2>/dev/null");
        }
        @unlink($pidfile);
    }
    exec("pkill -f 'node.*edunotify.*server.js' 2>/dev/null");

    $sessiondir = $botdir . '/session';
    if (is_dir($sessiondir)) {
        exec('rm -rf ' . escapeshellarg($sessiondir) . ' 2>/dev/null');
    }

    $tables = [
        'local_edunotify_recipient',
        'local_edunotify_optout',
        'local_edunotify_course_optout',
        'local_edunotify_queue',
        'local_edunotify_templates',
        'local_edunotify_log',
    ];
    foreach ($tables as $tablename) {
        $table = new xmldb_table($tablename);
        if ($dbman->table_exists($table)) {
            $dbman->drop_table($table);
        }
    }

    unset_config('apikey', 'local_edunotify');
    unset_config('botport', 'local_edunotify');
    unset_config('batchsize', 'local_edunotify');
    unset_config('batchpause', 'local_edunotify');
    unset_config('quietstart', 'local_edunotify');
    unset_config('quietend', 'local_edunotify');
    unset_config('template_course_module_created', 'local_edunotify');
    unset_config('allowed_modtypes', 'local_edunotify');
    unset_config('bot_status_cache', 'local_edunotify');
    unset_config('bot_status_cache_time', 'local_edunotify');

    return true;
}
