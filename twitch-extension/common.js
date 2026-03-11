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
