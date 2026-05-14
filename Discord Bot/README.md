# LIFE OS Discord Bot

## Setup

1. Copy `.env.example` to `.env`.
2. Fill in the Discord token, client ID, and LIFE OS API settings.
3. Run `npm install`.
4. Run `npm run register-commands`.
5. Run `npm start`.

The bot talks to the WordPress plugin over signed requests using the shared bot secret.
It also sends a signed heartbeat every minute so WordPress can catch up on cron-style work such as finance sync checks and queued Voice Monkey delivery.
