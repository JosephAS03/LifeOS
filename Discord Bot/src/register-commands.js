const { REST, Routes } = require('discord.js');
const config = require('./config');
const { commandDefinitions } = require('./commands');

async function main() {
  const rest = new REST({ version: '10' }).setToken(config.discordToken);
  const payload = commandDefinitions.map((command) => command.toJSON());

  if (config.discordGuildId) {
    await rest.put(
      Routes.applicationGuildCommands(config.discordClientId, config.discordGuildId),
      { body: payload }
    );
    console.log(`Registered ${payload.length} guild commands.`);
    return;
  }

  await rest.put(Routes.applicationCommands(config.discordClientId), { body: payload });
  console.log(`Registered ${payload.length} global commands.`);
}

main().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});

