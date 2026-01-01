<?php
/**
 * nav.php - Global navigation bar
 *
 * Include this at the top of every page for consistent navigation.
 * Requires twitch_oauth.php to be included first for getCurrentUser().
 *
 * Usage:
 *   require_once __DIR__ . '/includes/twitch_oauth.php';
 *   $currentUser = getCurrentUser();
 *   require_once __DIR__ . '/includes/nav.php';
 */

// Get user's accessible channels for dropdown (cached in session)
function getNavUserChannels($pdo, $currentUser) {
    if (!$currentUser || !$pdo) return [];

    $userLogin = strtolower($currentUser['login']);
    $channels = [];

    // Check if user is an archived streamer
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as clip_count FROM clips WHERE login = ?");
        $stmt->execute([$userLogin]);
        $result = $stmt->fetch();
        if ($result && $result['clip_count'] > 0) {
            $channels[] = [
                'login' => $userLogin,
                'role' => 'streamer',
                'clip_count' => (int)$result['clip_count']
            ];
        }
    } catch (PDOException $e) {}

    // Get channels where user is a mod
    try {
        $stmt = $pdo->prepare("
            SELECT cm.channel_login,
                   (SELECT COUNT(*) FROM clips WHERE login = cm.channel_login) as clip_count
            FROM channel_mods cm
            WHERE cm.mod_username = ?
            ORDER BY cm.channel_login
        ");
        $stmt->execute([$userLogin]);
        while ($row = $stmt->fetch()) {
            if ($row['channel_login'] !== $userLogin) {
                $channels[] = [
                    'login' => $row['channel_login'],
                    'role' => 'mod',
                    'clip_count' => (int)$row['clip_count']
                ];
            }
        }
    } catch (PDOException $e) {}

    return $channels;
}

// Determine nav context
$navUser = $currentUser ?? null;
$navPdo = $pdo ?? null;
if (!$navPdo) {
    require_once __DIR__ . '/../db_config.php';
    $navPdo = get_db_connection();
}

$navChannels = [];
$navIsSuperAdmin = false;
$navIsArchivedStreamer = false;
$navHasAccess = false;

if ($navUser) {
    $navIsSuperAdmin = isSuperAdmin();
    $navChannels = getNavUserChannels($navPdo, $navUser);
    $navIsArchivedStreamer = !empty(array_filter($navChannels, fn($c) => $c['role'] === 'streamer'));
    $navHasAccess = $navIsSuperAdmin || count($navChannels) > 0;
}

// Get current page for active state
$navCurrentPage = basename($_SERVER['PHP_SELF'], '.php');
$navCurrentPath = $_SERVER['REQUEST_URI'] ?? '/';
?>
<nav class="global-nav">
    <div class="nav-container">
        <div class="nav-left">
            <a href="/" class="nav-brand">
                <span class="nav-logo">📺</span>
                <span class="nav-title">ClipArchive</span>
            </a>
            <div class="nav-links">
                <a href="/" class="nav-link <?= $navCurrentPage === 'index' ? 'active' : '' ?>">Home</a>
                <?php if ($navHasAccess): ?>
                <a href="/channels" class="nav-link <?= $navCurrentPage === 'my_channels' ? 'active' : '' ?>">Dashboard</a>
                <?php endif; ?>
                <?php if ($navIsSuperAdmin): ?>
                <a href="/admin.php" class="nav-link <?= $navCurrentPage === 'admin' ? 'active' : '' ?>">Admin</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="nav-right">
            <?php if ($navUser): ?>
            <div class="nav-user-dropdown">
                <button class="nav-user-btn" id="navUserBtn">
                    <?php if (!empty($navUser['profile_image_url'])): ?>
                    <img src="<?= htmlspecialchars($navUser['profile_image_url']) ?>" alt="" class="nav-user-avatar">
                    <?php else: ?>
                    <div class="nav-user-avatar nav-user-avatar-placeholder"><?= strtoupper(substr($navUser['display_name'] ?? $navUser['login'], 0, 1)) ?></div>
                    <?php endif; ?>
                    <span class="nav-user-name"><?= htmlspecialchars($navUser['display_name'] ?? $navUser['login']) ?></span>
                    <svg class="nav-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>
                </button>
                <div class="nav-dropdown-menu" id="navDropdownMenu">
                    <?php if ($navIsSuperAdmin): ?>
                    <div class="nav-dropdown-label">Super Admin</div>
                    <a href="/channels" class="nav-dropdown-item">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg>
                        Dashboard Hub
                    </a>
                    <a href="/admin.php" class="nav-dropdown-item">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 15a3 3 0 100-6 3 3 0 000 6z"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-2 2 2 2 0 01-2-2v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06a1.65 1.65 0 00.33-1.82 1.65 1.65 0 00-1.51-1H3a2 2 0 01-2-2 2 2 0 012-2h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 010-2.83 2 2 0 012.83 0l.06.06a1.65 1.65 0 001.82.33H9a1.65 1.65 0 001-1.51V3a2 2 0 012-2 2 2 0 012 2v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 0 2 2 0 010 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H21a2 2 0 012 2 2 2 0 01-2 2h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
                        Admin Panel
                    </a>
                    <div class="nav-dropdown-divider"></div>
                    <?php endif; ?>

                    <?php if ($navIsArchivedStreamer): ?>
                    <div class="nav-dropdown-label">Your Channel</div>
                    <?php foreach ($navChannels as $ch): ?>
                    <?php if ($ch['role'] === 'streamer'): ?>
                    <a href="/dashboard/<?= urlencode($ch['login']) ?>" class="nav-dropdown-item">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 016.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z"/></svg>
                        Streamer Dashboard
                    </a>
                    <a href="/mod/<?= urlencode($ch['login']) ?>" class="nav-dropdown-item">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/></svg>
                        Playlist Manager
                    </a>
                    <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if (count(array_filter($navChannels, fn($c) => $c['role'] === 'mod')) > 0): ?>
                    <div class="nav-dropdown-divider"></div>
                    <?php endif; ?>
                    <?php endif; ?>

                    <?php
                    $modChannels = array_filter($navChannels, fn($c) => $c['role'] === 'mod');
                    if (count($modChannels) > 0):
                    ?>
                    <div class="nav-dropdown-label">Channels You Moderate</div>
                    <?php foreach ($modChannels as $ch): ?>
                    <a href="/mod/<?= urlencode($ch['login']) ?>" class="nav-dropdown-item">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
                        <?= htmlspecialchars($ch['login']) ?>
                        <span class="nav-dropdown-meta"><?= number_format($ch['clip_count']) ?> clips</span>
                    </a>
                    <?php endforeach; ?>
                    <div class="nav-dropdown-divider"></div>
                    <?php endif; ?>

                    <a href="/auth/logout.php?return=<?= urlencode($navCurrentPath) ?>" class="nav-dropdown-item nav-dropdown-logout">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                        Logout
                    </a>
                </div>
            </div>
            <?php else: ?>
            <a href="/auth/login.php?return=<?= urlencode($navCurrentPath) ?>" class="nav-login-btn">
                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M11.64 5.93h1.43v4.28h-1.43m3.93-4.28H17v4.28h-1.43M7 2L3.43 5.57v12.86h4.28V22l3.58-3.57h2.85L20.57 12V2m-1.43 9.29l-2.85 2.85h-2.86l-2.5 2.5v-2.5H7.71V3.43h11.43z"/></svg>
                Login with Twitch
            </a>
            <?php endif; ?>
        </div>

        <button class="nav-mobile-toggle" id="navMobileToggle">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        </button>
    </div>
</nav>

<style>
/* Global Navigation Styles */
.global-nav {
    position: sticky;
    top: 0;
    z-index: 1000;
    background: #18181b;
    border-bottom: 1px solid #2f2f35;
    height: 56px;
}

.nav-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 0 16px;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.nav-left {
    display: flex;
    align-items: center;
    gap: 32px;
}

.nav-brand {
    display: flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
    color: #efeff1;
    font-weight: 700;
    font-size: 18px;
}

.nav-logo {
    font-size: 24px;
}

.nav-title {
    background: linear-gradient(90deg, #9147ff, #bf94ff);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.nav-links {
    display: flex;
    gap: 4px;
}

.nav-link {
    padding: 8px 16px;
    color: #adadb8;
    text-decoration: none;
    font-size: 14px;
    font-weight: 500;
    border-radius: 6px;
    transition: all 0.15s ease;
}

.nav-link:hover {
    color: #efeff1;
    background: rgba(255, 255, 255, 0.08);
}

.nav-link.active {
    color: #efeff1;
    background: rgba(145, 71, 255, 0.2);
}

.nav-right {
    display: flex;
    align-items: center;
    gap: 12px;
}

/* Login Button */
.nav-login-btn {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    background: #9147ff;
    color: white;
    text-decoration: none;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 600;
    transition: background 0.15s ease;
}

.nav-login-btn:hover {
    background: #772ce8;
}

.nav-login-btn svg {
    width: 18px;
    height: 18px;
}

/* User Dropdown */
.nav-user-dropdown {
    position: relative;
}

.nav-user-btn {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 4px 12px 4px 4px;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid #3d3d42;
    border-radius: 6px;
    color: #efeff1;
    cursor: pointer;
    transition: all 0.15s ease;
}

.nav-user-btn:hover {
    background: rgba(255, 255, 255, 0.1);
    border-color: #9147ff;
}

.nav-user-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    object-fit: cover;
}

.nav-user-avatar-placeholder {
    display: flex;
    align-items: center;
    justify-content: center;
    background: #9147ff;
    color: white;
    font-weight: 600;
    font-size: 14px;
}

.nav-user-name {
    font-size: 14px;
    font-weight: 500;
    max-width: 120px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.nav-chevron {
    width: 16px;
    height: 16px;
    color: #adadb8;
    transition: transform 0.15s ease;
}

.nav-user-dropdown.open .nav-chevron {
    transform: rotate(180deg);
}

/* Dropdown Menu */
.nav-dropdown-menu {
    position: absolute;
    top: calc(100% + 8px);
    right: 0;
    min-width: 240px;
    background: #1f1f23;
    border: 1px solid #3d3d42;
    border-radius: 8px;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4);
    opacity: 0;
    visibility: hidden;
    transform: translateY(-8px);
    transition: all 0.15s ease;
    overflow: hidden;
}

.nav-user-dropdown.open .nav-dropdown-menu {
    opacity: 1;
    visibility: visible;
    transform: translateY(0);
}

.nav-dropdown-label {
    padding: 8px 16px 4px;
    font-size: 11px;
    font-weight: 600;
    color: #adadb8;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.nav-dropdown-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 16px;
    color: #efeff1;
    text-decoration: none;
    font-size: 14px;
    transition: background 0.1s ease;
}

.nav-dropdown-item:hover {
    background: rgba(145, 71, 255, 0.15);
}

.nav-dropdown-item svg {
    width: 16px;
    height: 16px;
    color: #adadb8;
    flex-shrink: 0;
}

.nav-dropdown-meta {
    margin-left: auto;
    font-size: 12px;
    color: #adadb8;
}

.nav-dropdown-divider {
    height: 1px;
    background: #3d3d42;
    margin: 4px 0;
}

.nav-dropdown-logout:hover {
    background: rgba(255, 71, 87, 0.15);
}

.nav-dropdown-logout:hover svg {
    color: #ff4757;
}

/* Mobile Toggle */
.nav-mobile-toggle {
    display: none;
    padding: 8px;
    background: none;
    border: none;
    color: #adadb8;
    cursor: pointer;
}

.nav-mobile-toggle svg {
    width: 24px;
    height: 24px;
}

/* Mobile Styles */
@media (max-width: 768px) {
    .nav-links {
        display: none;
    }

    .nav-user-name {
        display: none;
    }

    .nav-user-btn {
        padding: 4px;
    }

    .nav-chevron {
        display: none;
    }

    .nav-mobile-toggle {
        display: block;
    }

    .nav-login-btn span {
        display: none;
    }

    .nav-login-btn {
        padding: 8px 12px;
    }
}

/* Spacing for page content */
.global-nav + * {
    /* Content starts after nav */
}
</style>

<script>
(function() {
    // User dropdown toggle
    const userBtn = document.getElementById('navUserBtn');
    const dropdown = document.querySelector('.nav-user-dropdown');

    if (userBtn && dropdown) {
        userBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            dropdown.classList.toggle('open');
        });

        document.addEventListener('click', (e) => {
            if (!dropdown.contains(e.target)) {
                dropdown.classList.remove('open');
            }
        });

        // Close on escape
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                dropdown.classList.remove('open');
            }
        });
    }

    // Mobile toggle (for future mobile menu implementation)
    const mobileToggle = document.getElementById('navMobileToggle');
    if (mobileToggle) {
        mobileToggle.addEventListener('click', () => {
            // Mobile menu implementation
            if (dropdown) {
                dropdown.classList.toggle('open');
            }
        });
    }
})();
</script>
