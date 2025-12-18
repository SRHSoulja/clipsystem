<?php
/**
 * bot_launcher.php - Launches the Node.js bot if not already running
 *
 * This is called by index.php on startup to ensure the bot is running.
 */

$pidFile = '/tmp/clipsystem_bot.pid';
$logFile = '/tmp/clipsystem_bot.log';
$installedFlag = '/tmp/clipsystem_npm_installed';

// Check if bot is already running
if (file_exists($pidFile)) {
    $pid = trim(file_get_contents($pidFile));
    // Check if process is still running
    if ($pid && file_exists("/proc/$pid")) {
        return; // Bot is already running
    }
}

// Check if node is available
$nodeCheck = trim(shell_exec('which node 2>/dev/null'));
if (!$nodeCheck) {
    error_log("Bot launcher: Node.js not found");
    return;
}

$dir = __DIR__;

// Install npm dependencies if not done yet
if (!file_exists($installedFlag)) {
    // Restore package.json from .bot backup
    if (file_exists("$dir/package.json.bot") && !file_exists("$dir/package.json")) {
        copy("$dir/package.json.bot", "$dir/package.json");
    }

    if (file_exists("$dir/package.json")) {
        shell_exec("cd " . escapeshellarg($dir) . " && npm install --production 2>&1");
        file_put_contents($installedFlag, date('c'));
        error_log("Bot launcher: npm install complete");
    }
}

// Launch bot in background
$cmd = "cd " . escapeshellarg($dir) . " && node bot.js >> $logFile 2>&1 & echo $!";
$pid = trim(shell_exec($cmd));

if ($pid) {
    file_put_contents($pidFile, $pid);
    error_log("Bot launched with PID: $pid");
}
