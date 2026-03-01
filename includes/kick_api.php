<?php
/**
 * kick_api.php - Kick API helper for clip fetching (EXPERIMENTAL)
 *
 * Uses Kick's unofficial v2 API endpoints (no auth required).
 * These endpoints are NOT officially documented and may break at any time.
 */

class KickAPI {
  private $baseUrl = 'https://kick.com/api/v2';
  private $userAgent = 'ClipArchive/1.0';

  /**
   * Check if API is available (always true - no credentials needed)
   */
  public function isConfigured(): bool {
    return true;
  }

  /**
   * Make an API request to Kick
   */
  private function apiRequest(string $url): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER => [
        'Accept: application/json',
        'User-Agent: ' . $this->userAgent,
      ],
      CURLOPT_TIMEOUT => 15,
      CURLOPT_FOLLOWLOCATION => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
      error_log("Kick API curl error: $error for $url");
      return null;
    }

    if ($httpCode !== 200) {
      error_log("Kick API error: HTTP $httpCode for $url");
      return null;
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
      error_log("Kick API error: Invalid JSON response for $url");
      return null;
    }

    return $data;
  }

  /**
   * Get channel info by username (slug)
   */
  public function getChannelInfo(string $username): ?array {
    $username = strtolower(trim($username));
    $url = "{$this->baseUrl}/channels/{$username}";

    $data = $this->apiRequest($url);
    if (!$data) return null;

    return [
      'id' => $data['id'] ?? null,
      'slug' => $data['slug'] ?? $username,
      'username' => $data['slug'] ?? $username,
      'display_name' => $data['user']['username'] ?? $data['slug'] ?? $username,
      'profile_image_url' => $data['user']['profile_pic'] ?? '',
      'verified' => $data['verified'] ?? false,
      'followers_count' => $data['followers_count'] ?? 0,
    ];
  }

  /**
   * Fetch clips for a channel
   *
   * @param string $username Kick channel slug
   * @param string $sort Sort order: 'view' (most viewed) or 'recent'
   * @param int $page Page number (1-based)
   * @return array ['clips' => [...], 'has_more' => bool]
   */
  public function getClips(string $username, string $sort = 'view', int $page = 1): array {
    $username = strtolower(trim($username));
    $url = "{$this->baseUrl}/channels/{$username}/clips?sort={$sort}&time=all&page={$page}";

    $data = $this->apiRequest($url);
    if (!$data) {
      return ['clips' => [], 'has_more' => false];
    }

    // Handle both array and object response formats
    $rawClips = [];
    $hasMore = false;

    if (isset($data['clips']) && is_array($data['clips'])) {
      // Object format: { clips: { data: [...], ... } } or { clips: [...] }
      if (isset($data['clips']['data'])) {
        $rawClips = $data['clips']['data'];
        $hasMore = !empty($data['clips']['next_page_url'] ?? $data['clips']['next_cursor']);
      } else {
        $rawClips = $data['clips'];
      }
    } elseif (isset($data['data']) && is_array($data['data'])) {
      // Paginated format: { data: [...], next_page_url: ... }
      $rawClips = $data['data'];
      $hasMore = !empty($data['next_page_url'] ?? $data['next_cursor']);
    } elseif (is_array($data) && !empty($data) && isset($data[0])) {
      // Direct array format
      $rawClips = $data;
    }

    $clips = [];
    foreach ($rawClips as $clip) {
      $mapped = $this->mapClipData($clip, $username);
      if ($mapped) {
        $clips[] = $mapped;
      }
    }

    return [
      'clips' => $clips,
      'has_more' => $hasMore || count($rawClips) >= 20,
    ];
  }

  /**
   * Fetch all clips for a channel (paginated)
   *
   * @param string $username Kick channel slug
   * @param int $limit Max clips to fetch
   * @param string $sort Sort order
   * @return array Array of clips
   */
  public function getAllClips(string $username, int $limit = 500, string $sort = 'view'): array {
    $allClips = [];
    $page = 1;
    $maxPages = 50; // Safety limit

    while (count($allClips) < $limit && $page <= $maxPages) {
      $result = $this->getClips($username, $sort, $page);

      if (empty($result['clips'])) {
        break;
      }

      $allClips = array_merge($allClips, $result['clips']);

      if (!$result['has_more']) {
        break;
      }

      $page++;
      usleep(200000); // 200ms delay between pages
    }

    return array_slice($allClips, 0, $limit);
  }

  /**
   * Get single clip info
   */
  public function getClipInfo(string $clipId): ?array {
    $url = "{$this->baseUrl}/clips/{$clipId}";
    $data = $this->apiRequest($url);
    if (!$data) return null;

    return $this->mapClipData($data['clip'] ?? $data, '');
  }

  /**
   * Map Kick clip data to our standard format
   */
  private function mapClipData(array $clip, string $channelSlug): ?array {
    $clipId = $clip['id'] ?? null;
    if (!$clipId) return null;

    // Extract video URL - Kick uses various field names
    $videoUrl = $clip['clip_url'] ?? $clip['video_url'] ?? $clip['stream'] ?? $clip['url'] ?? null;

    // Extract thumbnail
    $thumbnail = '';
    if (isset($clip['thumbnail']) && is_array($clip['thumbnail'])) {
      $thumbnail = $clip['thumbnail']['src'] ?? $clip['thumbnail']['url'] ?? '';
    } elseif (isset($clip['thumbnail']) && is_string($clip['thumbnail'])) {
      $thumbnail = $clip['thumbnail'];
    } elseif (isset($clip['thumbnail_url'])) {
      $thumbnail = $clip['thumbnail_url'];
    }

    // Extract creator
    $creatorName = '';
    if (isset($clip['creator']) && is_array($clip['creator'])) {
      $creatorName = $clip['creator']['username'] ?? $clip['creator']['slug'] ?? '';
    } elseif (isset($clip['creator_name'])) {
      $creatorName = $clip['creator_name'];
    }

    // Extract category/game
    $gameName = '';
    $gameId = '';
    if (isset($clip['category']) && is_array($clip['category'])) {
      $gameName = $clip['category']['name'] ?? '';
      $gameId = 'kick_' . ($clip['category']['id'] ?? '');
    }

    return [
      'clip_id' => (string)$clipId,
      'title' => $clip['title'] ?? 'Untitled Clip',
      'duration' => (int)($clip['duration'] ?? 0),
      'created_at' => $clip['created_at'] ?? null,
      'view_count' => (int)($clip['views'] ?? $clip['view_count'] ?? 0),
      'thumbnail_url' => $thumbnail,
      'creator_name' => $creatorName,
      'game_id' => $gameId,
      'game_name' => $gameName,
      'video_id' => '',
      'vod_offset' => null,
      'mp4_url' => $videoUrl,
      'url' => "https://kick.com/{$channelSlug}?clip={$clipId}",
      'platform' => 'kick',
    ];
  }
}
