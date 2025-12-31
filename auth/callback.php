<?php
/**
 * callback.php - Twitch OAuth callback handler
 *
 * Handles the redirect from Twitch after user authorizes.
 * Exchanges code for token, fetches user info, creates session.
 */

require_once __DIR__ . '/../includes/twitch_oauth.php';

initSession();

$oauth = new TwitchOAuth();

// Check for errors from Twitch
if (isset($_GET['error'])) {
  $error = htmlspecialchars($_GET['error']);
  $desc = htmlspecialchars($_GET['error_description'] ?? 'Unknown error');
  die("Authorization failed: $error - $desc");
}

// Verify we have a code
if (empty($_GET['code'])) {
  die('Missing authorization code');
}

// Verify state to prevent CSRF
$state = $_GET['state'] ?? '';
$stateParts = explode('|', $state);
$stateToken = $stateParts[0];
$returnTo = isset($stateParts[1]) ? base64_decode($stateParts[1]) : '/';

if (empty($_SESSION['oauth_state']) || $stateToken !== $_SESSION['oauth_state']) {
  die('Invalid state token - possible CSRF attack');
}
unset($_SESSION['oauth_state']);

// Exchange code for access token
$tokenData = $oauth->getAccessToken($_GET['code']);
if (!$tokenData) {
  die('Failed to get access token');
}

$accessToken = $tokenData['access_token'];
$expiresIn = $tokenData['expires_in'] ?? 3600;

// Get user info
$userInfo = $oauth->getUserInfo($accessToken);
if (!$userInfo) {
  die('Failed to get user info');
}

// Create session
login($userInfo, $accessToken, $expiresIn);

// Validate return URL (must be relative or same domain)
if (!preg_match('#^/[^/]#', $returnTo) && !preg_match('#^https?://' . preg_quote($_SERVER['HTTP_HOST']) . '#', $returnTo)) {
  $returnTo = '/';
}

// Redirect to return URL
header('Location: ' . $returnTo);
exit;
