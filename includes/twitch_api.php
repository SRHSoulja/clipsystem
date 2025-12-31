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
   * @return array ['clips' => [...], 'cursor' => '...']
   */
  public function getClips(string $broadcasterId, int $limit = 100, ?string $gameId = null, ?string $cursor = null): array {
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
   */
  public function getAllClips(string $broadcasterId, int $limit = 500, ?string $gameId = null): array {
    $allClips = [];
    $cursor = null;
    $fetched = 0;

    while ($fetched < $limit) {
      $batchSize = min(100, $limit - $fetched);
      $result = $this->getClips($broadcasterId, $batchSize, $gameId, $cursor);

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
   */
  public function getClipsForStreamer(string $username, int $limit = 500, ?string $gameName = null): array {
    $broadcasterId = $this->getBroadcasterId($username);
    if (!$broadcasterId) {
      return ['error' => 'Streamer not found', 'clips' => []];
    }

    $gameId = null;
    if ($gameName) {
      $gameId = $this->getGameId($gameName);
    }

    $clips = $this->getAllClips($broadcasterId, $limit, $gameId);

    return [
      'broadcaster_id' => $broadcasterId,
      'clips' => $clips,
      'count' => count($clips)
    ];
  }
}
