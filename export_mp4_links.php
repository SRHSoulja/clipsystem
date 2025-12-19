<?php
/**
 * export_mp4_links.php - Export all clip MP4 download links
 *
 * Twitch thumbnail URLs can be converted to MP4 URLs by changing the pattern:
 *   Thumbnail: https://clips-media-assets2.twitch.tv/AT-cm%7C123456-preview-480x272.jpg
 *   MP4:       https://clips-media-assets2.twitch.tv/AT-cm%7C123456.mp4
 *
 * Usage:
 *   export_mp4_links.php?login=floppyjimmie&key=YOUR_KEY
 *   export_mp4_links.php?login=floppyjimmie&key=YOUR_KEY&format=txt  (plain text list)
 *   export_mp4_links.php?login=floppyjimmie&key=YOUR_KEY&format=csv  (CSV with metadata)
 *   export_mp4_links.php?login=floppyjimmie&key=YOUR_KEY&format=json (JSON array)
 *
 * Note: This requires thumbnails to be populated first. Run populate_thumbnails.php if needed.
 */

set_time_limit(120);

// Load env
$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
  foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (strpos(trim($line), '#') === 0) continue;
    if (strpos($line, '=') === false) continue;
    list($k, $v) = explode('=', $line, 2);
    putenv(trim($k) . '=' . trim($v));
  }
}

require_once __DIR__ . '/db_config.php';

// Auth check
$ADMIN_KEY = getenv('ADMIN_KEY') ?: '';
$key = $_GET['key'] ?? '';
if ($key !== $ADMIN_KEY) {
    http_response_code(403);
    header("Content-Type: text/plain");
    echo "Forbidden. Use ?key=YOUR_ADMIN_KEY";
    exit;
}

$login = strtolower(preg_replace('/[^a-z0-9_]/', '', $_GET['login'] ?? 'floppyjimmie'));
$format = strtolower($_GET['format'] ?? 'json');

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    header("Content-Type: text/plain");
    echo "Database unavailable";
    exit;
}

/**
 * Convert Twitch thumbnail URL to MP4 download URL
 *
 * Thumbnail formats seen:
 *   https://clips-media-assets2.twitch.tv/AT-cm%7C123456-preview-480x272.jpg
 *   https://clips-media-assets2.twitch.tv/vod-123456789-offset-12345-preview-480x272.jpg
 *   https://clips-media-assets2.twitch.tv/ABCDEF123-offset-12345-preview-480x272.jpg
 */
function thumbnailToMp4($thumbnailUrl) {
    if (!$thumbnailUrl || $thumbnailUrl === 'NOT_FOUND') {
        return null;
    }

    // Remove the preview suffix and .jpg, add .mp4
    // Pattern: -preview-WIDTHxHEIGHT.jpg at the end
    $mp4 = preg_replace('/-preview-\d+x\d+\.jpg$/i', '.mp4', $thumbnailUrl);

    // Also handle .png thumbnails (rare)
    $mp4 = preg_replace('/-preview-\d+x\d+\.png$/i', '.mp4', $mp4);

    // If no change was made, the URL format is unexpected
    if ($mp4 === $thumbnailUrl) {
        return null;
    }

    return $mp4;
}

// Fetch all clips with thumbnails
try {
    $stmt = $pdo->prepare("
        SELECT seq, clip_id, title, duration, view_count, game_id, created_at, thumbnail_url
        FROM clips
        WHERE login = ? AND blocked = FALSE
        ORDER BY seq ASC
    ");
    $stmt->execute([$login]);
    $clips = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    header("Content-Type: text/plain");
    echo "Database error: " . $e->getMessage();
    exit;
}

// Count stats
$totalClips = count($clips);
$withThumbnails = 0;
$withMp4 = 0;
$results = [];

foreach ($clips as $clip) {
    $thumbnail = $clip['thumbnail_url'] ?? '';
    $mp4 = thumbnailToMp4($thumbnail);

    if ($thumbnail && $thumbnail !== 'NOT_FOUND') {
        $withThumbnails++;
    }
    if ($mp4) {
        $withMp4++;
    }

    $results[] = [
        'seq' => (int)$clip['seq'],
        'clip_id' => $clip['clip_id'],
        'title' => $clip['title'],
        'duration' => (int)$clip['duration'],
        'view_count' => (int)$clip['view_count'],
        'game_id' => $clip['game_id'],
        'created_at' => $clip['created_at'],
        'thumbnail_url' => $thumbnail ?: null,
        'mp4_url' => $mp4,
        'twitch_url' => 'https://clips.twitch.tv/' . $clip['clip_id']
    ];
}

// Output based on format
switch ($format) {
    case 'txt':
        // Plain text - just MP4 URLs, one per line
        header("Content-Type: text/plain; charset=utf-8");
        header("Content-Disposition: attachment; filename=\"{$login}_mp4_links.txt\"");
        foreach ($results as $r) {
            if ($r['mp4_url']) {
                echo $r['mp4_url'] . "\n";
            }
        }
        break;

    case 'csv':
        // CSV with full metadata
        header("Content-Type: text/csv; charset=utf-8");
        header("Content-Disposition: attachment; filename=\"{$login}_clips.csv\"");

        // BOM for Excel UTF-8
        echo "\xEF\xBB\xBF";

        // Header row
        echo "seq,clip_id,title,duration,view_count,game_id,created_at,mp4_url,twitch_url\n";

        foreach ($results as $r) {
            $title = str_replace('"', '""', $r['title']); // Escape quotes
            echo sprintf(
                '%d,"%s","%s",%d,%d,"%s","%s","%s","%s"' . "\n",
                $r['seq'],
                $r['clip_id'],
                $title,
                $r['duration'],
                $r['view_count'],
                $r['game_id'] ?? '',
                $r['created_at'] ?? '',
                $r['mp4_url'] ?? '',
                $r['twitch_url']
            );
        }
        break;

    case 'json':
    default:
        // JSON with stats
        header("Content-Type: application/json; charset=utf-8");
        header("Access-Control-Allow-Origin: *");

        echo json_encode([
            'login' => $login,
            'total_clips' => $totalClips,
            'clips_with_thumbnails' => $withThumbnails,
            'clips_with_mp4_links' => $withMp4,
            'missing_thumbnails' => $totalClips - $withThumbnails,
            'note' => 'Run populate_thumbnails.php first if many thumbnails are missing',
            'clips' => $results
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        break;
}
