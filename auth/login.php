<?php
/**
 * login.php - Initiate Twitch OAuth login
 *
 * Redirects user to Twitch authorization page.
 * Optional ?return= parameter to redirect back after login.
 */

require_once __DIR__ . '/../includes/twitch_oauth.php';

// Initialize session with proper settings BEFORE generating state
initSession();

$oauth = new TwitchOAuth();

if (!$oauth->isConfigured()) {
  http_response_code(500);
  die('Twitch OAuth not configured');
}

// Get return URL (where to redirect after login)
$returnTo = $_GET['return'] ?? '/';

// Redirect to Twitch authorization
header('Location: ' . $oauth->getAuthUrl($returnTo));
exit;
