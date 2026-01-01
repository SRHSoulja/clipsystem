<?php
/**
 * callback.php - Twitch OAuth callback handler
 *
 * Handles the redirect from Twitch after user authorizes.
 * Exchanges code for token, fetches user info, creates session.
 */

require_once __DIR__ . '/../includes/twitch_oauth.php';
require_once __DIR__ . '/../db_config.php';

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

// Debug logging for CSRF issues
error_log("OAuth callback - Session ID: " . session_id());
error_log("OAuth callback - State from URL: " . $stateToken);
error_log("OAuth callback - State from session: " . ($_SESSION['oauth_state'] ?? 'NOT SET'));

if (empty($_SESSION['oauth_state']) || $stateToken !== $_SESSION['oauth_state']) {
  // Provide more debug info
  error_log("OAuth CSRF failure - URL state: $stateToken, Session state: " . ($_SESSION['oauth_state'] ?? 'empty'));
  die('Invalid state token - possible CSRF attack. Session may have expired. Please try again.');
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

// Save profile image to database for archived streamers
// This allows displaying streamer avatars on browse/search pages
$pdo = get_db_connection();
if ($pdo && !empty($userInfo['profile_image_url'])) {
  $userLogin = strtolower($userInfo['login']);
  try {
    // Check if this user is an archived streamer (has clips)
    $stmt = $pdo->prepare("SELECT 1 FROM clips WHERE login = ? LIMIT 1");
    $stmt->execute([$userLogin]);
    if ($stmt->fetch()) {
      // Update their profile image in channel_settings
      $stmt = $pdo->prepare("
        INSERT INTO channel_settings (login, profile_image_url, profile_image_updated_at)
        VALUES (?, ?, NOW())
        ON CONFLICT (login) DO UPDATE SET
          profile_image_url = EXCLUDED.profile_image_url,
          profile_image_updated_at = EXCLUDED.profile_image_updated_at
      ");
      $stmt->execute([$userLogin, $userInfo['profile_image_url']]);
    }
  } catch (PDOException $e) {
    // Non-critical - just log and continue
    error_log("Failed to save profile image for $userLogin: " . $e->getMessage());
  }
}

// Validate return URL (must be relative or same domain)
if (!preg_match('#^/[^/]#', $returnTo) && !preg_match('#^https?://' . preg_quote($_SERVER['HTTP_HOST']) . '#', $returnTo)) {
  $returnTo = '/';
}

// Redirect to return URL
header('Location: ' . $returnTo);
exit;
