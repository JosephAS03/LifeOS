# LIFE OS

LIFE OS is a private, timeline-first personal dashboard built around a WordPress plugin plus a companion Discord bot.

## Project Layout

- `Wordpress Plugin/life-os/` - the WordPress plugin and system of record.
- `Discord Bot/` - the Discord bot service that talks to the plugin over signed HTTP requests.

## MVP Status

This repository contains the first working MVP foundation based on the revised implementation guide:

- custom WordPress tables and activation installer
- admin settings, feature-page automation, finance connection management UI, and Voice Monkey test action
- automatic provisioning of Dashboard, Tasks, Health, Finance, Mood, and Timeline pages
- timeline, tasks, mood, health ingest, notifications, and audit logging foundations
- signed internal REST API for bot and bridge traffic
- Discord OAuth2 account linking
- SimpleFIN setup-token exchange, encrypted access-token storage, archived account and transaction sync, raw sync logs, and local history retention
- Discord bot command scaffolding plus a 1-minute heartbeat that asks WordPress to process cron-style catch-up work

## Next Setup Steps

1. Install the plugin from `Wordpress Plugin/life-os/` into your WordPress instance.
2. Configure plugin settings in WordPress Admin under `LIFE OS`.
3. Create a Discord application and bot, then fill in the Discord settings.
4. Open `LIFE OS -> Finance Connections`, generate a SimpleFIN setup token, and connect the finance provider.
5. Install the bot dependencies in `Discord Bot/` and run the bot with the `.env.example` template.
6. Add Google / Voice Monkey credentials when those providers are ready to be connected.

## GitHub Updates

The canonical repo for LIFE OS is [https://github.com/JosephAS03/LifeOS](https://github.com/JosephAS03/LifeOS).

The WordPress plugin now references that repo directly in its header and includes a built-in GitHub release updater. For WordPress to install an update automatically, each GitHub Release should include a plugin ZIP asset built from `Wordpress Plugin/life-os` with `life-os/` as the root folder inside the archive.

Recommended asset name:

- `life-os.zip`
