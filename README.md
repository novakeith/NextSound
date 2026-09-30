# NextSound
###### (Title is WIP)

##### Docker Container is here:
[NextSound on Docker Hub](https://hub.docker.com/repository/docker/novakeith/nextsound/general)

## What is it?
This was designed to be an open source, self-hostable alternative to Soundcloud for musicians or podcasters or anyone working with audio.

As I looked around the web, I did not see a convincing alternative to Soundcloud that sat in the middle of the venn diagram I had in my mind.

This project aims to be simple at one thing (sharing audio and soliciting feedback on that audio) without any of the extra features I saw in alternative tools. 

## What can it do today?
As of 9/24/26, you can:

**Tracks & versions**
- Spin up an instance using docker
- Have a single user install where the admin can upload tracks and set/edit/delete metadata on those tracks (project notes, changelog between versions)
- Upload multiple versions of the same track, so a listener can hear the progression. The newest upload becomes the default, and older versions stay selectable.
- Delete an entire project OR delete a single version of a track
- Allow individual versions to be downloaded
- Uploads are checked by their actual contents, not just the file extension. Supported formats: MP3, WAV, OGG, FLAC.

**Playlists**
- Group tracks into playlists that play straight through - when one track ends the next one starts, and its notes and comments load in automatically
- A playlist entry follows the project's latest version by default, so new mixes show up on their own. You can also pin an entry to a specific version.
- The same song can be in a playlist more than once, so you can build 'before/after' playlists (v1 pinned, then latest)
- Reorder tracks, swap pinned versions, and remove tracks from the admin panel

**Sharing & visibility**
- Every track and playlist gets its own share link (`/share/...` and `/playlist/...`), with a proper preview (title, artist, notes) when you paste it into Discord, iMessage, etc.
- Marking something 'public' lists it on the home page. 'Private' just means unlisted - anyone with the link can still listen. See [How privacy works](#how-privacy-works) below.

**Feedback**
- Listeners can click on a waveform and leave a timestamped comment (their name is remembered for next time)
- Comments show up as dots along the top of the waveform - hover to read one, click to jump to that spot
- Admins can mark comments as resolved/declined, or delete them
- A notifications menu (🔔) in the top bar collects new comments for review, with read/unread status and "mark all as read"
- Optionally require approval before visitors' comments become visible - approve or deny (delete) them right from the notifications menu
- Comments can be disabled site-wide. They will still appear for the admin though, so you can use this as a 'note to self' thing on tracks while still sharing the track with other people.
- Private notes for each version of a song, only visible to the admin - handy to keep open next to your DAW (mix ideas, plugin settings, what to fix next)
- Play counts for every track and version, always visible to you in the admin panel; you can choose to show them publicly too
- Optional webhook (e.g. Discord) that posts a message when a comment is left, a track is uploaded, and/or a playlist is created - you pick which in Settings

## How privacy works
NextSound's public/private setting controls **what's listed on the home page**, not who can listen:

- A **public** track or playlist shows up on the home page.
- A **private** track or playlist doesn't show up anywhere, but anyone you send the link to can listen.
- A **playlist link plays every track in it**, including private ones. It's up to you to curate your playlists. The admin panel warns you when:
  - you add a private track to a public playlist
  - you make a playlist public that has private tracks in it
  - you make a track private that's in a public playlist
- Playlist pages never reveal the individual share links of the tracks inside them.

If you need something truly locked down, don't upload it here yet (see the roadmap below).

## What will it do eventually?
Roughly in order - short term is what I'm working on next, the rest is further out and might change.

**Short term**
- A/B between versions of a song without losing your place, so you can hear what actually changed
- keyboard shortcuts (space to play/pause, arrow keys to seek, C to comment)

**Medium term**
- search / filter on the dashboard - with a lot of tracks, the admin UI is going to get unwieldy
- an embeddable player for blogs / websites
- generate waveforms when a track is uploaded, so big WAV files start playing right away instead of downloading the whole thing first
- UI clean-up in general, including a better playlist page layout and the nav bar on phones

**Long term / maybe**
- tie comment feedback to versions (ie. "incorporated in v3") so this doubles as a to-do list for a song
- light mode / dark mode AND/OR allow admins to create custom themes from a dashboard (I have rudimentary color picking for some buttons right now)
- allow admins to tag their projects / versions (ie. "sketch" / "mixing" / "mastering" / "completed")
- truly private tracks (audio only streamed to people who have access, instead of being reachable by its file URL)
- smaller streaming copies of big files for listening on phones
- multiple user accounts (admin / contributor / commenter), which would also let people edit or delete their own comments

## Installation
1. create a docker-compose.yml file,  example given below. Change the port and the .env file if you want.
```
services:
  nextsound:
    image: novakeith/nextsound:latest
    container_name: nextsound_app
    # change this port if you want. Default is 8027.
    ports:
      - "8027:80"
    volumes:
      - ./data:/var/www/html/data
      - ./uploads:/var/www/html/uploads
    # Make sure you open the .env file and update the defaults. Example .env file is at: .env.example
    env_file:
      - .env
    restart: always
```
2. Grab the .env.example file from this repo and save it as .env in the same directory as your docker-compose.yml. Make necessary changes to the .env file before exposing this container publicly! (see [Configuration](#configuration))
3. run ```docker compose up -d``` in a terminal in the same folder where your docker-compose.yml is
4. navigate to your service in your browser (ie. 127.0.0.1:8027) and login using the admin password you set in the .env file. 
5. Everything in the database should be created on first run

## Installation (Building this repo)
1. Download this repo
2. run ```cp .env.example .env``` and then edit .env so your environment variables are set properly. Do not run the docker without editing this first!
3. run the service with ```docker compose up -d --build```
4. The docker container will create the necessary folder structure, as well as the database the first time the page is accessed. 

## Installation (Unraid)
See [here](https://github.com/novakeith/NextSound-Unraid). I'm eventually going to work on getting this added to the community app store.

## Configuration
Set these in your `.env` file:

| Variable | What it does |
|---|---|
| `ADMIN_PASSWORD` | **Required.** The admin login password. If it's missing, login is disabled entirely. Use something long. |
| `SITE_TITLE` | The site name shown in the nav bar. Only used when the database is first created - after that, change it from the Settings page. |
| `SITE_URL` | Your public URL (e.g. `https://nextsound.mysite.com`, no trailing slash), used in webhook links. Also only used on first run; change it later from Settings. |
| `UPLOAD_MAX_FILESIZE` | Optional. Biggest single file you can upload, e.g. `512M` or `2G`. Defaults to 500M. |
| `POST_MAX_SIZE` | Optional. Should be the same as or a bit bigger than `UPLOAD_MAX_FILESIZE` - if you leave it out it just matches it. |

Heads up: if you're behind a reverse proxy, it probably has its own upload size limit too (Nginx Proxy Manager, Cloudflare, etc.), so raise that as well if big uploads fail.

If you're running NextSound behind a reverse proxy with HTTPS, make sure the proxy sends the `X-Forwarded-Proto` header so the login cookie is marked secure.

## Upgrading
1. Pull the new image (or `git pull` and rebuild) and restart the container
2. Log in and go to **Settings**. If the new version needs a database update, you'll see a button there - click it. It won't remove any data.

Some features (like playlists) stay hidden until that update has been run.

## Shutting down the service
If you want to cleanly reset all the data this service has created but retain the install files to run again in the future, you can run CLEANUP.sh - this will erase everything in the /data/ and /uploads/ folders. You will be prompted to confirm before the script executes and deletes files.

## Screenshots

Tracks can be public or private. Public tracks are listed on the home page.
![screenshot of the nextsound homepage showing two public tracks](https://novakeith.net/wp-content/uploads/2026/03/Songlist.png)

There is a waveform player with version notes and project notes, and a comment form that automatically time stamps the comment.
![screenshot of nextsound song page with the waveform viewer and comment form](https://novakeith.net/wp-content/uploads/2026/03/Songpage1.png)

Comments are visible by default but can be toggled off by the admin.
![screenshot of nextsound comments on a specific song](https://novakeith.net/wp-content/uploads/2026/03/Songpage-2.png)

The admin panel is straightforward:
![screenshot of the nextsound admin panel showing an HTML form](https://novakeith.net/wp-content/uploads/2026/03/AdminPanel1.png)

Projects listed in the admin panel; you can upload a new version if you wish, that automatically replaces the old version (the old version still remains selectable by listeners)
![screenshot of the nextsound admin panel showing the project management area](https://novakeith.net/wp-content/uploads/2026/03/AdminPanel2.png)

Global site settings. Rudimentary at this point but hopefully more to come soon.
![screenshot of the nextsound global site settings page](https://novakeith.net/wp-content/uploads/2026/03/AdminPanel3.png)
