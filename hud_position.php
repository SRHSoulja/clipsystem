<?php
/**
 * hud_position.php - Get/Set HUD position for a channel
 *
 * GET: Returns current HUD position (and top clips overlay position)
 * POST: Sets HUD position (requires key)
 *
 * Positions: tr (top-right), tl (top-left), br (bottom-right), bl (bottom-left)
 * Types: hud (default), top (for top clips overlay)
 */
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/db_config.php';

function clean_login($s) {
  $s = strtolower(trim((string)$s));
  $s = preg_replace("/[^a-z0-9_]/", "", $s);
  return $s ?: "default";
}

$login = clean_login($_GET["login"] ?? $_POST["login"] ?? "");
$type = strtolower($_GET["type"] ?? $_POST["type"] ?? "hud"); // "hud" or "top"
$ADMIN_KEY = getenv('ADMIN_KEY') ?: '';

// Valid positions and types
$validPositions = ['tr', 'tl', 'tc', 'br', 'bl'];
$validTypes = ['hud', 'top'];
if (!in_array($type, $validTypes)) $type = 'hud';

// Column name based on type
$column = $type === 'top' ? 'top_position' : 'hud_position';
$defaultPos = $type === 'top' ? 'br' : 'tr'; // HUD defaults to top-right

// Use database to store position per channel
$pdo = get_db_connection();

if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['set'])) {
  // Set position - requires admin key
  $key = $_GET["key"] ?? $_POST["key"] ?? "";
  if ($ADMIN_KEY === '' || !hash_equals($ADMIN_KEY, (string)$key)) {
    http_response_code(403);
    echo json_encode(["error" => "forbidden"]);
    exit;
  }

  $position = strtolower($_GET["position"] ?? $_POST["position"] ?? $defaultPos);
  if (!in_array($position, $validPositions)) {
    $position = $defaultPos;
  }

  if ($pdo) {
    try {
      // Use channel_settings table (create if not exists) - add top_position column
      $pdo->exec("CREATE TABLE IF NOT EXISTS channel_settings (
        login VARCHAR(50) PRIMARY KEY,
        hud_position VARCHAR(10) DEFAULT 'tr',
        top_position VARCHAR(10) DEFAULT 'br',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
      )");

      // Add top_position column if it doesn't exist (for existing tables)
      try {
        $pdo->exec("ALTER TABLE channel_settings ADD COLUMN IF NOT EXISTS top_position VARCHAR(10) DEFAULT 'br'");
      } catch (PDOException $e) {
        // Column might already exist, ignore
      }

      // Add filtering columns if they don't exist
      try {
        $pdo->exec("ALTER TABLE channel_settings ADD COLUMN IF NOT EXISTS blocked_words TEXT DEFAULT '[]'");
        $pdo->exec("ALTER TABLE channel_settings ADD COLUMN IF NOT EXISTS blocked_clippers TEXT DEFAULT '[]'");
        $pdo->exec("ALTER TABLE channel_settings ADD COLUMN IF NOT EXISTS voting_enabled BOOLEAN DEFAULT TRUE");
        $pdo->exec("ALTER TABLE channel_settings ADD COLUMN IF NOT EXISTS last_refresh TIMESTAMP");
        $pdo->exec("ALTER TABLE channel_settings ADD COLUMN IF NOT EXISTS banner_config TEXT DEFAULT '{}'");
        $pdo->exec("ALTER TABLE channel_settings ADD COLUMN IF NOT EXISTS discord_hud_position VARCHAR(10) DEFAULT 'tr'");
        $pdo->exec("ALTER TABLE channel_settings ADD COLUMN IF NOT EXISTS obs_hud_position VARCHAR(10) DEFAULT 'tr'");
      } catch (PDOException $e) {
        // Columns might already exist, ignore
      }

      $stmt = $pdo->prepare("
        INSERT INTO channel_settings (login, {$column}, updated_at)
        VALUES (?, ?, NOW())
        ON CONFLICT (login) DO UPDATE SET {$column} = ?, updated_at = NOW()
      ");
      $stmt->execute([$login, $position, $position]);

      echo json_encode([
        "ok" => true,
        "login" => $login,
        "type" => $type,
        "position" => $position
      ]);
    } catch (PDOException $e) {
      http_response_code(500);
      echo json_encode(["error" => "Database error"]);
    }
  } else {
    http_response_code(500);
    echo json_encode(["error" => "no database"]);
  }
  exit;
}

// GET - return current position(s)
$debug = isset($_GET['debug']);

if ($pdo) {
  try {
    $stmt = $pdo->prepare("SELECT hud_position, discord_hud_position, obs_hud_position, top_position, banner_config FROM channel_settings WHERE login = ?");
    $stmt->execute([$login]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $hudPosition = $row && isset($row['hud_position']) ? $row['hud_position'] : 'tr';
    $discordHudPosition = $row && isset($row['discord_hud_position']) ? $row['discord_hud_position'] : 'tr';
    $obsHudPosition = $row && isset($row['obs_hud_position']) ? $row['obs_hud_position'] : 'tr';
    $topPosition = $row && isset($row['top_position']) ? $row['top_position'] : 'br';

    // Parse banner_config - handle empty/default cases
    $rawBanner = $row ? ($row['banner_config'] ?? null) : null;
    $bannerConfig = null;
    if ($rawBanner && $rawBanner !== '{}' && $rawBanner !== '') {
        $bannerConfig = json_decode($rawBanner, true);
    }
    if (!$bannerConfig || !is_array($bannerConfig) || empty($bannerConfig)) {
        $bannerConfig = new stdClass();
    }

    $response = [
      "login" => $login,
      "position" => $type === 'top' ? $topPosition : $hudPosition,
      "hud_position" => $hudPosition,
      "discord_hud_position" => $discordHudPosition,
      "obs_hud_position" => $obsHudPosition,
      "top_position" => $topPosition,
      "banner_config" => $bannerConfig
    ];

    if ($debug) {
      $response['_debug'] = [
        'row_found' => $row ? true : false,
        'raw_banner' => $rawBanner,
        'raw_banner_length' => $rawBanner ? strlen($rawBanner) : 0,
        'decoded_type' => gettype($bannerConfig),
      ];
    }

    echo json_encode($response);
  } catch (PDOException $e) {
    // Table might not exist yet, return defaults
    $response = [
      "login" => $login,
      "position" => $defaultPos,
      "hud_position" => "tr",
      "discord_hud_position" => "tr",
      "obs_hud_position" => "tr",
      "top_position" => "br",
      "banner_config" => new stdClass()
    ];
    if ($debug) {
      $response['_debug'] = ['error' => $e->getMessage()];
    }
    echo json_encode($response);
  }
} else {
  echo json_encode([
    "login" => $login,
    "position" => $defaultPos,
    "hud_position" => "tr",
    "discord_hud_position" => "tr",
    "obs_hud_position" => "tr",
    "top_position" => "br",
    "banner_config" => new stdClass()
  ]);
}
