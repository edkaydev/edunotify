<?php
define('CLI_SCRIPT', true);
define('NO_OUTPUT_BUFFERING', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once(__DIR__ . '/../classes/bot.php');

$botdir = __DIR__ . '/../bot';

cli_heading('EduNotify Bot Installer');
echo "Installing Node.js dependencies in: $botdir\n\n";

if (!file_exists($botdir . '/package.json')) {
    cli_error('package.json not found in bot directory');
}

$npm = trim(shell_exec('which npm 2>/dev/null') ?? '');
if (empty($npm)) {
    cli_error('npm is not installed. Install Node.js first.');
}

$node = trim(shell_exec('which node 2>/dev/null') ?? '');
if (empty($node)) {
    cli_error('node is not installed. Install Node.js first.');
}

echo "Node: " . trim(shell_exec('node --version')) . "\n";
echo "npm: " . trim(shell_exec('npm --version')) . "\n\n";

$skip = '';
$chrome = \local_edunotify\chrome_detector::detect();
if (!empty($chrome)) {
    $skip = 'PUPPETEER_SKIP_DOWNLOAD=true ';
    echo "System Chrome found at: $chrome\n";
}

$cmd = 'cd ' . escapeshellarg($botdir) . ' && ' . $skip . 'npm install --no-audit --no-fund 2>&1';
echo "Running: npm install\n";
passthru($cmd, $exitcode);

if ($exitcode === 0) {
    cli_heading('Success');
    echo "Bot dependencies installed.\n";
    echo "Start the bot with: php cli/start_bot.php\n";
} else {
    cli_error('npm install failed (exit code: ' . $exitcode . ')');
}
