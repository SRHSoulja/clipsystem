<?php
/**
 * twitch_oauth.php - Twitch OAuth authentication helper
 *
 * Handles user authentication via Twitch OAuth for:
 * - Viewer voting on clips
 * - Streamer dashboard access (channel owners)
 * - Mod authorization (verified via Twitch API)
 */

class TwitchOAuth {
  private $clientId;
  private $clientSecret;
  private $redirectUri;

  // OAuth scopes we request
  const SCOPES = 'user:read:email';

  public function __construct() {
    $this->clientId = getenv('TWITCH_CLIENT_ID') ?: '';
    $this->clientSecret = getenv('TWITCH_CLIENT_SECRET') ?: '';

    // Build redirect URI from environment or auto-detect
    $baseUrl = getenv('API_BASE_URL') ?: '';
    if (!$baseUrl) {
      $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
      $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
      $baseUrl = "{$protocol}://{$host}";
    }
    $this->redirectUri = rtrim($baseUrl, '/') . '/auth/callback.php';
  }

  /**
   * Check if OAuth is configured
   */
  public function isConfigured(): bool {
    return !empty($this->clientId) && !empty($this->clientSecret);
  }

  /**
   * Get the authorization URL to redirect users to
   */
  public function getAuthUrl(?string $returnTo = null): string {
    // Generate state token for CSRF protection
    $state = bin2hex(random_bytes(16));
    if ($returnTo) {
      $state .= '|' . base64_encode($returnTo);
    }

    // Store state in session for verification
    initSession();
    $_SESSION['oauth_state'] = explode('|', $state)[0];

    // Debug logging
    error_log("OAuth login - Session ID: " . session_id());
    error_log("OAuth login - State stored: " . $_SESSION['oauth_state']);

    $params = [
      'client_id' => $this->clientId,
      'redirect_uri' => $this->redirectUri,
      'response_type' => 'code',
      'scope' => self::SCOPES,
      'state' => $state,
      'force_verify' => 'true', // Always show auth screen so users can switch accounts
    ];

    return 'https://id.twitch.tv/oauth2/authorize?' . http_build_query($params);
  }

  /**
   * Exchange authorization code for access token
   */
  public function getAccessToken(string $code): ?array {
    $url = 'https://id.twitch.tv/oauth2/token';
    $data = [
      'client_id' => $this->clientId,
      'client_secret' => $this->clientSecret,
      'code' => $code,
      'grant_type' => 'authorization_code',
      'redirect_uri' => $this->redirectUri,
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_POST => true,
      CURLOPT_POSTFIELDS => http_build_query($data),
      CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
      error_log("Twitch OAuth token error: HTTP $httpCode - $response");
      return null;
    }

    $json = json_decode($response, true);
    if (!$json || !isset($json['access_token'])) {
      error_log("Twitch OAuth token error: Invalid response");
      return null;
    }

    return $json;
  }

  /**
   * Get user info from access token
   */
  public function getUserInfo(string $accessToken): ?array {
    $url = 'https://api.twitch.tv/helix/users';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER => [
        "Authorization: Bearer $accessToken",
        "Client-Id: {$this->clientId}",
      ],
      CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
      error_log("Twitch user info error: HTTP $httpCode");
      return null;
    }

    $json = json_decode($response, true);
    if (!$json || empty($json['data'])) {
      return null;
    }

    return $json['data'][0];
  }

  /**
   * Check if a user is a mod for a channel
   */
  public function isModForChannel(string $accessToken, string $userId, string $broadcasterId): bool {
    // User is always "mod" of their own channel
    if ($userId === $broadcasterId) {
      return true;
    }

    $url = "https://api.twitch.tv/helix/moderation/moderators?broadcaster_id={$broadcasterId}&user_id={$userId}";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER => [
        "Authorization: Bearer $accessToken",
        "Client-Id: {$this->clientId}",
      ],
      CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
      // API might fail if user doesn't have permission to check mods
      // Fall back to database check
      return false;
    }

    $json = json_decode($response, true);
    return !empty($json['data']);
  }

  /**
   * Validate an access token
   */
  public function validateToken(string $accessToken): ?array {
    $url = 'https://id.twitch.tv/oauth2/validate';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER => [
        "Authorization: OAuth $accessToken",
      ],
      CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
      return null;
    }

    return json_decode($response, true);
  }

  /**
   * Revoke an access token (logout)
   */
  public function revokeToken(string $accessToken): bool {
    $url = 'https://id.twitch.tv/oauth2/revoke';
    $data = [
      'client_id' => $this->clientId,
      'token' => $accessToken,
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_POST => true,
      CURLOPT_POSTFIELDS => http_build_query($data),
      CURLOPT_TIMEOUT => 10,
    ]);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $httpCode === 200;
  }

  /**
   * Get broadcaster ID from username (using app token)
   */
  public function getBroadcasterId(string $username): ?string {
    // Use the TwitchAPI class for this
    require_once __DIR__ . '/twitch_api.php';
    $api = new TwitchAPI();
    return $api->getBroadcasterId($username);
  }
}

/**
 * Session helper functions
 */

function initSession() {
  // Handle case where session was closed by session_write_close()
  if (session_status() === PHP_SESSION_NONE) {
    // Set secure session settings
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

    // Get domain from host, stripping port if present
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $domain = preg_replace('/:\d+$/', '', $host);

    // Don't set domain for localhost
    $isLocalhost = (strpos($domain, 'localhost') !== false || strpos($domain, '127.0.0.1') !== false);

    $params = [
      'lifetime' => 86400 * 7, // 7 days
      'path' => '/',
      'secure' => $secure,
      'httponly' => true,
      'samesite' => 'Lax',
    ];

    // Only add domain if not localhost (helps with subdomain issues)
    if (!$isLocalhost) {
      $params['domain'] = $domain;
    }

    session_set_cookie_params($params);
    session_start();
  }
}

function getCurrentUser(): ?array {
  initSession();

  if (empty($_SESSION['twitch_user'])) {
    return null;
  }

  // Check if token is still valid (cached check)
  // Only logout if token is WELL past expiry (add 1 hour grace period)
  if (isset($_SESSION['token_expires']) && $_SESSION['token_expires'] < (time() - 3600)) {
    // Token expired over an hour ago, clear session
    error_log("Session logout: Token expired over an hour ago for " . ($_SESSION['twitch_user']['login'] ?? 'unknown'));
    logout();
    return null;
  }

  // Periodically validate token with Twitch (every 30 minutes instead of 15)
  // Only if the token hasn't expired yet (avoid unnecessary API calls)
  $lastValidated = $_SESSION['last_validated'] ?? 0;
  $tokenExpired = isset($_SESSION['token_expires']) && $_SESSION['token_expires'] < time();

  if (!$tokenExpired && time() - $lastValidated > 1800 && !empty($_SESSION['access_token'])) {
    $oauth = new TwitchOAuth();
    $validation = $oauth->validateToken($_SESSION['access_token']);

    if ($validation === null) {
      // Validation FAILED (network error, timeout, etc.)
      // Don't logout immediately - could be temporary issue
      // Just log it and let the session continue
      error_log("Token validation failed (network/timeout?) for " . ($_SESSION['twitch_user']['login'] ?? 'unknown') . " - keeping session");
      // Don't update last_validated so we'll retry soon
    } elseif ($validation === false || (is_array($validation) && empty($validation['login']))) {
      // Token was explicitly rejected by Twitch (revoked or invalid)
      error_log("Session logout: Token explicitly rejected by Twitch for " . ($_SESSION['twitch_user']['login'] ?? 'unknown'));
      logout();
      return null;
    } else {
      // Validation succeeded
      $_SESSION['last_validated'] = time();
    }
  }

  return $_SESSION['twitch_user'];
}

function isLoggedIn(): bool {
  return getCurrentUser() !== null;
}

function login(array $userInfo, string $accessToken, int $expiresIn) {
  initSession();

  // Regenerate session ID to prevent session fixation attacks
  // This also sends the new session cookie to the browser
  session_regenerate_id(true);

  $_SESSION['twitch_user'] = [
    'id' => $userInfo['id'],
    'login' => strtolower($userInfo['login']),
    'display_name' => $userInfo['display_name'],
    'profile_image_url' => $userInfo['profile_image_url'] ?? '',
  ];
  $_SESSION['access_token'] = $accessToken;
  $_SESSION['token_expires'] = time() + $expiresIn - 60; // 1 min buffer
  $_SESSION['last_validated'] = time(); // Track when we last validated with Twitch

  // Session is automatically written when script ends or header() redirect happens
  // No need for session_write_close() here - it can cause issues with cookie delivery
}

function logout() {
  initSession();

  // Revoke token if we have one
  if (!empty($_SESSION['access_token'])) {
    $oauth = new TwitchOAuth();
    $oauth->revokeToken($_SESSION['access_token']);
  }

  // Clear session
  $_SESSION = [];
  session_destroy();
}

function getAccessToken(): ?string {
  initSession();
  return $_SESSION['access_token'] ?? null;
}

/**
 * Check if the current user is a super admin
 * Super admins can access any streamer's dashboard
 */
function isSuperAdmin(): bool {
  $user = getCurrentUser();
  if (!$user) return false;

  $superAdmins = ['thearsondragon', 'cliparchive'];
  return in_array(strtolower($user['login']), $superAdmins);
}

/**
 * Check if the current user is authorized to access a specific channel
 * Returns true if:
 * - User is a super admin (thearsondragon, cliparchive)
 * - User is accessing their own channel
 */
function isAuthorizedForChannel(string $targetChannel): bool {
  $user = getCurrentUser();
  if (!$user) return false;

  // Super admins can access any channel
  if (isSuperAdmin()) return true;

  // Users can access their own channel
  return strtolower($user['login']) === strtolower($targetChannel);
}
