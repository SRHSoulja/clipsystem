<?php
/**
 * poll.php - Consolidated polling endpoint
 *
 * Reduces network overhead by combining all polling checks into a single request.
 * Returns all pending commands/state in one response instead of 7 separate calls.
 */
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/db_config.php';

set_cors_headers();
handle_options_request();
set_nocache_headers();
header("Content-Type: application/json; charset=utf-8");

$login = clean_login($_GET["login"] ?? "");

// Runtime data directory
$runtimeDir = get_runtime_dir();

$response = [
    "skip" => false,
    "prev" => false,
    "shuffle" => null,
    "force_play" => null,
    "category" => ["active" => false],
    "top_clips" => null,
    "votes" => ["up" => 0, "down" => 0]
];

$pdo = get_db_connection();

// ---- Check Skip Request ----
if ($pdo) {
    try {
        $stmt = $pdo->prepare("
            DELETE FROM command_requests
            WHERE login = ? AND command_type = 'skip' AND created_at > NOW() - INTERVAL '5 seconds'
            RETURNING nonce
        ");
        $stmt->execute([$login]);
        if ($stmt->fetch()) {
            $response["skip"] = true;
        }
    } catch (PDOException $e) {
        // Fall back to file-based check
    }
}

// File-based fallback for skip
if (!$response["skip"]) {
    $skipPath = $runtimeDir . "/skip_request_" . $login . ".json";
    if (file_exists($skipPath)) {
        $raw = @file_get_contents($skipPath);
        $data = $raw ? json_decode($raw, true) : null;
        if ($data && isset($data["nonce"])) {
            $setAt = isset($data["set_at"]) ? strtotime($data["set_at"]) : 0;
            if ($setAt && (time() - $setAt) <= 5) {
                @unlink($skipPath);
                $response["skip"] = true;
            } else {
                @unlink($skipPath);
            }
        }
    }
}

// ---- Check Prev Request ----
if ($pdo) {
    try {
        $stmt = $pdo->prepare("
            DELETE FROM command_requests
            WHERE login = ? AND command_type = 'prev' AND created_at > NOW() - INTERVAL '5 seconds'
            RETURNING nonce
        ");
        $stmt->execute([$login]);
        if ($stmt->fetch()) {
            $response["prev"] = true;
        }
    } catch (PDOException $e) {
        // Fall back to file-based check
    }
}

// File-based fallback for prev
if (!$response["prev"]) {
    $prevPath = $runtimeDir . "/prev_request_" . $login . ".json";
    if (file_exists($prevPath)) {
        $raw = @file_get_contents($prevPath);
        $data = $raw ? json_decode($raw, true) : null;
        if ($data && isset($data["nonce"])) {
            $setAt = isset($data["set_at"]) ? strtotime($data["set_at"]) : 0;
            if ($setAt && (time() - $setAt) <= 5) {
                @unlink($prevPath);
                $response["prev"] = true;
            } else {
                @unlink($prevPath);
            }
        }
    }
}

// ---- Check Shuffle Request ----
$shufflePath = $runtimeDir . "/shuffle_request_" . $login . ".json";
if (file_exists($shufflePath)) {
    $raw = @file_get_contents($shufflePath);
    $data = $raw ? json_decode($raw, true) : null;
    if ($data && isset($data["nonce"])) {
        $setAt = isset($data["set_at"]) ? strtotime($data["set_at"]) : 0;
        if ($setAt && (time() - $setAt) <= 30) {
            @unlink($shufflePath);
            $response["shuffle"] = [
                "nonce" => $data["nonce"]
            ];
        } else {
            @unlink($shufflePath);
        }
    }
}

// ---- Check Force Play Request ----
$forcePath = $runtimeDir . "/force_play_" . $login . ".json";
if (file_exists($forcePath)) {
    $raw = @file_get_contents($forcePath);
    $data = $raw ? json_decode($raw, true) : null;
    if ($data && isset($data["nonce"])) {
        $response["force_play"] = $data;
    }
}

// ---- Check Category Filter ----
$filterPath = $runtimeDir . "/category_filter_" . $login . ".json";
if (file_exists($filterPath)) {
    $raw = @file_get_contents($filterPath);
    $data = $raw ? json_decode($raw, true) : null;
    if ($data && (isset($data["game_id"]) || isset($data["game_ids"]))) {
        // Get clip data for category
        $gameIds = isset($data["game_ids"]) ? $data["game_ids"] : [$data["game_id"]];
        $clipIds = [];
        $categoryClips = [];

        if ($pdo && !empty($gameIds)) {
            try {
                $placeholders = implode(',', array_fill(0, count($gameIds), '?'));
                $params = array_merge([$login], $gameIds);

                $stmt = $pdo->prepare("
                    SELECT c.clip_id as id, c.seq, c.title, c.duration, c.created_at, c.view_count,
                           c.game_id, c.video_id, c.vod_offset, c.creator_name, c.thumbnail_url,
                           g.name as game_name
                    FROM clips c
                    LEFT JOIN games_cache g ON c.game_id = g.game_id
                    WHERE c.login = ? AND c.game_id IN ({$placeholders}) AND c.blocked = false
                    ORDER BY c.created_at DESC
                ");
                $stmt->execute($params);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $clipIds[] = $row['id'];
                    $categoryClips[] = $row;
                }
            } catch (PDOException $e) {
                error_log("poll category db error: " . $e->getMessage());
            }
        }

        $response["category"] = [
            "active" => true,
            "game_id" => $data["game_id"] ?? null,
            "game_ids" => $gameIds,
            "game_name" => $data["game_name"] ?? "",
            "nonce" => $data["nonce"] ?? "",
            "set_at" => $data["set_at"] ?? "",
            "clip_ids" => $clipIds,
            "clips" => $categoryClips,
            "clip_count" => count($clipIds)
        ];
    }
}

// ---- Check Top Clips Request ----
if ($pdo) {
    try {
        $stmt = $pdo->prepare("
            SELECT count, nonce, requested_at
            FROM ctop_requests
            WHERE login = ? AND requested_at > NOW() - INTERVAL '20 seconds'
        ");
        $stmt->execute([$login]);
        $topReq = $stmt->fetch();

        if ($topReq) {
            $count = (int)$topReq['count'];

            // Get top voted clips
            $stmt = $pdo->prepare("
                SELECT v.seq, v.title, v.up_votes, v.down_votes,
                       (v.up_votes - v.down_votes) as net_score
                FROM votes v
                WHERE v.login = ? AND (v.up_votes - v.down_votes) > 0
                ORDER BY net_score DESC, v.up_votes DESC
                LIMIT ?
            ");
            $stmt->execute([$login, $count]);
            $topClips = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($topClips)) {
                $response["top_clips"] = [
                    "active" => true,
                    "nonce" => $topReq['nonce'],
                    "count" => $count,
                    "clips" => $topClips
                ];
            }
        }
    } catch (PDOException $e) {
        error_log("poll top clips error: " . $e->getMessage());
    }
}

// ---- Get Current Vote Counts ----
if ($pdo) {
    try {
        // Get current clip from now_playing
        $npPath = $runtimeDir . "/now_playing_" . $login . ".json";
        if (file_exists($npPath)) {
            $npRaw = @file_get_contents($npPath);
            $npData = $npRaw ? json_decode($npRaw, true) : null;
            if ($npData && isset($npData["clip_id"])) {
                $stmt = $pdo->prepare("
                    SELECT up_votes, down_votes
                    FROM votes
                    WHERE login = ? AND clip_id = ?
                ");
                $stmt->execute([$login, $npData["clip_id"]]);
                $votes = $stmt->fetch();
                if ($votes) {
                    $response["votes"] = [
                        "up" => (int)$votes["up_votes"],
                        "down" => (int)$votes["down_votes"]
                    ];
                }
            }
        }
    } catch (PDOException $e) {
        error_log("poll votes error: " . $e->getMessage());
    }
}

echo json_encode($response, JSON_UNESCAPED_SLASHES);
