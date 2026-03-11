// panel.js — ClipTV Twitch Panel Extension

(function () {
  'use strict';

  // ── Twitch GQL constants (same as main ClipTV player) ─────────────────────
  const GQL_URL    = 'https://gql.twitch.tv/gql';
  const CLIENT_ID  = 'kimne78kx3ncx6brgo4mv6wki5h1ko';
  const QUERY_HASH = '36b89d2507fce29e5ca551df756d27c1cfe079e2609642b4390aa4c35796eb11';

  // ── State ──────────────────────────────────────────────────────────────────
  let authToken      = null;
  let channelInfo    = null;
  let settings       = { ...DEFAULT_SETTINGS };
  let currentSort    = 'recent';
  let searchTimer    = null;
  let initInProgress = false;
  let playlist       = [];
  let currentIndex   = -1;
  let playerActive   = false;  // true once a clip has been played; prevents auto-play on list refresh
  let currentClipUrl = null;   // clip_url of the currently loaded clip (for copy button)

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
  const searchSort        = document.getElementById('searchSort');
  const searchDuration    = document.getElementById('searchDuration');
  const searchGame        = document.getElementById('searchGame');
  const searchClipper     = document.getElementById('searchClipper');
  const fullSiteBtn       = document.getElementById('fullSiteBtn');
  const playerEl          = document.getElementById('player');
  const clipVideo         = document.getElementById('clipVideo');
  const playerOverlay     = document.getElementById('playerOverlay');
  const playBtn           = document.getElementById('playBtn');
  const playerTitle       = document.getElementById('playerTitle');
  const playerMeta        = document.getElementById('playerMeta');
  const noVideoState      = document.getElementById('noVideoState');
  const noVideoThumb      = document.getElementById('noVideoThumb');
  const watchOnTwitchBtn  = document.getElementById('watchOnTwitchBtn');
  const copyUrlBtn        = document.getElementById('copyUrlBtn');

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

  // ── Copy clip URL to clipboard ─────────────────────────────────────────────
  copyUrlBtn.addEventListener('click', () => {
    if (!currentClipUrl) return;
    const ta = document.createElement('textarea');
    ta.value = currentClipUrl;
    ta.style.cssText = 'position:fixed;top:0;left:0;opacity:0;pointer-events:none';
    document.body.appendChild(ta);
    ta.focus();
    ta.select();
    document.execCommand('copy');
    document.body.removeChild(ta);
    copyUrlBtn.textContent = '\u2713';
    copyUrlBtn.classList.add('copied');
    setTimeout(() => {
      copyUrlBtn.textContent = '\u29C9';
      copyUrlBtn.classList.remove('copied');
    }, 2000);
  });

  function hideUrlReveal() {
    copyUrlBtn.classList.remove('copied');
    copyUrlBtn.textContent = '\u29C9';
  }

  // ── Fetch signed Twitch clip URL via GQL (same method as main ClipTV player)
  async function getTwitchMp4Url(clipId) {
    try {
      const res = await fetch(GQL_URL, {
        method: 'POST',
        headers: { 'Client-ID': CLIENT_ID, 'Content-Type': 'application/json' },
        body: JSON.stringify({
          operationName: 'VideoAccessToken_Clip',
          variables: { slug: clipId },
          extensions: { persistedQuery: { version: 1, sha256Hash: QUERY_HASH } }
        })
      });
      if (!res.ok) return null;
      const data = await res.json();
      const clip = data?.data?.clip;
      if (!clip) return null;
      const token    = clip.playbackAccessToken?.value;
      const sig      = clip.playbackAccessToken?.signature;
      const qualities = clip.videoQualities || [];
      if (!token || !sig || !qualities.length) return null;
      const best = qualities.sort((a, b) => (parseInt(b.quality) || 0) - (parseInt(a.quality) || 0))[0];
      const sep  = best.sourceURL.includes('?') ? '&' : '?';
      return `${best.sourceURL}${sep}sig=${encodeURIComponent(sig)}&token=${encodeURIComponent(token)}`;
    } catch (e) {
      return null;
    }
  }

  // ── Video player ───────────────────────────────────────────────────────────
  async function playClip(index) {
    if (!playlist.length) return;
    const clip = playlist[index];
    if (!clip) return;

    currentIndex = index;
    playerActive = true;
    currentClipUrl = clip.clip_url || null;
    hideUrlReveal();

    // Update info immediately
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

    // Kick clips or pre-stored URLs play directly
    if (clip.video_url) {
      noVideoState.style.display = 'none';
      clipVideo.src = clip.video_url;
      clipVideo.play().catch(() => {
        // Autoplay blocked — show overlay so user knows to click
        playBtn.textContent = '\u25B6';
        playerEl.classList.add('awaiting-play');
      });
      playBtn.textContent = '\u23F8';
      return;
    }

    // Show thumbnail as poster while fetching — hide Watch on Twitch until we know it's needed
    noVideoThumb.src = clip.thumbnail_url || '';
    watchOnTwitchBtn.href = clip.clip_url || '#';
    watchOnTwitchBtn.style.visibility = 'hidden';
    noVideoState.style.display = '';
    clipVideo.removeAttribute('src');
    clipVideo.load();
    playBtn.textContent = '\u25B6';
    playerEl.classList.add('awaiting-play');

    if (!clip.id) {
      watchOnTwitchBtn.style.visibility = '';
      return;
    }
    const url = await getTwitchMp4Url(clip.id);

    // Bail if the user switched clips while we were fetching
    if (currentIndex !== index) return;

    if (url) {
      noVideoState.style.display = 'none';
      watchOnTwitchBtn.style.visibility = '';
      clipVideo.src = url;
      clipVideo.play().catch(() => {
        playBtn.textContent = '\u25B6';
        playerEl.classList.add('awaiting-play');
      });
      playBtn.textContent = '\u23F8';
    } else {
      // GQL failed — reveal Watch on Twitch fallback
      watchOnTwitchBtn.style.visibility = '';
    }
  }

  function playNext() {
    if (!playlist.length) return;
    playClip((currentIndex + 1) % playlist.length);
  }

  clipVideo.addEventListener('ended', playNext);

  playerOverlay.addEventListener('click', () => {
    if (clipVideo.paused) {
      clipVideo.play().catch(() => {});
      playBtn.textContent = '\u23F8';
    } else {
      clipVideo.pause();
      playBtn.textContent = '\u25B6';
    }
  });

  clipVideo.addEventListener('play',  () => {
    playBtn.textContent = '\u23F8';
    playerEl.classList.remove('awaiting-play');
  });
  clipVideo.addEventListener('pause', () => { playBtn.textContent = '\u25B6'; });

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

    // Auto-play first clip only on initial load — don't interrupt if already playing
    if (!playerActive) {
      playClip(0);
    }
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

  // ── Load games for search filter dropdown ──────────────────────────────────
  async function loadGames() {
    try {
      const data = await extFetch(apiUrl('/extension_api.php?action=games'), authToken);
      const games = data.games || [];
      searchGame.innerHTML = '<option value="">All games</option>';
      games.forEach(g => {
        if (!g.game_id || !g.name) return;
        const opt = document.createElement('option');
        opt.value = g.game_id;
        opt.textContent = g.name;
        searchGame.appendChild(opt);
      });
      searchGame.style.display = games.length ? '' : 'none';
    } catch (e) {
      searchGame.style.display = 'none';
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
      searchSort.value = 'views';
      searchDuration.value = '';
      searchGame.value = '';
      searchGame.style.display = '';
      searchClipper.value = '';
      searchInput.focus();
      showSearchPrompt();
      loadGames();
      return;
    }

    tabBar.querySelectorAll('.ext-tab').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');
    currentSort = sort;
    loadClips(sort);
  });

  // ── Search ─────────────────────────────────────────────────────────────────

  // Returns true if enough input is present to run a filtered query
  function hasSearchInput() {
    const q       = searchInput.value.trim();
    const gameId  = searchGame.value;
    const dur     = searchDuration.value;
    const clipper = searchClipper.value.trim();
    return q.length >= 2 || gameId !== '' || dur !== '' || clipper.length >= 2;
  }

  function showSearchPrompt() {
    clipList.innerHTML = `<div class="ext-status" style="height:auto;padding:20px">
      <div class="ext-status-body" style="color:var(--text-muted)">Type to search or pick a category</div>
    </div>`;
  }

  searchInput.addEventListener('input', () => {
    clearTimeout(searchTimer);
    const q = searchInput.value.trim();
    if (q.length === 1 && !searchGame.value && !searchDuration.value) {
      clipList.innerHTML = ''; return;
    }
    if (!hasSearchInput()) { showSearchPrompt(); return; }
    searchTimer = setTimeout(() => runSearch(), 400);
  });

  searchInput.addEventListener('keydown', e => {
    if (e.key === 'Enter') {
      clearTimeout(searchTimer);
      if (hasSearchInput()) runSearch();
    }
  });

  searchSort.addEventListener('change', () => {
    if (hasSearchInput()) runSearch();
  });

  searchDuration.addEventListener('change', () => {
    if (hasSearchInput()) runSearch();
    else if (!searchInput.value.trim() && !searchGame.value) showSearchPrompt();
  });

  searchGame.addEventListener('change', () => {
    if (hasSearchInput()) runSearch();
    else if (!searchInput.value.trim() && !searchDuration.value) showSearchPrompt();
  });

  searchClipper.addEventListener('input', () => {
    clearTimeout(searchTimer);
    if (!hasSearchInput()) { showSearchPrompt(); return; }
    searchTimer = setTimeout(() => runSearch(), 500);
  });

  searchClipper.addEventListener('keydown', e => {
    if (e.key === 'Enter') {
      clearTimeout(searchTimer);
      if (hasSearchInput()) runSearch();
    }
  });

  searchClose.addEventListener('click', () => {
    searchBar.classList.remove('visible');
    tabBar.style.display = '';
    const activeTab = tabBar.querySelector(`.ext-tab[data-sort="${currentSort}"]`);
    if (activeTab) activeTab.classList.add('active');
    loadClips(currentSort);
  });

  async function runSearch() {
    const q        = searchInput.value.trim();
    const sort     = searchSort.value || 'views';
    const duration = searchDuration.value;
    const gameId   = searchGame.value;
    const clipper  = searchClipper.value.trim();

    let url = `/extension_api.php?action=search&sort=${encodeURIComponent(sort)}`;
    if (q.length >= 2)      url += `&q=${encodeURIComponent(q)}`;
    if (duration)           url += `&duration=${encodeURIComponent(duration)}`;
    if (gameId)             url += `&game_id=${encodeURIComponent(gameId)}`;
    if (clipper.length >= 2) url += `&clipper=${encodeURIComponent(clipper)}`;

    clipList.innerHTML = `<div class="ext-status" style="height:auto;padding:20px"><div class="ext-spinner"></div></div>`;
    try {
      const data = await extFetch(apiUrl(url), authToken);
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
    playerActive = false;
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

      // Reset search state in case init() re-ran while search was open
      tabBar.style.display = '';
      searchBar.classList.remove('visible');

      showShell();
      loadClips(currentSort);

    } catch (e) {
      showError(e.message || 'Couldn\u2019t connect to ClipTV.');
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
    } else if (authToken) {
      // Config loaded after onAuthorized — retry init with correct settings
      init();
    }
  });

})();
