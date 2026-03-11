// panel.js — ClipTV Twitch Panel Extension

(function () {
  'use strict';

  // ── State ──────────────────────────────────────────────────────────────────
  let authToken      = null;
  let channelInfo    = null;
  let settings       = { ...DEFAULT_SETTINGS };
  let currentSort    = 'recent';
  let searchTimer    = null;
  let initInProgress = false;
  let playlist       = [];
  let currentIndex   = -1;

  // ── Elements ───────────────────────────────────────────────────────────────
  const shell             = document.getElementById('shell');
  const loadingState      = document.getElementById('loadingState');
  const unregisteredState = document.getElementById('unregisteredState');
  const errorState        = document.getElementById('errorState');
  const errorMsg          = document.getElementById('errorMsg');
  const retryBtn          = document.getElementById('retryBtn');
  const channelName       = document.getElementById('channelName');
  const clipList          = document.getElementById('clipList');
  const tabBar            = document.getElementById('tabBar');
  const searchBar         = document.getElementById('searchBar');
  const searchInput       = document.getElementById('searchInput');
  const searchClose       = document.getElementById('searchClose');
  const fullSiteBtn       = document.getElementById('fullSiteBtn');
  const clipVideo         = document.getElementById('clipVideo');
  const playerOverlay     = document.getElementById('playerOverlay');
  const playBtn           = document.getElementById('playBtn');
  const playerTitle       = document.getElementById('playerTitle');
  const playerMeta        = document.getElementById('playerMeta');

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

  // ── Video player ───────────────────────────────────────────────────────────
  function playClip(index) {
    if (!playlist.length) return;
    const clip = playlist[index];
    if (!clip) return;

    currentIndex = index;

    // Update video source
    if (clip.video_url) {
      clipVideo.src = clip.video_url;
      clipVideo.play().catch(() => {});
      playBtn.textContent = '⏸';
    } else {
      clipVideo.removeAttribute('src');
      clipVideo.load();
      playBtn.textContent = '▶';
    }

    // Update info
    playerTitle.textContent = clip.title || 'Untitled';
    const meta = [
      clip.creator_name || null,
      clip.duration ? formatDuration(clip.duration) : null,
      clip.view_count ? formatViews(clip.view_count) + ' views' : null
    ].filter(Boolean).join(' · ');
    playerMeta.textContent = meta;

    // Highlight active card
    clipList.querySelectorAll('.clip-card').forEach((card, i) => {
      card.classList.toggle('active', i === index);
    });
  }

  function playNext() {
    if (!playlist.length) return;
    playClip((currentIndex + 1) % playlist.length);
  }

  clipVideo.addEventListener('ended', playNext);

  playerOverlay.addEventListener('click', () => {
    if (clipVideo.paused) {
      clipVideo.play().catch(() => {});
      playBtn.textContent = '⏸';
    } else {
      clipVideo.pause();
      playBtn.textContent = '▶';
    }
  });

  clipVideo.addEventListener('play',  () => { playBtn.textContent = '⏸'; });
  clipVideo.addEventListener('pause', () => { playBtn.textContent = '▶'; });

  // ── Render clip queue ──────────────────────────────────────────────────────
  function renderClips(clips) {
    playlist = clips || [];
    currentIndex = -1;

    if (!playlist.length) {
      clipList.innerHTML = `
        <div class="ext-status" style="height:auto;padding:30px 20px">
          <div class="ext-status-icon">🎬</div>
          <div class="ext-status-body">No clips found</div>
        </div>`;
      playerTitle.textContent = '';
      playerMeta.textContent = '';
      clipVideo.removeAttribute('src');
      return;
    }

    clipList.innerHTML = playlist.map((clip, i) => {
      const thumb = clip.thumbnail_url
        ? `<img class="clip-thumb" src="${escHtml(clip.thumbnail_url)}" alt="" loading="lazy">`
        : `<div class="clip-thumb-placeholder">🎬</div>`;

      const meta = [
        clip.creator_name ? escHtml(clip.creator_name) : null,
        clip.duration     ? formatDuration(clip.duration) : null,
        clip.view_count   ? formatViews(clip.view_count) + ' views' : null
      ].filter(Boolean).join(' · ');

      return `<button class="clip-card" data-index="${i}">
        ${thumb}
        <div class="clip-info">
          <div class="clip-title">${escHtml(clip.title || 'Untitled')}</div>
          <div class="clip-meta">${meta}</div>
        </div>
      </button>`;
    }).join('');

    clipList.querySelectorAll('.clip-card').forEach(card => {
      card.addEventListener('click', () => {
        playClip(parseInt(card.dataset.index, 10));
      });
    });

    // Auto-play first clip
    playClip(0);
  }

  // ── Build API URL with optional preview_login ──────────────────────────────
  function apiUrl(base) {
    const preview = settings.ext_preview_login || '';
    return preview ? `${base}&preview_login=${encodeURIComponent(preview)}` : base;
  }

  // ── Fetch and render clips ─────────────────────────────────────────────────
  async function loadClips(sort) {
    clipList.innerHTML = `<div class="ext-status" style="height:auto;padding:20px"><div class="ext-spinner"></div></div>`;
    try {
      const data = await extFetch(
        apiUrl(`/extension_api.php?action=clips&sort=${encodeURIComponent(sort)}&limit=20`),
        authToken
      );
      renderClips(data.clips);
    } catch (e) {
      clipList.innerHTML = `<div class="ext-status" style="height:auto;padding:20px">
        <div class="ext-status-body">Couldn\u2019t load clips. <a href="#" id="inlineRetry" style="color:var(--accent)">Retry</a></div>
      </div>`;
      document.getElementById('inlineRetry')?.addEventListener('click', ev => {
        ev.preventDefault();
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
      tabBar.style.display = 'none';
      searchBar.classList.add('visible');
      searchInput.value = '';
      searchInput.focus();
      clipList.innerHTML = '';
      return;
    }

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
    const activeTab = tabBar.querySelector(`.ext-tab[data-sort="${currentSort}"]`);
    if (activeTab) activeTab.classList.add('active');
    loadClips(currentSort);
  });

  async function runSearch(q) {
    clipList.innerHTML = `<div class="ext-status" style="height:auto;padding:20px"><div class="ext-spinner"></div></div>`;
    try {
      const data = await extFetch(
        apiUrl(`/extension_api.php?action=search&q=${encodeURIComponent(q)}`),
        authToken
      );
      renderClips(data.clips);
    } catch (e) {
      clipList.innerHTML = `<div class="ext-status" style="height:auto;padding:20px"><div class="ext-status-body">Search failed</div></div>`;
    }
  }

  // ── Retry ──────────────────────────────────────────────────────────────────
  retryBtn.addEventListener('click', () => init());

  // ── Initialise ─────────────────────────────────────────────────────────────
  async function init() {
    if (initInProgress) return;
    initInProgress = true;
    showLoading();

    const saved = readBroadcasterConfig();
    settings = { ...DEFAULT_SETTINGS, ...saved };
    currentSort = settings.ext_sort || 'recent';

    try {
      const data = await extFetch(
        apiUrl('/extension_api.php?action=channel'),
        authToken
      );

      if (!data.registered) {
        showUnregistered();
        return;
      }

      channelInfo = data;
      channelName.textContent = (data.display_name || data.login) + '\u2019s clips';
      fullSiteBtn.href = `https://clips.gmgnrepeat.com/tv/${encodeURIComponent(data.login)}`;

      tabBar.querySelectorAll('.ext-tab').forEach(t => {
        t.classList.toggle('active', t.dataset.sort === currentSort);
      });

      showShell();
      loadClips(currentSort);

    } catch (e) {
      showError('Couldn\u2019t connect to ClipTV. Check back soon.');
    } finally {
      initInProgress = false;
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

  window.Twitch.ext.configuration.onChanged(() => {
    const saved = readBroadcasterConfig();
    settings = { ...DEFAULT_SETTINGS, ...saved };
    if (channelInfo) {
      currentSort = settings.ext_sort || currentSort;
      loadClips(currentSort);
    }
  });

})();
