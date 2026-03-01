<?php
/**
 * discord/config.php - Serves Discord client ID as JavaScript
 *
 * Outputs window.DISCORD_CLIENT_ID from environment variable
 * so the Activity HTML doesn't need manual editing.
 */
header('Content-Type: application/javascript');
header('Cache-Control: public, max-age=3600');
$clientId = getenv('DISCORD_CLIENT_ID') ?: '';
echo "window.DISCORD_CLIENT_ID = " . json_encode($clientId) . ";\n";
