# ClipTV (clipsystem) — Project Context

This is **ClipTV**, the streamer clip ecosystem.

## Brain Link
- **Node:** [nodes/cliptv/](~/gmgn-brain/nodes/cliptv/)
- **Brain root:** ~/gmgn-brain/

## Key Facts
- PHP + JavaScript, Twitch API, Discord API
- GitHub: SRHSoulja/clipsystem (public)
- Deployed on Railway (auto-deploys from GitHub)
- Components: Discord activity app, web clip TV, streamer dashboard, OBS BRB archive, Twitch bot
- Owner: Arson (Eric on Windows, arson on WSL)

## On Session Start

1. Run `~/2026Projects/tools/bin/brain-resume` to generate a fresh briefing
2. Read `~/gmgn-brain/brain/index/session-resume.md`
3. Check `~/gmgn-brain/nodes/cliptv/` for this project's brain node

## Rules
- No secrets in code or docs — use env vars
- Check the brain node for context before architectural decisions

## Endpoint Metrics

Lightweight request instrumentation is active on 5 high-traffic endpoints.

**Instrumented endpoints:** sync_state.php, sync_state_heartbeat.php, cliptv_viewers.php, cliptv_chat.php, cliptv_request.php

**How it works:** `includes/metrics.php` is included at the top of each endpoint. A shutdown function records request count + latency per endpoint:method into hourly JSON bucket files at `cache/metrics/YYYY-MM-DD-HH.json`. Auto-purges after 72 hours.

**Reading the data:**
- **Web:** `metrics_report.php` (text table) or `metrics_report.php?format=json`
- **Adjust window:** `metrics_report.php?hours=6` (default: 24h, max: 72h)
- **Raw files:** `cache/metrics/*.json` — one file per hour, keys are `endpoint:METHOD`
- **On Railway:** `https://clips.gmgnrepeat.com/metrics_report.php`
