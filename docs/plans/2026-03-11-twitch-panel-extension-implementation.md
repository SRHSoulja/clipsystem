# Twitch Panel Extension Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build a publicly installable Twitch Panel Extension that shows each streamer's own ClipTV library natively, with no external iframes, using JWT-verified API calls to the existing clipsystem backend.

**Architecture:** Static front-end assets in `twitch-extension/` (HTML/JS/CSS uploaded to Twitch), backed by a new `extension_api.php` endpoint in the clipsystem root. The extension gets the broadcaster's Twitch channel ID from a Twitch-signed JWT via `onAuthorized()`, passes it as a Bearer token to our backend, which verifies the JWT with the extension secret and resolves channel ID → login for DB queries. Lightweight panel preferences (sort, clip count, autoplay, featured) are stored in Twitch's broadcaster config segment; account linkage and clip data live in our DB.

**Tech Stack:** PHP 8 (backend), vanilla JS ES6 (extension front end), Twitch Extension Helper v1 CDN, PostgreSQL via existing PDO setup, Railway environment variables.

**Design doc:** `docs/plans/2026-03-11-twitch-panel-extension-design.md`

---

## Context You Need

### How Twitch Extensions Work

- Extension front-end assets are static files (HTML/JS/CSS) served from a URL you control during development (Local Test mode), then uploaded as a zip for Hosted Test and production.
- The front end loads inside a sandboxed Twitch iframe. No iframes of external sites allowed.
- `window.Twitch.ext` is injected by the Twitch Extension Helper script. Always load it from: `https://extension-files.twitch.tv/helper/v1/twitch-ext.min.js`
- `Twitch.ext.onAuthorized(auth => { ... })` is the main entry point. It fires when the extension has a valid auth context. `auth.token` is a JWT signed by Twitch with your extension secret.
- `Twitch.ext.onContext(ctx => { ... })` fires on context changes including theme (`ctx.theme` = `"light"` or `"dark"`).
- `Twitch.ext.configuration.set("broadcaster", version, value)` saves a string to Twitch's config segment. `Twitch.ext.configuration.onChanged(() => { ... })` reads it back. Value must be a string — JSON.stringify your settings object.

### Twitch JWT Payload Structure

```json
{
  "exp": 1503343947,
  "opaque_user_id": "UTTH4xxxx",
  "user_id": "44322889",
  "channel_id": "44322889",
  "role": "broadcaster",
  "is_unlinked": false,
  "pubsub_perms": { "listen": ["broadcast"], "send": [] }
}
```

- `channel_id` is always present and is the broadcaster's numeric Twitch user ID (string).
- `role` is `"broadcaster"` when the broadcaster themselves is viewing, `"viewer"` otherwise.
- The JWT is a standard base64url-encoded `header.payload.signature` token.
- Verify signature with HMAC-SHA256 using `base64_decode($TWITCH_EXT_SECRET)` as the key.

### Environment Variables (already on Railway, add the new one)

- `DB_URL` — existing PostgreSQL connection string
- `TWITCH_CLIENT_ID` — existing
- `TWITCH_CLIENT_SECRET` — existing
- `TWITCH_EXT_SECRET` — **new**: base64-encoded extension secret from Twitch developer console

### DB Pattern (follow existing code exactly)

```php
require_once __DIR__ . '/db_config.php';
// $pdo is available after this require
$stmt = $pdo->prepare("SELECT ... FROM clips WHERE login = ? AND blocked = FALSE");
$stmt->execute([$login]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
```

### Clip Columns Available

```sql
clip_id, seq, title, duration, created_at, view_count, game_id,
creator_name, thumbnail_url, mp4_url, platform, blocked
```

With join: `LEFT JOIN games_cache g ON c.game_id = g.game_id` → `g.name as game_name`

Clip URL pattern: `https://clips.twitch.tv/{clip_id}` (for Twitch clips; Kick clips use `mp4_url`).

---

## Task 1: DB Schema — Add `twitch_id` to `channel_settings`

**Files:**
- Modify: `extension_api.php` (handled here inline in the migration block — no separate migration file needed; the API file self-migrates like other PHP files in this project)

**Step 1: Open `dashboard_api.php` and read lines 145–175** to understand the existing ALTER TABLE pattern. This is the pattern to copy exactly.

**Step 2: Note the pattern** — each column is added with `ALTER TABLE channel_settings ADD COLUMN IF NOT EXISTS`. We follow the same approach in `extension_api.php`.

No commit yet — schema migration lives inside Task 3 (extension_api.php creation).

---

## Task 2: Create `twitch-extension/` folder and `common.js`

**Files:**
- Create: `twitch-extension/common.js`

**Step 1: Create the folder and file**

```bash
mkdir -p twitch-extension
```

**Step 2: Write `twitch-extension/common.js`**

```javascript
// common.js — shared helpers for all extension views
// Loaded before panel.js and config.js

const EBS_BASE = 'https://clips.gmgnrepeat.com';

// Apply Twitch theme (call whenever ctx.theme changes)
function applyTheme(theme) {
  document.body.setAttribute('data-theme', theme === 'light' ? 'light' : 'dark');
}

// Format duration seconds → "1:23" or "45s"
function formatDuration(seconds) {
  if (!seconds) return '';
  const s = Math.round(seconds);
  if (s < 60) return `${s}s`;
  return `${Math.floor(s / 60)}:${(s % 60).toString().padStart(2, '0')}`;
}

// Format view count → "142K" or "1.2M"
function formatViews(n) {
  if (!n) return '';
  if (n >= 1000000) return (n / 1000000).toFixed(1).replace(/\.0$/, '') + 'M';
  if (n >= 1000) return Math.round(n / 1000) + 'K';
  return String(n);
}

// Escape HTML for safe text insertion
function escHtml(str) {
  return String(str || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

// Authenticated fetch to our EBS — passes the Twitch JWT
async function extFetch(path, token, options = {}) {
  const url = `${EBS_BASE}${path}`;
  const res = await fetch(url, {
    ...options,
    headers: {
      'Authorization': `Bearer ${token}`,
      'Content-Type': 'application/json',
      ...(options.headers || {})
    }
  });
  if (!res.ok) {
    const text = await res.text();
    throw new Error(`EBS ${res.status}: ${text}`);
  }
  return res.json();
}

// Read Twitch broadcaster config segment (returns parsed object or {})
function readBroadcasterConfig() {
  try {
    const seg = window.Twitch.ext.configuration.broadcaster;
    if (seg && seg.content) return JSON.parse(seg.content);
  } catch (e) {}
  return {};
}

// Write Twitch broadcaster config segment
function writeBroadcasterConfig(settings) {
  window.Twitch.ext.configuration.set('broadcaster', '1', JSON.stringify(settings));
}

// Default panel settings
const DEFAULT_SETTINGS = {
  ext_sort: 'recent',
  ext_clip_count: 10,
  ext_autoplay: false,
  ext_featured: false
};
```

**Step 3: Commit**

```bash
git add twitch-extension/common.js
git commit -m "feat(ext): add twitch-extension folder and common.js helpers"
```

---

## Task 3: Create `extension_api.php` — skeleton + `channel` endpoint

**Files:**
- Create: `extension_api.php`

**Step 1: Write the file**

```php
<?php
/**
 * extension_api.php — EBS for ClipTV Twitch Panel Extension
 *
 * All endpoints require a Twitch-signed JWT in the Authorization: Bearer header.
 * JWT is verified with TWITCH_EXT_SECRET (base64-encoded, from Twitch dev console).
 *
 * Actions:
 *   GET  ?action=channel   — resolve broadcaster + fetch settings
 *   GET  ?action=clips     — fetch clips (sort=recent|top|random, limit=5-25)
 *   GET  ?action=search    — search clips (q=query)
 *   POST ?action=settings  — save broadcaster settings (role=broadcaster required)
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/db_config.php';

// ── Helpers ──────────────────────────────────────────────────────────────────

function json_ok($data) {
  echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}

function json_err($msg, $code = 400) {
  http_response_code($code);
  echo json_encode(['error' => $msg]);
  exit;
}

// ── JWT Verification ─────────────────────────────────────────────────────────

/**
 * Verify a Twitch extension JWT and return the decoded payload array.
 * Throws on failure. Never trust unverified data.
 */
function verify_twitch_jwt(string $token): array {
  $secret_b64 = getenv('TWITCH_EXT_SECRET');
  if (!$secret_b64) {
    throw new Exception('TWITCH_EXT_SECRET not configured');
  }
  $secret = base64_decode($secret_b64);

  $parts = explode('.', $token);
  if (count($parts) !== 3) {
    throw new Exception('Malformed JWT');
  }

  [$header_b64, $payload_b64, $sig_b64] = $parts;

  // Verify signature
  $expected_sig = hash_hmac('sha256', "$header_b64.$payload_b64", $secret, true);
  $provided_sig = base64_decode(strtr($sig_b64, '-_', '+/') . str_repeat('=', (4 - strlen($sig_b64) % 4) % 4));

  if (!hash_equals($expected_sig, $provided_sig)) {
    throw new Exception('Invalid JWT signature');
  }

  // Decode payload
  $payload_json = base64_decode(strtr($payload_b64, '-_', '+/') . str_repeat('=', (4 - strlen($payload_b64) % 4) % 4));
  $payload = json_decode($payload_json, true);
  if (!$payload) {
    throw new Exception('Could not decode JWT payload');
  }

  // Check expiry
  if (isset($payload['exp']) && $payload['exp'] < time()) {
    throw new Exception('JWT expired');
  }

  return $payload;
}

/**
 * Extract Bearer token from Authorization header and verify it.
 * Returns verified payload array. Calls json_err() and exits on failure.
 */
function require_jwt(): array {
  $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
  if (!str_starts_with($auth, 'Bearer ')) {
    json_err('Missing Authorization header', 401);
  }
  $token = substr($auth, 7);
  try {
    return verify_twitch_jwt($token);
  } catch (Exception $e) {
    json_err('JWT error: ' . $e->getMessage(), 401);
  }
}

// ── Schema Migration (idempotent) ────────────────────────────────────────────

try {
  $pdo->exec("ALTER TABLE channel_settings ADD COLUMN IF NOT EXISTS twitch_id VARCHAR(64)");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_channel_settings_twitch_id ON channel_settings(twitch_id)");
} catch (PDOException $e) {
  error_log('extension_api migration error: ' . $e->getMessage());
}

// ── Routing ───────────────────────────────────────────────────────────────────

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// ── GET channel ───────────────────────────────────────────────────────────────

if ($action === 'channel' && $method === 'GET') {
  $payload = require_jwt();
  $channel_id = $payload['channel_id'] ?? '';
  if (!$channel_id) json_err('channel_id missing from JWT', 400);

  // Look up channel_id → login in channel_settings first, then known_users
  $login = null;
  $display_name = null;
  $profile_image_url = null;

  try {
    $stmt = $pdo->prepare(
      "SELECT login, display_name, profile_image_url FROM channel_settings WHERE twitch_id = ? LIMIT 1"
    );
    $stmt->execute([$channel_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
      $login = $row['login'];
      $display_name = $row['display_name'];
      $profile_image_url = $row['profile_image_url'];
    }
  } catch (PDOException $e) {
    error_log('extension_api channel lookup error: ' . $e->getMessage());
  }

  // Fallback: known_users table
  if (!$login) {
    try {
      $stmt = $pdo->prepare(
        "SELECT login, display_name, profile_image_url FROM known_users WHERE twitch_id = ? LIMIT 1"
      );
      $stmt->execute([$channel_id]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      if ($row) {
        $login = $row['login'];
        $display_name = $row['display_name'];
        $profile_image_url = $row['profile_image_url'];
      }
    } catch (PDOException $e) {
      error_log('extension_api known_users lookup error: ' . $e->getMessage());
    }
  }

  if (!$login) {
    // Broadcaster is not in our system
    json_ok(['registered' => false]);
  }

  // Verify they actually have clips
  try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM clips WHERE login = ? AND blocked = FALSE");
    $stmt->execute([$login]);
    $count = (int)$stmt->fetchColumn();
    if ($count === 0) {
      json_ok(['registered' => false, 'reason' => 'no_clips']);
    }
  } catch (PDOException $e) {
    error_log('extension_api clip count error: ' . $e->getMessage());
  }

  json_ok([
    'registered' => true,
    'login' => $login,
    'display_name' => $display_name ?: $login,
    'profile_image_url' => $profile_image_url ?: null,
    'clip_count' => $count ?? 0
  ]);
}

json_err('Unknown action', 400);
```

**Step 2: Verify the file exists and has no PHP parse errors**

```bash
php -l extension_api.php
```

Expected: `No syntax errors detected in extension_api.php`

**Step 3: Test the `channel` endpoint manually**

Since we can't easily generate a valid Twitch JWT locally, test with a deliberately bad token to confirm auth rejection works:

```bash
curl -s -H "Authorization: Bearer fake.token.here" \
  "https://your-railway-url.railway.app/extension_api.php?action=channel"
```

Expected: `{"error":"JWT error: Malformed JWT"}` with HTTP 401.

Also test with no header:

```bash
curl -s "https://your-railway-url.railway.app/extension_api.php?action=channel"
```

Expected: `{"error":"Missing Authorization header"}` with HTTP 401.

**Step 4: Commit**

```bash
git add extension_api.php
git commit -m "feat(ext): add extension_api.php with JWT verification and channel endpoint"
```

---

## Task 4: Add `clips` endpoint to `extension_api.php`

**Files:**
- Modify: `extension_api.php`

**Step 1: Find the final line** `json_err('Unknown action', 400);` and insert the clips endpoint above it.

```php
// ── GET clips ────────────────────────────────────────────────────────────────

if ($action === 'clips' && $method === 'GET') {
  $payload = require_jwt();
  $channel_id = $payload['channel_id'] ?? '';
  if (!$channel_id) json_err('channel_id missing from JWT', 400);

  // Resolve channel_id → login
  $login = resolve_login($pdo, $channel_id);
  if (!$login) json_err('Channel not registered with ClipTV', 404);

  $sort  = $_GET['sort'] ?? 'recent';
  $limit = max(5, min(25, (int)($_GET['limit'] ?? 10)));

  try {
    $base_sql = "
      SELECT c.clip_id as id, c.seq, c.title, c.duration, c.created_at,
             c.view_count, c.creator_name, c.thumbnail_url, c.platform,
             g.name as game_name
      FROM clips c
      LEFT JOIN games_cache g ON c.game_id = g.game_id
      WHERE c.login = ? AND c.blocked = FALSE
    ";

    if ($sort === 'top') {
      $sql = $base_sql . " ORDER BY c.view_count DESC LIMIT ?";
    } elseif ($sort === 'random') {
      $sql = $base_sql . " ORDER BY RANDOM() LIMIT ?";
    } else {
      // recent (default)
      $sql = $base_sql . " ORDER BY c.created_at DESC LIMIT ?";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$login, $limit]);
    $clips = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Normalise output
    foreach ($clips as &$c) {
      if ($c['created_at'] instanceof DateTime) {
        $c['created_at'] = $c['created_at']->format('c');
      }
      // Build clip URL
      $c['clip_url'] = ($c['platform'] === 'kick' && !empty($c['mp4_url']))
        ? $c['mp4_url']
        : 'https://clips.twitch.tv/' . urlencode($c['id']);
      unset($c['platform']);
    }
    unset($c);

    json_ok(['clips' => $clips, 'sort' => $sort, 'login' => $login]);

  } catch (PDOException $e) {
    error_log('extension_api clips error: ' . $e->getMessage());
    json_err('Database error', 500);
  }
}
```

**Step 2: Add the `resolve_login()` helper** — insert this right after the `require_jwt()` function definition (before the schema migration block):

```php
/**
 * Resolve a Twitch channel_id to a ClipTV login string.
 * Checks channel_settings first, then known_users. Returns null if not found.
 */
function resolve_login(PDO $pdo, string $channel_id): ?string {
  foreach (['channel_settings', 'known_users'] as $table) {
    try {
      $stmt = $pdo->prepare("SELECT login FROM {$table} WHERE twitch_id = ? LIMIT 1");
      $stmt->execute([$channel_id]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      if ($row) return $row['login'];
    } catch (PDOException $e) {
      error_log("resolve_login {$table} error: " . $e->getMessage());
    }
  }
  return null;
}
```

**Step 3: Refactor `channel` endpoint** to use `resolve_login()` instead of its inline lookup (remove the two try/catch blocks that did the lookup, replace with one line: `$login = resolve_login($pdo, $channel_id);`).

**Step 4: Verify no parse errors**

```bash
php -l extension_api.php
```

**Step 5: Commit**

```bash
git add extension_api.php
git commit -m "feat(ext): add clips endpoint with recent/top/random sort"
```

---

## Task 5: Add `search` endpoint to `extension_api.php`

**Files:**
- Modify: `extension_api.php`

**Step 1: Insert above the final `json_err` line**

```php
// ── GET search ────────────────────────────────────────────────────────────────

if ($action === 'search' && $method === 'GET') {
  $payload = require_jwt();
  $channel_id = $payload['channel_id'] ?? '';
  if (!$channel_id) json_err('channel_id missing from JWT', 400);

  $login = resolve_login($pdo, $channel_id);
  if (!$login) json_err('Channel not registered with ClipTV', 404);

  $q = trim($_GET['q'] ?? '');
  if (strlen($q) < 2) json_err('Query too short', 400);

  $limit = 20;

  try {
    $stmt = $pdo->prepare("
      SELECT c.clip_id as id, c.seq, c.title, c.duration, c.created_at,
             c.view_count, c.creator_name, c.thumbnail_url, c.platform,
             g.name as game_name
      FROM clips c
      LEFT JOIN games_cache g ON c.game_id = g.game_id
      WHERE c.login = ? AND c.blocked = FALSE
        AND (c.title ILIKE ? OR c.creator_name ILIKE ? OR g.name ILIKE ?)
      ORDER BY c.view_count DESC
      LIMIT ?
    ");
    $like = '%' . $q . '%';
    $stmt->execute([$login, $like, $like, $like, $limit]);
    $clips = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($clips as &$c) {
      if ($c['created_at'] instanceof DateTime) {
        $c['created_at'] = $c['created_at']->format('c');
      }
      $c['clip_url'] = ($c['platform'] === 'kick' && !empty($c['mp4_url']))
        ? $c['mp4_url']
        : 'https://clips.twitch.tv/' . urlencode($c['id']);
      unset($c['platform']);
    }
    unset($c);

    json_ok(['clips' => $clips, 'query' => $q]);

  } catch (PDOException $e) {
    error_log('extension_api search error: ' . $e->getMessage());
    json_err('Database error', 500);
  }
}
```

**Step 2: Verify**

```bash
php -l extension_api.php
```

**Step 3: Commit**

```bash
git add extension_api.php
git commit -m "feat(ext): add search endpoint"
```

---

## Task 6: Add `settings` POST endpoint to `extension_api.php`

**Files:**
- Modify: `extension_api.php`

This endpoint saves broadcaster-level settings (twitch_id → login linkage plus DB-side settings). Lightweight panel prefs are stored in Twitch's config segment client-side; this endpoint handles the ClipTV account linkage.

**Step 1: Insert above the final `json_err` line**

```php
// ── POST settings ─────────────────────────────────────────────────────────────

if ($action === 'settings' && $method === 'POST') {
  $payload = require_jwt();
  $channel_id = $payload['channel_id'] ?? '';
  $role       = $payload['role'] ?? '';

  if ($role !== 'broadcaster') json_err('Broadcaster role required', 403);
  if (!$channel_id) json_err('channel_id missing from JWT', 400);

  $body = json_decode(file_get_contents('php://input'), true) ?? [];

  // Only accepted field for now: claim — associates this twitch_id with a ClipTV login
  $login = trim(strtolower($body['login'] ?? ''));
  $login = preg_replace('/[^a-z0-9_]/', '', $login);

  if ($login) {
    // Verify this login actually exists and has clips
    try {
      $stmt = $pdo->prepare("SELECT COUNT(*) FROM clips WHERE login = ? AND blocked = FALSE");
      $stmt->execute([$login]);
      if ((int)$stmt->fetchColumn() === 0) {
        json_err('No ClipTV library found for that login', 404);
      }

      // Link twitch_id to this login in channel_settings
      $stmt = $pdo->prepare("
        INSERT INTO channel_settings (login, twitch_id, updated_at)
        VALUES (?, ?, NOW())
        ON CONFLICT (login) DO UPDATE SET twitch_id = EXCLUDED.twitch_id, updated_at = NOW()
      ");
      $stmt->execute([$login, $channel_id]);

      json_ok(['success' => true, 'login' => $login, 'linked' => true]);

    } catch (PDOException $e) {
      error_log('extension_api settings error: ' . $e->getMessage());
      json_err('Database error', 500);
    }
  }

  json_ok(['success' => true]);
}
```

**Step 2: Verify**

```bash
php -l extension_api.php
```

**Step 3: Commit**

```bash
git add extension_api.php
git commit -m "feat(ext): add settings POST endpoint for broadcaster account linkage"
```

---

## Task 7: `panel.css`

**Files:**
- Create: `twitch-extension/panel.css`

Panel is 318px wide. Design for a fixed-height compact app. Use CSS custom properties for light/dark theming.

```css
/* panel.css — ClipTV Twitch Panel Extension */

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --bg:        #0e0e10;
  --surface:   #18181b;
  --border:    #2a2a2d;
  --text:      #efeff1;
  --text-muted:#adadb8;
  --accent:    #9147ff;
  --accent-h:  #772ce8;
  --thumb-bg:  #26262c;
  --card-h:    #26262c;
}

[data-theme="light"] {
  --bg:        #f7f7f8;
  --surface:   #ffffff;
  --border:    #dedee3;
  --text:      #0e0e10;
  --text-muted:#53535f;
  --thumb-bg:  #e8e8ea;
  --card-h:    #f0f0f2;
}

html, body {
  width: 318px;
  height: 100%;
  background: var(--bg);
  color: var(--text);
  font-family: 'Inter', system-ui, -apple-system, sans-serif;
  font-size: 13px;
  overflow: hidden;
}

/* ── Header ─────────────────────────────────────────────────────────────── */
.ext-header {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 12px 8px;
  border-bottom: 1px solid var(--border);
  flex-shrink: 0;
}

.ext-logo {
  font-size: 16px;
  line-height: 1;
}

.ext-channel-name {
  font-weight: 600;
  font-size: 13px;
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* ── Tabs ────────────────────────────────────────────────────────────────── */
.ext-tabs {
  display: flex;
  border-bottom: 1px solid var(--border);
  flex-shrink: 0;
}

.ext-tab {
  flex: 1;
  background: none;
  border: none;
  border-bottom: 2px solid transparent;
  color: var(--text-muted);
  font-size: 12px;
  font-weight: 500;
  padding: 8px 4px;
  cursor: pointer;
  transition: color 0.15s, border-color 0.15s;
  letter-spacing: 0.02em;
}

.ext-tab:hover { color: var(--text); }
.ext-tab.active {
  color: var(--accent);
  border-bottom-color: var(--accent);
}

/* ── Search bar (replaces tab bar in search mode) ────────────────────────── */
.ext-search-bar {
  display: none;
  align-items: center;
  gap: 6px;
  padding: 7px 10px;
  border-bottom: 1px solid var(--border);
  flex-shrink: 0;
}

.ext-search-bar.visible { display: flex; }

.ext-search-input {
  flex: 1;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 4px;
  color: var(--text);
  font-size: 12px;
  padding: 5px 8px;
  outline: none;
}

.ext-search-input:focus { border-color: var(--accent); }

.ext-search-close {
  background: none;
  border: none;
  color: var(--text-muted);
  cursor: pointer;
  font-size: 16px;
  line-height: 1;
  padding: 2px 4px;
}

/* ── Clip list ───────────────────────────────────────────────────────────── */
.ext-clip-list {
  flex: 1;
  overflow-y: auto;
  overflow-x: hidden;
}

.ext-clip-list::-webkit-scrollbar { width: 4px; }
.ext-clip-list::-webkit-scrollbar-track { background: transparent; }
.ext-clip-list::-webkit-scrollbar-thumb { background: var(--border); border-radius: 2px; }

.clip-card {
  display: flex;
  gap: 9px;
  padding: 8px 12px;
  cursor: pointer;
  border-bottom: 1px solid var(--border);
  transition: background 0.1s;
  text-decoration: none;
  color: inherit;
}

.clip-card:hover { background: var(--card-h); }

.clip-thumb {
  width: 72px;
  height: 40px;
  border-radius: 3px;
  object-fit: cover;
  background: var(--thumb-bg);
  flex-shrink: 0;
}

.clip-thumb-placeholder {
  width: 72px;
  height: 40px;
  border-radius: 3px;
  background: var(--thumb-bg);
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
}

.clip-info {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: 3px;
}

.clip-title {
  font-size: 12px;
  font-weight: 500;
  line-height: 1.3;
  overflow: hidden;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
}

.clip-meta {
  font-size: 11px;
  color: var(--text-muted);
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}

/* ── Status messages ─────────────────────────────────────────────────────── */
.ext-status {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  height: 100%;
  gap: 10px;
  padding: 20px;
  text-align: center;
}

.ext-status-icon { font-size: 32px; }
.ext-status-title { font-weight: 600; font-size: 13px; }
.ext-status-body { color: var(--text-muted); font-size: 12px; line-height: 1.5; }

.ext-btn {
  display: inline-block;
  background: var(--accent);
  color: #fff;
  border: none;
  border-radius: 4px;
  font-size: 12px;
  font-weight: 600;
  padding: 7px 14px;
  cursor: pointer;
  text-decoration: none;
  transition: background 0.15s;
}

.ext-btn:hover { background: var(--accent-h); }

/* ── Spinner ─────────────────────────────────────────────────────────────── */
.ext-spinner {
  width: 28px;
  height: 28px;
  border: 3px solid var(--border);
  border-top-color: var(--accent);
  border-radius: 50%;
  animation: spin 0.7s linear infinite;
}

@keyframes spin { to { transform: rotate(360deg); } }

/* ── Footer ──────────────────────────────────────────────────────────────── */
.ext-footer {
  border-top: 1px solid var(--border);
  padding: 8px 12px;
  flex-shrink: 0;
}

.ext-footer-btn {
  display: block;
  width: 100%;
  text-align: center;
  background: none;
  border: 1px solid var(--border);
  border-radius: 4px;
  color: var(--text-muted);
  font-size: 12px;
  padding: 6px;
  cursor: pointer;
  text-decoration: none;
  transition: border-color 0.15s, color 0.15s;
}

.ext-footer-btn:hover { border-color: var(--accent); color: var(--accent); }

/* ── Layout shell ────────────────────────────────────────────────────────── */
.ext-shell {
  display: flex;
  flex-direction: column;
  height: 100vh;
}
```

**Step 2: Commit**

```bash
git add twitch-extension/panel.css
git commit -m "feat(ext): add panel.css with light/dark theme support"
```

---

## Task 8: `panel.html`

**Files:**
- Create: `twitch-extension/panel.html`

```html
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ClipTV</title>
  <link rel="stylesheet" href="panel.css">
</head>
<body data-theme="dark">

<div class="ext-shell" id="shell" style="display:none">

  <!-- Header -->
  <div class="ext-header">
    <span class="ext-logo">📼</span>
    <span class="ext-channel-name" id="channelName">ClipTV</span>
  </div>

  <!-- Tab bar -->
  <div class="ext-tabs" id="tabBar">
    <button class="ext-tab active" data-sort="recent">Recent</button>
    <button class="ext-tab"        data-sort="top">Top</button>
    <button class="ext-tab"        data-sort="random">Random</button>
    <button class="ext-tab"        data-sort="search" title="Search">🔍</button>
  </div>

  <!-- Search bar (hidden until search tab clicked) -->
  <div class="ext-search-bar" id="searchBar">
    <input class="ext-search-input" id="searchInput" type="text" placeholder="Search clips…" autocomplete="off">
    <button class="ext-search-close" id="searchClose" title="Close">✕</button>
  </div>

  <!-- Clip list / status -->
  <div class="ext-clip-list" id="clipList"></div>

  <!-- Footer -->
  <div class="ext-footer">
    <a class="ext-footer-btn" id="fullSiteBtn" href="#" target="_blank">Open full ClipTV →</a>
  </div>

</div>

<!-- Loading state (shown before auth/data ready) -->
<div class="ext-status" id="loadingState">
  <div class="ext-spinner"></div>
</div>

<!-- Not registered state -->
<div class="ext-status" id="unregisteredState" style="display:none">
  <div class="ext-status-icon">📼</div>
  <div class="ext-status-title">ClipTV not set up</div>
  <div class="ext-status-body">This streamer hasn't linked their ClipTV clip library yet.</div>
  <a class="ext-btn" href="https://clips.gmgnrepeat.com" target="_blank">Learn about ClipTV</a>
</div>

<!-- Error state -->
<div class="ext-status" id="errorState" style="display:none">
  <div class="ext-status-icon">⚠️</div>
  <div class="ext-status-title">Couldn't load clips</div>
  <div class="ext-status-body" id="errorMsg">Something went wrong.</div>
  <button class="ext-btn" id="retryBtn">Try again</button>
</div>

<script src="https://extension-files.twitch.tv/helper/v1/twitch-ext.min.js"></script>
<script src="common.js"></script>
<script src="panel.js"></script>
</body>
</html>
```

**Step 2: Commit**

```bash
git add twitch-extension/panel.html
git commit -m "feat(ext): add panel.html structure"
```

---

## Task 9: `panel.js` — core panel logic

**Files:**
- Create: `twitch-extension/panel.js`

```javascript
// panel.js — ClipTV Twitch Panel Extension

(function () {
  'use strict';

  // ── State ──────────────────────────────────────────────────────────────────
  let authToken   = null;
  let channelInfo = null;
  let settings    = { ...DEFAULT_SETTINGS };
  let currentSort = 'recent';
  let searchTimer = null;

  // ── Elements ───────────────────────────────────────────────────────────────
  const shell            = document.getElementById('shell');
  const loadingState     = document.getElementById('loadingState');
  const unregisteredState= document.getElementById('unregisteredState');
  const errorState       = document.getElementById('errorState');
  const errorMsg         = document.getElementById('errorMsg');
  const retryBtn         = document.getElementById('retryBtn');
  const channelName      = document.getElementById('channelName');
  const clipList         = document.getElementById('clipList');
  const tabBar           = document.getElementById('tabBar');
  const searchBar        = document.getElementById('searchBar');
  const searchInput      = document.getElementById('searchInput');
  const searchClose      = document.getElementById('searchClose');
  const fullSiteBtn      = document.getElementById('fullSiteBtn');

  // ── Show/hide state panels ─────────────────────────────────────────────────
  function showLoading() {
    loadingState.style.display = '';
    shell.style.display = 'none';
    unregisteredState.style.display = 'none';
    errorState.style.display = 'none';
  }

  function showShell() {
    loadingState.style.display = 'none';
    shell.style.display = '';
    unregisteredState.style.display = 'none';
    errorState.style.display = 'none';
  }

  function showUnregistered() {
    loadingState.style.display = 'none';
    shell.style.display = 'none';
    unregisteredState.style.display = '';
    errorState.style.display = 'none';
  }

  function showError(msg) {
    loadingState.style.display = 'none';
    shell.style.display = 'none';
    unregisteredState.style.display = 'none';
    errorState.style.display = '';
    errorMsg.textContent = msg || 'Something went wrong.';
  }

  // ── Render clip cards ──────────────────────────────────────────────────────
  function renderClips(clips) {
    if (!clips || clips.length === 0) {
      clipList.innerHTML = `
        <div class="ext-status" style="height:auto;padding:30px 20px">
          <div class="ext-status-icon">🎬</div>
          <div class="ext-status-body">No clips found</div>
        </div>`;
      return;
    }

    clipList.innerHTML = clips.map(clip => {
      const thumb = clip.thumbnail_url
        ? `<img class="clip-thumb" src="${escHtml(clip.thumbnail_url)}" alt="" loading="lazy">`
        : `<div class="clip-thumb-placeholder">🎬</div>`;

      const meta = [
        clip.creator_name ? escHtml(clip.creator_name) : null,
        clip.duration     ? formatDuration(clip.duration) : null,
        clip.view_count   ? formatViews(clip.view_count) + ' views' : null
      ].filter(Boolean).join(' · ');

      return `<a class="clip-card" href="${escHtml(clip.clip_url)}" target="_blank" rel="noopener">
        ${thumb}
        <div class="clip-info">
          <div class="clip-title">${escHtml(clip.title || 'Untitled')}</div>
          <div class="clip-meta">${meta}</div>
        </div>
      </a>`;
    }).join('');
  }

  // ── Fetch and render clips ─────────────────────────────────────────────────
  async function loadClips(sort) {
    clipList.innerHTML = `<div class="ext-status" style="height:auto;padding:30px 20px"><div class="ext-spinner"></div></div>`;
    try {
      const limit = settings.ext_clip_count || 10;
      const data = await extFetch(
        `/extension_api.php?action=clips&sort=${encodeURIComponent(sort)}&limit=${limit}`,
        authToken
      );
      renderClips(data.clips);
    } catch (e) {
      clipList.innerHTML = `<div class="ext-status" style="height:auto;padding:20px">
        <div class="ext-status-body">Couldn't load clips. <a href="#" id="inlineRetry" style="color:var(--accent)">Retry</a></div>
      </div>`;
      document.getElementById('inlineRetry')?.addEventListener('click', e => {
        e.preventDefault();
        loadClips(sort);
      });
    }
  }

  // ── Tabs ───────────────────────────────────────────────────────────────────
  tabBar.addEventListener('click', e => {
    const btn = e.target.closest('.ext-tab');
    if (!btn) return;

    const sort = btn.dataset.sort;

    if (sort === 'search') {
      // Show search UI
      tabBar.style.display = 'none';
      searchBar.classList.add('visible');
      searchInput.value = '';
      searchInput.focus();
      clipList.innerHTML = '';
      return;
    }

    // Switch tab
    tabBar.querySelectorAll('.ext-tab').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');
    currentSort = sort;
    loadClips(sort);
  });

  // ── Search ─────────────────────────────────────────────────────────────────
  searchInput.addEventListener('input', () => {
    clearTimeout(searchTimer);
    const q = searchInput.value.trim();
    if (q.length < 2) { clipList.innerHTML = ''; return; }
    searchTimer = setTimeout(() => runSearch(q), 400);
  });

  searchClose.addEventListener('click', () => {
    searchBar.classList.remove('visible');
    tabBar.style.display = '';
    // Restore active tab
    const activeTab = tabBar.querySelector(`.ext-tab[data-sort="${currentSort}"]`);
    if (activeTab) activeTab.classList.add('active');
    loadClips(currentSort);
  });

  async function runSearch(q) {
    clipList.innerHTML = `<div class="ext-status" style="height:auto;padding:20px 20px"><div class="ext-spinner"></div></div>`;
    try {
      const data = await extFetch(
        `/extension_api.php?action=search&q=${encodeURIComponent(q)}`,
        authToken
      );
      renderClips(data.clips);
    } catch (e) {
      clipList.innerHTML = `<div class="ext-status" style="height:auto;padding:20px"><div class="ext-status-body">Search failed</div></div>`;
    }
  }

  // ── Retry button ───────────────────────────────────────────────────────────
  retryBtn.addEventListener('click', () => init());

  // ── Initialise ─────────────────────────────────────────────────────────────
  async function init() {
    showLoading();

    // Read settings from Twitch config segment
    const saved = readBroadcasterConfig();
    settings = { ...DEFAULT_SETTINGS, ...saved };
    currentSort = settings.ext_sort || 'recent';

    try {
      const data = await extFetch('/extension_api.php?action=channel', authToken);

      if (!data.registered) {
        showUnregistered();
        return;
      }

      channelInfo = data;

      // Update header + footer
      channelName.textContent = (data.display_name || data.login) + ''s clips';
      fullSiteBtn.href = `https://clips.gmgnrepeat.com/tv/${encodeURIComponent(data.login)}`;

      // Activate correct tab
      tabBar.querySelectorAll('.ext-tab').forEach(t => {
        t.classList.toggle('active', t.dataset.sort === currentSort);
      });

      showShell();
      loadClips(currentSort);

    } catch (e) {
      showError('Couldn\'t connect to ClipTV. Check back soon.');
    }
  }

  // ── Twitch Extension entry points ──────────────────────────────────────────
  window.Twitch.ext.onAuthorized(auth => {
    authToken = auth.token;
    init();
  });

  window.Twitch.ext.onContext(ctx => {
    if (ctx.theme) applyTheme(ctx.theme);
  });

  // Respond to config changes (broadcaster saves new settings in config view)
  window.Twitch.ext.configuration.onChanged(() => {
    const saved = readBroadcasterConfig();
    settings = { ...DEFAULT_SETTINGS, ...saved };
    // Re-render with new settings if already loaded
    if (channelInfo) {
      currentSort = settings.ext_sort || currentSort;
      loadClips(currentSort);
    }
  });

})();
```

**Step 2: Verify the file was created correctly**

```bash
wc -l twitch-extension/panel.js
```

**Step 3: Commit**

```bash
git add twitch-extension/panel.js
git commit -m "feat(ext): add panel.js with tabs, clip cards, search, and auth flow"
```

---

## Task 10: `config.html` and `config.js`

**Files:**
- Create: `twitch-extension/config.html`
- Create: `twitch-extension/config.js`

**Step 1: Write `config.html`**

```html
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ClipTV Panel Settings</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --bg: #0e0e10; --surface: #18181b; --border: #2a2a2d;
      --text: #efeff1; --text-muted: #adadb8;
      --accent: #9147ff; --accent-h: #772ce8;
    }
    [data-theme="light"] {
      --bg: #f7f7f8; --surface: #fff; --border: #dedee3;
      --text: #0e0e10; --text-muted: #53535f;
    }

    body {
      background: var(--bg); color: var(--text);
      font-family: 'Inter', system-ui, sans-serif;
      font-size: 13px; padding: 16px; min-height: 100vh;
    }

    h1 { font-size: 15px; font-weight: 700; margin-bottom: 16px; }

    .field { margin-bottom: 16px; }
    .field label { display: block; font-weight: 600; margin-bottom: 6px; font-size: 12px; color: var(--text-muted); letter-spacing: 0.05em; text-transform: uppercase; }

    .radio-group { display: flex; gap: 8px; flex-wrap: wrap; }
    .radio-opt { display: flex; align-items: center; gap: 5px; cursor: pointer; font-size: 13px; }
    .radio-opt input { accent-color: var(--accent); }

    select, input[type="number"] {
      background: var(--surface); border: 1px solid var(--border);
      border-radius: 4px; color: var(--text); font-size: 13px;
      padding: 6px 10px; width: 100%; outline: none;
    }
    select:focus, input[type="number"]:focus { border-color: var(--accent); }

    .toggle-row { display: flex; align-items: center; justify-content: space-between; }
    .toggle { position: relative; width: 36px; height: 20px; }
    .toggle input { opacity: 0; width: 0; height: 0; }
    .toggle-slider {
      position: absolute; inset: 0; background: var(--border);
      border-radius: 20px; cursor: pointer; transition: background 0.2s;
    }
    .toggle-slider::before {
      content: ''; position: absolute; width: 14px; height: 14px;
      left: 3px; top: 3px; background: white; border-radius: 50%;
      transition: transform 0.2s;
    }
    .toggle input:checked + .toggle-slider { background: var(--accent); }
    .toggle input:checked + .toggle-slider::before { transform: translateX(16px); }

    .account-box {
      background: var(--surface); border: 1px solid var(--border);
      border-radius: 6px; padding: 12px; margin-bottom: 16px;
    }
    .account-status { font-size: 12px; margin-bottom: 8px; }
    .account-input-row { display: flex; gap: 6px; }
    .account-input-row input {
      flex: 1; background: var(--bg); border: 1px solid var(--border);
      border-radius: 4px; color: var(--text); font-size: 12px;
      padding: 5px 8px; outline: none;
    }
    .account-input-row input:focus { border-color: var(--accent); }

    .btn {
      background: var(--accent); color: #fff; border: none;
      border-radius: 4px; font-size: 12px; font-weight: 600;
      padding: 7px 14px; cursor: pointer; transition: background 0.15s;
    }
    .btn:hover { background: var(--accent-h); }
    .btn-outline {
      background: none; border: 1px solid var(--border);
      color: var(--text-muted);
    }
    .btn-outline:hover { border-color: var(--accent); color: var(--accent); background: none; }

    .divider { border: none; border-top: 1px solid var(--border); margin: 16px 0; }

    #saveStatus { font-size: 12px; color: var(--text-muted); margin-top: 8px; min-height: 18px; }
    .status-ok { color: #00c896; }
    .status-err { color: #f04747; }
  </style>
</head>
<body data-theme="dark">

  <h1>📼 ClipTV Panel Settings</h1>

  <div class="account-box">
    <div class="account-status" id="accountStatus">Checking account link…</div>
    <div class="account-input-row" id="linkRow" style="display:none">
      <input id="loginInput" type="text" placeholder="Your Twitch username" autocomplete="off">
      <button class="btn" id="linkBtn">Link</button>
    </div>
  </div>

  <div class="field">
    <label>Default sort</label>
    <div class="radio-group">
      <label class="radio-opt"><input type="radio" name="sort" value="recent" checked> Recent</label>
      <label class="radio-opt"><input type="radio" name="sort" value="top"> Top</label>
      <label class="radio-opt"><input type="radio" name="sort" value="random"> Random</label>
    </div>
  </div>

  <div class="field">
    <label>Clips shown</label>
    <input type="number" id="clipCount" min="5" max="25" value="10">
  </div>

  <div class="field">
    <div class="toggle-row">
      <span>Autoplay</span>
      <label class="toggle">
        <input type="checkbox" id="autoplay">
        <span class="toggle-slider"></span>
      </label>
    </div>
  </div>

  <div class="field">
    <div class="toggle-row">
      <span>Featured clips</span>
      <label class="toggle">
        <input type="checkbox" id="featured">
        <span class="toggle-slider"></span>
      </label>
    </div>
  </div>

  <hr class="divider">

  <button class="btn" id="saveBtn">Save Settings</button>
  <div id="saveStatus"></div>

  <script src="https://extension-files.twitch.tv/helper/v1/twitch-ext.min.js"></script>
  <script src="common.js"></script>
  <script src="config.js"></script>
</body>
</html>
```

**Step 2: Write `config.js`**

```javascript
// config.js — ClipTV Twitch Panel Extension config view

(function () {
  'use strict';

  let authToken   = null;
  let isLinked    = false;

  const accountStatus = document.getElementById('accountStatus');
  const linkRow       = document.getElementById('linkRow');
  const loginInput    = document.getElementById('loginInput');
  const linkBtn       = document.getElementById('linkBtn');
  const saveBtn       = document.getElementById('saveBtn');
  const saveStatus    = document.getElementById('saveStatus');
  const clipCount     = document.getElementById('clipCount');
  const autoplay      = document.getElementById('autoplay');
  const featured      = document.getElementById('featured');

  // ── Load saved settings into form ─────────────────────────────────────────
  function loadFormValues() {
    const saved = readBroadcasterConfig();
    const s = { ...DEFAULT_SETTINGS, ...saved };

    const sortInput = document.querySelector(`input[name="sort"][value="${s.ext_sort}"]`);
    if (sortInput) sortInput.checked = true;

    clipCount.value = s.ext_clip_count;
    autoplay.checked = !!s.ext_autoplay;
    featured.checked = !!s.ext_featured;
  }

  // ── Fetch account link status ─────────────────────────────────────────────
  async function checkAccountLink() {
    try {
      const data = await extFetch('/extension_api.php?action=channel', authToken);
      if (data.registered) {
        isLinked = true;
        accountStatus.innerHTML = `✅ Linked as <strong>${escHtml(data.display_name || data.login)}</strong>`;
        linkRow.style.display = 'none';
      } else {
        isLinked = false;
        accountStatus.textContent = '⚠️ Not linked — enter your Twitch username to connect your ClipTV library.';
        linkRow.style.display = '';
      }
    } catch (e) {
      accountStatus.textContent = 'Could not check account status.';
    }
  }

  // ── Link account ──────────────────────────────────────────────────────────
  linkBtn.addEventListener('click', async () => {
    const login = loginInput.value.trim().toLowerCase().replace(/[^a-z0-9_]/g, '');
    if (!login) return;

    linkBtn.disabled = true;
    linkBtn.textContent = '…';

    try {
      await extFetch('/extension_api.php?action=settings', authToken, {
        method: 'POST',
        body: JSON.stringify({ login })
      });
      loginInput.value = '';
      await checkAccountLink();
    } catch (e) {
      accountStatus.textContent = '❌ Could not link: ' + (e.message || 'unknown error');
    } finally {
      linkBtn.disabled = false;
      linkBtn.textContent = 'Link';
    }
  });

  // ── Save panel settings ───────────────────────────────────────────────────
  saveBtn.addEventListener('click', () => {
    const sort = document.querySelector('input[name="sort"]:checked')?.value || 'recent';
    const count = Math.max(5, Math.min(25, parseInt(clipCount.value) || 10));

    const settings = {
      ext_sort:       sort,
      ext_clip_count: count,
      ext_autoplay:   autoplay.checked,
      ext_featured:   featured.checked
    };

    writeBroadcasterConfig(settings);
    saveStatus.textContent = '✓ Saved';
    saveStatus.className = 'status-ok';
    setTimeout(() => { saveStatus.textContent = ''; }, 3000);
  });

  // ── Entry points ──────────────────────────────────────────────────────────
  window.Twitch.ext.onAuthorized(auth => {
    authToken = auth.token;
    loadFormValues();
    checkAccountLink();
  });

  window.Twitch.ext.onContext(ctx => {
    if (ctx.theme) applyTheme(ctx.theme);
  });

  window.Twitch.ext.configuration.onChanged(() => {
    loadFormValues();
  });

})();
```

**Step 3: Verify both files exist**

```bash
ls twitch-extension/
```

Expected: `common.js  config.html  config.js  panel.css  panel.html  panel.js`

**Step 4: Commit**

```bash
git add twitch-extension/config.html twitch-extension/config.js
git commit -m "feat(ext): add config.html and config.js for broadcaster settings"
```

---

## Task 11: `twitch-extension/README.md`

**Files:**
- Create: `twitch-extension/README.md`

**Step 1: Write README**

```markdown
# ClipTV Twitch Panel Extension

## Setup

### 1. Register the extension

1. Go to https://dev.twitch.tv/console/extensions and create a new extension.
2. Set **Extension Type** to **Panel**.
3. Set **Panel Height** to `500`.
4. Under **Capabilities**, enable **Configuration Service** (for broadcaster config segment).
5. Copy your **Extension Client ID** and **Base64 Extension Secret**.

### 2. Configure environment

Add to Railway (or your hosting env):
```
TWITCH_EXT_SECRET=<your base64 extension secret from Twitch dev console>
```

### 3. Local Test

1. Run `ngrok http 8000` (or expose your local server another way).
2. Run `php -S localhost:8000` from the clipsystem root.
3. In the Twitch dev console → **Extension Settings** → **Testing Base URI**, set it to your ngrok URL.
4. Enable **Local Test** mode in the developer console.
5. Install the extension on a test channel via **Extension Manager**.

Panel files are served from `twitch-extension/` relative to the Testing Base URI.

### 4. Hosted Test

1. Zip the contents of `twitch-extension/` (not the folder itself — the files directly).
2. Upload the zip in the Twitch dev console under **Files**.
3. Switch to **Hosted Test** mode.

### 5. Backend

All API calls go to `https://clips.gmgnrepeat.com/extension_api.php`.
CORS is open. JWT verification uses `TWITCH_EXT_SECRET`.

## Linking a channel

A broadcaster who installs the extension must link their ClipTV account in the config view:
1. Open Extension Manager → ClipTV → Configure.
2. Enter their Twitch username.
3. Click Link.

This writes `twitch_id` → `login` into `channel_settings` in the DB.

## Files

| File | Purpose |
|---|---|
| `panel.html` | Panel view shell |
| `panel.js` | Panel logic: tabs, clip cards, search |
| `panel.css` | Styles (light + dark theme) |
| `config.html` | Broadcaster config view |
| `config.js` | Config logic: settings form, account link |
| `common.js` | Shared helpers: theme, fetch, formatting |
```

**Step 2: Commit**

```bash
git add twitch-extension/README.md
git commit -m "docs(ext): add twitch-extension README with setup instructions"
```

---

## Task 12: Smoke Test Checklist

Before calling this done, verify each of these manually in Twitch Local Test:

**Backend:**
- [ ] `php -l extension_api.php` returns no errors
- [ ] Hitting `?action=channel` with no auth returns 401
- [ ] Hitting `?action=clips&sort=recent` with no auth returns 401
- [ ] DB migration ran: `twitch_id` column exists on `channel_settings` (check via Railway DB console or psql)

**Extension front end (Local Test):**
- [ ] Panel loads without JS errors in browser console
- [ ] Loading spinner shows briefly, then either shell or unregistered state appears
- [ ] Tabs switch between Recent / Top / Random and load clips
- [ ] Search icon shows search bar, typing fetches results
- [ ] Clicking a clip opens `clips.twitch.tv/...` in a new tab
- [ ] Footer "Open full ClipTV" link goes to the correct channel URL
- [ ] Dark mode renders correctly (default)
- [ ] Light mode renders correctly (toggle via Twitch dev console context simulator)
- [ ] Config view shows sort options, clip count, toggles, save button
- [ ] Saving settings persists across panel reload (Twitch config segment)
- [ ] Account link flow: entering a valid login links it; invalid login shows error

**Step 1: Run the checklist above. Fix anything that fails before declaring v1 done.**

**Step 2: Final commit if any small fixes made**

```bash
git add -A
git commit -m "fix(ext): smoke test fixes"
```

---

## What's Not in This Plan (Intentionally)

- In-panel clip playback (v2)
- Viewer voting from extension (v2)
- Mobile or video overlay views (v2)
- Auto-registration flow for unlinked streamers (v2)
- Twitch Review submission steps (separate process after Hosted Test passes)
```
