<?php
/**
 * logout.php - Log out user
 *
 * Revokes Twitch token and clears session.
 */

require_once __DIR__ . '/../includes/twitch_oauth.php';

logout();

// Redirect to home or return URL
$returnTo = $_GET['return'] ?? '/';

// Validate return URL
if (!preg_match('#^/[^/]#', $returnTo)) {
  $returnTo = '/';
}

header('Location: ' . $returnTo);
exit;
