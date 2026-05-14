const { Client, Events, GatewayIntentBits } = require('discord.js');
const config = require('./config');
const { LifeOsClient } = require('./wp-client');
const { handleInteraction } = require('./commands');

process.on('unhandledRejection', (error) => {
  console.error('[unhandledRejection]', error);
});

process.on('uncaughtException', (error) => {
  console.error('[uncaughtException]', error);
});

const client = new Client({
  intents: [GatewayIntentBits.Guilds, GatewayIntentBits.DirectMessages]
});

const lifeOsClient = new LifeOsClient(config);

async function runHeartbeat() {
  try {
    const result = await lifeOsClient.heartbeat();
    const financeSynced = result.finance_sync?.synced ?? 0;
    const financeSkipped = result.finance_sync?.skipped ?? 0;
    const voiceMonkeySent = result.voice_monkey?.sent ?? 0;
    const voiceMonkeyFailed = result.voice_monkey?.failed ?? 0;
    console.log(
      `[heartbeat] processed at ${result.processed_at}, decayed tasks: ${result.decayed_tasks}, finance synced: ${financeSynced}, finance skipped: ${financeSkipped}, voice monkey sent: ${voiceMonkeySent}, voice monkey failed: ${voiceMonkeyFailed}`
    );
  } catch (error) {
    console.error('[heartbeat] failed:', error.message);
  }
}

client.once(Events.ClientReady, async (readyClient) => {
  console.log(`LIFE OS bot ready as ${readyClient.user.tag}`);
  if (config.loadedEnvPath) {
    console.log(`[config] loaded env from ${config.loadedEnvPath}`);
  } else {
    console.log('[config] no local .env file found, relying on host-provided environment variables');
  }
  await runHeartbeat();
  setInterval(runHeartbeat, 60 * 1000);
});

client.on(Events.Error, (error) => {
  console.error('[discordClientError]', error);
});

client.on(Events.InteractionCreate, async (interaction) => {
  if (!interaction.isChatInputCommand()) {
    return;
  }

  try {
    await handleInteraction(interaction, lifeOsClient);
  } catch (error) {
    const message = `LIFE OS request failed: ${error.message}`;
    if (interaction.deferred || interaction.replied) {
      await interaction.followUp({ content: message, ephemeral: true });
      return;
    }

    await interaction.reply({ content: message, ephemeral: true });
  }
});

client.login(config.discordToken).catch((error) => {
  console.error('[login] failed to start the Discord bot:', error);
  process.exitCode = 1;
});
