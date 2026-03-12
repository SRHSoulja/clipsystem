# ClipTV — Full Product Reference

**Version:** March 2026
**Website:** clips.gmgnrepeat.com
**Made by:** GMGNRepeat

---

## What is ClipTV?

ClipTV is a full clip management and community viewing platform built for Twitch streamers and their communities. It automatically collects, organizes, and surfaces every clip ever made on a Twitch channel, and makes those clips available everywhere a community hangs out — on the web, on Twitch, and inside Discord.

ClipTV is free to use right now.

---

## Getting Started (Streamers)

1. Go to **clips.gmgnrepeat.com**
2. Enter your Twitch channel name
3. Hit **Archive**

ClipTV pulls your entire clip history from Twitch and keeps it updated automatically going forward. No manual importing needed after the initial archive.

Once archived, the streamer gets their own account linked via Twitch login.

---

## Features Overview

### Clip Archive and Browser (Web)

- Every clip ever made on a channel is collected and stored in the ClipTV system
- Clips are searchable and filterable by:
  - Game / category
  - Date
  - Who clipped it (creator name)
  - View count
  - Clip duration (short, medium, long)
  - Sort by: most viewed, trending, newest, oldest
- Accessible at: `clips.gmgnrepeat.com/search/channelname`
- Available to anyone, no login required to browse

---

### Streamer Dashboard

Streamers get a personal dashboard after linking their Twitch account. Features include:

- View clip stats: most liked clips, most disliked clips, top clips by views
- Manage their clip library (hide, organize)
- Build custom clip playlists
- Grant mod access so moderators can help curate and manage clips
- Mods can build playlists and assist with clip management

---

### ClipTV Live (Synchronized Viewer)

A shared, real-time clip viewing experience available at:
`clips.gmgnrepeat.com/tv/channelname`

- Everyone watching sees the **same clip at the same time** — fully synchronized
- Community features:
  - **Voting** — viewers can like or dislike clips as they play
  - **Clip requests** — viewers can request specific clips to be played
  - **Skip voting** — community can vote to skip a clip
  - **Live chat** — chat alongside the clip playback
- Mods can:
  - Skip clips
  - Play specific clips or playlists
  - Control the queue

---

### Discord Activity

ClipTV is available as a **Discord Embedded Activity** — launched directly inside a Discord voice channel.

- Works on desktop and mobile Discord
- **No browser needed** — runs entirely inside Discord
- Brings the full synchronized ClipTV Live experience into voice channels
- Same clip plays for everyone in the activity at the same time
- Voting, chat, and clip interaction all work the same as the web version
- To launch: join a voice channel, click the rocket/activities icon, find ClipTV

Install link: `https://discord.com/oauth2/authorize?client_id=1477451341776421046`

---

### BRB OBS Browser Source

ClipTV includes a browser source designed for use in OBS (or any browser source compatible software) for when a streamer goes on a BRB screen.

- Drop the ClipTV browser source URL into OBS as a browser source
- Clips play automatically while the streamer is away — the stream never goes dead
- Requires adding **ClipTV Bot** (`cliptvbot`) to the Twitch channel
- Chat can vote (like/dislike) on clips as they play during BRB
- Mods can skip clips or play their favorites directly from Twitch chat
- Great for keeping a stream engaging during breaks

---

### Twitch Panel Extension

ClipTV has a **Twitch Panel Extension** that lives in the panels section of a streamer's Twitch channel page.

- Viewers can browse and watch clips **without ever leaving Twitch**
- Panel features:
  - Video player built in — clips play inline in the panel
  - Sort tabs: Recent, Top, Random
  - Search by keyword, game/category, duration, or who clipped it
  - Copy clip URL button for sharing
- Extension is currently in **testing / trial mode**
- Streamers who want to add the extension can reach out to get access
- After full Twitch review, it will be publicly available to install

---

## Twitch Bot — cliptvbot

ClipTV operates a Twitch bot account called **cliptvbot** (formerly cliparchive, renamed March 2026).

- Used to enable chat interaction during the BRB OBS browser source
- Streamers add the bot to their channel to enable BRB voting and mod controls
- The bot listens to chat for vote commands and mod commands during BRB sessions

---

## Platform Support

- **Twitch** — primary platform, full feature support
- **Kick** — experimental support (clips imported and playable, platform-aware across the system)

---

## Branding

- Product name: **ClipTV**
- Parent brand: **GMGNRepeat** ("Powered by GMGNRepeat.com")
- Previously known as "ClipArchive" — that name is fully retired as of March 2026
- Mascot: tapefacecliptv (VHS tape character)
- Domain: clips.gmgnrepeat.com
- Contact: contact@gmgnrepeat.com

---

## Frequently Asked Questions

**Is ClipTV free?**
Yes. ClipTV is free right now for streamers and their communities.

**Do I need to manually add clips?**
No. Once you hit Archive for your channel, ClipTV pulls everything automatically and keeps updating.

**Can my mods help manage clips?**
Yes. Streamers can grant mod access from their dashboard so mods can curate playlists and help manage the library.

**Does ClipTV work for Kick streamers?**
Experimentally yes. Kick support is built in but is considered early access.

**How do I get the Twitch Panel Extension?**
The extension is currently in testing. Reach out to GMGNRepeat to be added as a tester. Once Twitch approves it for public release, it will be available to install directly from the Twitch extension store.

**How does the BRB source work?**
Add ClipTV Bot (`cliptvbot`) to your Twitch channel, then add the ClipTV browser source URL to OBS. When you go to your BRB scene, clips play automatically. Your chat can vote and your mods can control the queue.

**What is the Discord Activity?**
A version of ClipTV that runs natively inside Discord voice channels. Everyone in the activity watches the same clip at the same time with voting and chat, no browser required.

**How is ClipTV different from just using Twitch clips?**
Twitch clips are hard to find, have no search, no filtering, no community voting, no synchronized viewing, and no BRB integration. ClipTV turns your clip library into a living, interactive experience across multiple platforms.

---

## Links

- Website: https://clips.gmgnrepeat.com
- Discord Activity install: https://discord.com/oauth2/authorize?client_id=1477451341776421046
- Twitch Extension: currently in testing, contact GMGNRepeat for access
