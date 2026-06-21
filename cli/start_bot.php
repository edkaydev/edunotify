<?php
/**
 * CLI script to start the bot process.
 * Usage: sudo -u www-data php cli/start_bot.php
 */

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once(__DIR__ . '/../classes/bot.php');

cli_heading('EduNotify Bot Starter');

if (\local_edunotify\bot::is_running()) {
    $pid = \local_edunotify\bot::get_pid();
    echo "Bot is already running (PID: $pid)\n";
    exit(0);
}

echo "Starting bot...\n";
if (\local_edunotify\bot::start()) {
    $pid = \local_edunotify\bot::get_pid();
    echo "Bot started successfully (PID: $pid)\n";

    sleep(2);
    $status = \local_edunotify\bot::get_status();
    echo "Status: " . ($status['message'] ?? 'unknown') . "\n";
} else {
    cli_error('Failed to start bot. Check error logs.');
}
