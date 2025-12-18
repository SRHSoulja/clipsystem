<?php
/**
 * bot_launcher.php - Launches the Node.js bot if not already running
 *
 * This is called by index.php on startup to ensure the bot is running.
 */

$pidFile = '/tmp/clipsystem_bot.pid';
$logFile = '/tmp/clipsystem_bot.log';

// Check if bot is already running
if (file_exists($pidFile)) {
    $pid = trim(file_get_contents($pidFile));
    // Check if process is still running
    if ($pid && file_exists("/proc/$pid")) {
        return; // Bot is already running
    }
}

// Launch bot in background
$cmd = "cd " . escapeshellarg(__DIR__) . " && node bot.js >> $logFile 2>&1 & echo $!";
$pid = trim(shell_exec($cmd));

if ($pid) {
    file_put_contents($pidFile, $pid);
    error_log("Bot launched with PID: $pid");
}
