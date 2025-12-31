<?php
/**
 * twitch_api.php - Twitch API helper for live clip fetching
 *
 * Provides functions to fetch clips directly from Twitch API
 * for streamers that haven't been archived yet.
 */

class TwitchAPI {
  private $clientId;
  private $clientSecret;
  private $accessToken;
  private $tokenExpires;

  private static $tokenCacheFile = null;

  public function __construct() {
    $this->clientId = getenv('TWITCH_CLIENT_ID') ?: '';
    $this->clientSecret = getenv('TWITCH_CLIENT_SECRET') ?: '';

    // Set up token cache file path
    $cacheDir = is_writable("/tmp") ? "/tmp/clipsystem_cache" : __DIR__ . "/../cache";
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0777, true);
    self::$tokenCacheFile = $cacheDir . "/twitch_token.json";
  }

  /**
   * Check if API credentials are configured
   */
  public function isConfigured(): bool {
    return !empty($this->clientId) && !empty($this->clientSecret);
  }

  /**
   * Get OAuth access token (cached)
   */
  private function getAccessToken(): ?string {
    // Check memory cache
    if ($this->accessToken && $this->tokenExpires > time()) {
      return $this->accessToken;
    }

    // Check file cache
    if (self::$tokenCacheFile && file_exists(self::$tokenCacheFile)) {
      $cached = json_decode(file_get_contents(self::$tokenCacheFile), true);
      if ($cached && isset($cached['token']) && isset($cached['expires']) && $cached['expires'] > time()) {
        $this->accessToken = $cached['token'];
        $this->tokenExpires = $cached['expires'];
        return $this->accessToken;
      }
    }

    // Fetch new token
    $url = "https://id.twitch.tv/oauth2/token";
    $data = [
      'client_id' => $this->clientId,
      'client_secret' => $this->clientSecret,
      'grant_type' => 'client_credentials'
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
      error_log("Twitch OAuth error: HTTP $httpCode - $response");
      return null;
    }

    $json = json_decode($response, true);
    if (!$json || !isset($json['access_token'])) {
      error_log("Twitch OAuth error: Invalid response");
      return null;
    }

    $this->accessToken = $json['access_token'];
    $this->tokenExpires = time() + ($json['expires_in'] ?? 3600) - 60; // 1 min buffer

    // Cache to file
    if (self::$tokenCacheFile) {
      file_put_contents(self::$tokenCacheFile, json_encode([
        'token' => $this->accessToken,
        'expires' => $this->tokenExpires
      ]));
    }

    return $this->accessToken;
  }

  /**
   * Make authenticated API request
   */
  private function apiRequest(string $url): ?array {
    $token = $this->getAccessToken();
    if (!$token) return null;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER => [
        "Authorization: Bearer $token",
        "Client-Id: {$this->clientId}"
      ],
      CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
      error_log("Twitch API error: HTTP $httpCode for $url");
      return null;
    }

    return json_decode($response, true);
  }

  /**
   * Get broadcaster ID from username
   */
  public function getBroadcasterId(string $username): ?string {
    $username = strtolower(trim($username));
    $url = "https://api.twitch.tv/helix/users?login=" . urlencode($username);

    $data = $this->apiRequest($url);
    if (!$data || empty($data['data'])) {
      return null;
    }

    return $data['data'][0]['id'] ?? null;
  }

  /**
   * Get game ID from game name
   */
  public function getGameId(string $gameName): ?string {
    $url = "https://api.twitch.tv/helix/games?name=" . urlencode($gameName);

    $data = $this->apiRequest($url);
    if (!$data || empty($data['data'])) {
      return null;
    }

    return $data['data'][0]['id'] ?? null;
  }

  /**
   * Search games by name
   */
  public function searchGames(string $query, int $limit = 20): array {
    $url = "https://api.twitch.tv/helix/search/categories?query=" . urlencode($query) . "&first=$limit";

    $data = $this->apiRequest($url);
    if (!$data || empty($data['data'])) {
      return [];
    }

    return $data['data'];
  }

  /**
   * Fetch clips for a broadcaster
   *
   * @param string $broadcasterId Twitch broadcaster ID
   * @param int $limit Max clips to fetch (up to 100 per request, will paginate)
   * @param string|null $gameId Filter by game ID
   * @param string|null $cursor Pagination cursor
   * @param string|null $startedAt ISO 8601 date - clips created after this
   * @param string|null $endedAt ISO 8601 date - clips created before this
   * @return array ['clips' => [...], 'cursor' => '...']
   */
  public function getClips(string $broadcasterId, int $limit = 100, ?string $gameId = null, ?string $cursor = null, ?string $startedAt = null, ?string $endedAt = null): array {
    $params = [
      'broadcaster_id' => $broadcasterId,
      'first' => min($limit, 100)
    ];

    if ($gameId) {
      $params['game_id'] = $gameId;
    }

    if ($cursor) {
      $params['after'] = $cursor;
    }

    if ($startedAt) {
      $params['started_at'] = $startedAt;
    }

    if ($endedAt) {
      $params['ended_at'] = $endedAt;
    }

    $url = "https://api.twitch.tv/helix/clips?" . http_build_query($params);

    $data = $this->apiRequest($url);
    if (!$data) {
      return ['clips' => [], 'cursor' => null];
    }

    $clips = [];
    foreach ($data['data'] ?? [] as $clip) {
      $clips[] = [
        'clip_id' => $clip['id'],
        'title' => $clip['title'],
        'view_count' => $clip['view_count'],
        'created_at' => $clip['created_at'],
        'duration' => $clip['duration'],
        'thumbnail_url' => $clip['thumbnail_url'],
        'creator_name' => $clip['creator_name'] ?? '',
        'game_id' => $clip['game_id'] ?? '',
        'url' => $clip['url'],
      ];
    }

    $nextCursor = $data['pagination']['cursor'] ?? null;

    return [
      'clips' => $clips,
      'cursor' => $nextCursor
    ];
  }

  /**
   * Fetch all clips for a broadcaster (paginated, up to limit)
   *
   * @param string $broadcasterId Twitch broadcaster ID
   * @param int $limit Max clips to fetch
   * @param string|null $gameId Filter by game ID
   * @param string|null $startedAt ISO 8601 date - clips created after this
   * @param string|null $endedAt ISO 8601 date - clips created before this
   * @return array Array of clips
   */
  public function getAllClips(string $broadcasterId, int $limit = 500, ?string $gameId = null, ?string $startedAt = null, ?string $endedAt = null): array {
    $allClips = [];
    $cursor = null;
    $fetched = 0;

    while ($fetched < $limit) {
      $batchSize = min(100, $limit - $fetched);
      $result = $this->getClips($broadcasterId, $batchSize, $gameId, $cursor, $startedAt, $endedAt);

      if (empty($result['clips'])) {
        break;
      }

      $allClips = array_merge($allClips, $result['clips']);
      $fetched += count($result['clips']);
      $cursor = $result['cursor'];

      if (!$cursor) {
        break;
      }

      // Small delay to be nice to API
      usleep(100000); // 100ms
    }

    return $allClips;
  }

  /**
   * Get game information by IDs (up to 100 at a time)
   */
  public function getGamesByIds(array $gameIds): array {
    if (empty($gameIds)) {
      return [];
    }

    // Twitch API allows up to 100 game IDs per request
    $gameIds = array_slice(array_unique($gameIds), 0, 100);
    $params = array_map(function($id) { return 'id=' . urlencode($id); }, $gameIds);
    $url = "https://api.twitch.tv/helix/games?" . implode('&', $params);

    $data = $this->apiRequest($url);
    if (!$data || empty($data['data'])) {
      return [];
    }

    $results = [];
    foreach ($data['data'] as $game) {
      $results[$game['id']] = [
        'game_id' => $game['id'],
        'name' => $game['name'],
        'box_art_url' => $game['box_art_url'] ?? '',
      ];
    }

    return $results;
  }

  /**
   * Get clips for a streamer by username
   *
   * @param string $username Twitch username
   * @param int $limit Max clips to fetch
   * @param string|null $gameName Filter by game name
   * @param string|null $dateRange Date range: 'week', 'month', '3months', '6months', 'year', '2years', '3years', 'all'
   * @return array ['broadcaster_id' => ..., 'clips' => [...], 'count' => ...]
   */
  public function getClipsForStreamer(string $username, int $limit = 500, ?string $gameName = null, ?string $dateRange = 'year'): array {
    $broadcasterId = $this->getBroadcasterId($username);
    if (!$broadcasterId) {
      return ['error' => 'Streamer not found', 'clips' => []];
    }

    $gameId = null;
    if ($gameName) {
      $gameId = $this->getGameId($gameName);
    }

    // Calculate date range
    $startedAt = null;
    $endedAt = null;
    $now = new DateTime('now', new DateTimeZone('UTC'));

    switch ($dateRange) {
      case 'week':
        $startedAt = (clone $now)->modify('-1 week')->format('Y-m-d\TH:i:s\Z');
        break;
      case 'month':
        $startedAt = (clone $now)->modify('-1 month')->format('Y-m-d\TH:i:s\Z');
        break;
      case '3months':
        $startedAt = (clone $now)->modify('-3 months')->format('Y-m-d\TH:i:s\Z');
        break;
      case '6months':
        $startedAt = (clone $now)->modify('-6 months')->format('Y-m-d\TH:i:s\Z');
        break;
      case 'year':
        $startedAt = (clone $now)->modify('-1 year')->format('Y-m-d\TH:i:s\Z');
        break;
      case '2years':
        $startedAt = (clone $now)->modify('-2 years')->format('Y-m-d\TH:i:s\Z');
        break;
      case '3years':
        $startedAt = (clone $now)->modify('-3 years')->format('Y-m-d\TH:i:s\Z');
        break;
      case 'all':
      default:
        // No date filter - get all available clips
        $startedAt = null;
        break;
    }

    $clips = $this->getAllClips($broadcasterId, $limit, $gameId, $startedAt, $endedAt);

    return [
      'broadcaster_id' => $broadcasterId,
      'clips' => $clips,
      'count' => count($clips),
      'date_range' => $dateRange,
      'started_at' => $startedAt
    ];
  }

  /**
   * Get clips with pagination cursor support (for AJAX deep search)
   *
   * @param string $username Twitch username
   * @param string|null $cursor Pagination cursor to continue from
   * @param int $batchSize Number of clips per batch
   * @param string|null $dateRange Date range filter
   * @return array ['clips' => [...], 'cursor' => ..., 'broadcaster_id' => ...]
   */
  public function getClipsBatch(string $username, ?string $cursor = null, int $batchSize = 100, ?string $dateRange = 'year'): array {
    $broadcasterId = $this->getBroadcasterId($username);
    if (!$broadcasterId) {
      return ['error' => 'Streamer not found', 'clips' => [], 'cursor' => null];
    }

    // Calculate date range
    $startedAt = null;
    $now = new DateTime('now', new DateTimeZone('UTC'));

    switch ($dateRange) {
      case 'week':
        $startedAt = (clone $now)->modify('-1 week')->format('Y-m-d\TH:i:s\Z');
        break;
      case 'month':
        $startedAt = (clone $now)->modify('-1 month')->format('Y-m-d\TH:i:s\Z');
        break;
      case '3months':
        $startedAt = (clone $now)->modify('-3 months')->format('Y-m-d\TH:i:s\Z');
        break;
      case '6months':
        $startedAt = (clone $now)->modify('-6 months')->format('Y-m-d\TH:i:s\Z');
        break;
      case 'year':
        $startedAt = (clone $now)->modify('-1 year')->format('Y-m-d\TH:i:s\Z');
        break;
      case '2years':
        $startedAt = (clone $now)->modify('-2 years')->format('Y-m-d\TH:i:s\Z');
        break;
      case '3years':
        $startedAt = (clone $now)->modify('-3 years')->format('Y-m-d\TH:i:s\Z');
        break;
      case 'all':
      default:
        $startedAt = null;
        break;
    }

    $result = $this->getClips($broadcasterId, $batchSize, null, $cursor, $startedAt, null);

    return [
      'broadcaster_id' => $broadcasterId,
      'clips' => $result['clips'],
      'cursor' => $result['cursor'],
      'has_more' => !empty($result['cursor'])
    ];
  }
}
