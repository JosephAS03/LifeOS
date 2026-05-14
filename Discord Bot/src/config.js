const fs = require('fs');
const path = require('path');
const dotenv = require('dotenv');

const envCandidates = [
  path.resolve(process.cwd(), '.env'),
  path.resolve(__dirname, '../.env'),
  path.resolve(__dirname, '../../.env')
];

let loadedEnvPath = null;

for (const candidate of envCandidates) {
  if (!fs.existsSync(candidate)) {
    continue;
  }

  dotenv.config({ path: candidate, override: false });
  loadedEnvPath = candidate;
  break;
}

function required(name) {
  const value = process.env[name];
  if (!value) {
    throw new Error(`Missing required environment variable: ${name}`);
  }

  return value;
}

module.exports = {
  discordToken: required('DISCORD_TOKEN'),
  discordClientId: required('DISCORD_CLIENT_ID'),
  discordGuildId: process.env.DISCORD_GUILD_ID || '',
  lifeOsBaseUrl: required('LIFE_OS_BASE_URL').replace(/\/+$/, ''),
  lifeOsSharedSecret: required('LIFE_OS_SHARED_SECRET'),
  lifeOsBotId: process.env.LIFE_OS_BOT_ID || 'discord-bot-prod',
  lifeOsWpUserId: Number(process.env.LIFE_OS_WP_USER_ID || '1'),
  loadedEnvPath
};
