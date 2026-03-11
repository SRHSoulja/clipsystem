// config.js — ClipTV Twitch Panel Extension config view

(function () {
  'use strict';

  let authToken = null;

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
        accountStatus.innerHTML = `\u2705 Linked as <strong>${escHtml(data.display_name || data.login)}</strong>`;
        linkRow.style.display = 'none';
      } else {
        accountStatus.textContent = '\u26a0\ufe0f Not linked \u2014 enter your Twitch username to connect your ClipTV library.';
        linkRow.style.display = '';
      }
    } catch (e) {
      accountStatus.textContent = 'Could not check account status.';
      linkRow.style.display = '';
    }
  }

  // ── Link account ──────────────────────────────────────────────────────────
  linkBtn.addEventListener('click', async () => {
    const login = loginInput.value.trim().toLowerCase().replace(/[^a-z0-9_]/g, '');
    if (!login) return;

    linkBtn.disabled = true;
    linkBtn.textContent = '\u2026';

    try {
      await extFetch('/extension_api.php?action=settings', authToken, {
        method: 'POST',
        body: JSON.stringify({ login })
      });
      loginInput.value = '';
      await checkAccountLink();
    } catch (e) {
      accountStatus.textContent = '\u274c Could not link: ' + (e.message || 'unknown error');
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
    saveStatus.textContent = '\u2713 Saved';
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
