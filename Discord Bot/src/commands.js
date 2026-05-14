const { SlashCommandBuilder } = require('discord.js');

const commandDefinitions = [
  new SlashCommandBuilder()
    .setName('today')
    .setDescription('Show tasks due soon and today\'s health snapshot.'),
  new SlashCommandBuilder()
    .setName('moment')
    .setDescription('Look up nearby timeline context for a timestamp.')
    .addStringOption((option) =>
      option
        .setName('at')
        .setDescription('Timestamp to inspect, e.g. 2026-05-12T18:30:00-04:00')
        .setRequired(true)
    )
    .addIntegerOption((option) =>
      option
        .setName('radius_minutes')
        .setDescription('Radius in minutes around the timestamp.')
        .setRequired(false)
    ),
  new SlashCommandBuilder()
    .setName('mood')
    .setDescription('Create a mood entry.')
    .addStringOption((option) =>
      option.setName('category').setDescription('Mood category').setRequired(true)
    )
    .addStringOption((option) =>
      option.setName('note').setDescription('Optional note').setRequired(false)
    ),
  new SlashCommandBuilder()
    .setName('task-add')
    .setDescription('Add a task.')
    .addStringOption((option) =>
      option.setName('title').setDescription('Task title').setRequired(true)
    )
    .addStringOption((option) =>
      option
        .setName('due_at')
        .setDescription('Optional due timestamp, e.g. 2026-05-12T21:00:00-04:00')
        .setRequired(false)
    )
    .addIntegerOption((option) =>
      option
        .setName('decay_after_hours')
        .setDescription('Optional decay window in hours.')
        .setRequired(false)
    ),
  new SlashCommandBuilder()
    .setName('task-done')
    .setDescription('Complete a task by ID.')
    .addIntegerOption((option) =>
      option.setName('id').setDescription('Task ID').setRequired(true)
    ),
  new SlashCommandBuilder()
    .setName('task-snooze')
    .setDescription('Snooze a task by ID.')
    .addIntegerOption((option) =>
      option.setName('id').setDescription('Task ID').setRequired(true)
    )
    .addIntegerOption((option) =>
      option.setName('minutes').setDescription('Minutes to snooze').setRequired(true)
    ),
  new SlashCommandBuilder()
    .setName('health-summary')
    .setDescription('Summarize health metrics for a date.')
    .addStringOption((option) =>
      option
        .setName('date')
        .setDescription('Date in YYYY-MM-DD format.')
        .setRequired(false)
    ),
  new SlashCommandBuilder()
    .setName('finance-recent')
    .setDescription('Show recent archived finance transactions.')
    .addIntegerOption((option) =>
      option
        .setName('days')
        .setDescription('How many days back to look.')
        .setRequired(false)
    )
];

function formatTask(task) {
  const due = task.due_at ? ` due ${task.due_at} UTC` : '';
  return `#${task.id} ${task.title} (${task.status})${due}`;
}

function formatMomentItem(item) {
  const when = item.occurred_at || item.start_at || 'unknown time';
  return `[${item.domain}] ${item.title} at ${when}`;
}

async function handleInteraction(interaction, lifeOsClient) {
  const name = interaction.commandName;

  if (name === 'today') {
    const [tasksData, healthData] = await Promise.all([
      lifeOsClient.listTasks('pending'),
      lifeOsClient.healthSummary(new Date().toISOString().slice(0, 10))
    ]);

    const tasks = tasksData.tasks || [];
    const summary = healthData.summary || {};

    const lines = [
      `Tasks: ${tasks.length}`,
      ...tasks.slice(0, 5).map(formatTask),
      `Steps today: ${summary.steps ?? 0}`,
      `Sleep hours: ${summary.sleep_hours ?? 0}`,
      `Average heart rate: ${summary.average_heart_rate ?? 'n/a'}`
    ];

    await interaction.reply(lines.join('\n'));
    return;
  }

  if (name === 'moment') {
    const at = interaction.options.getString('at', true);
    const radiusMinutes = interaction.options.getInteger('radius_minutes') || 60;
    const result = await lifeOsClient.moment(at, radiusMinutes);
    const items = result.items || [];

    if (items.length === 0) {
      await interaction.reply('No nearby timeline items were found.');
      return;
    }

    await interaction.reply(items.slice(0, 10).map(formatMomentItem).join('\n'));
    return;
  }

  if (name === 'mood') {
    const category = interaction.options.getString('category', true);
    const note = interaction.options.getString('note');
    const result = await lifeOsClient.createMood({
      category,
      note,
      happened_at: new Date().toISOString()
    });

    await interaction.reply(`Mood logged: ${result.entry.category}`);
    return;
  }

  if (name === 'task-add') {
    const title = interaction.options.getString('title', true);
    const dueAt = interaction.options.getString('due_at');
    const decayAfterHours = interaction.options.getInteger('decay_after_hours');
    const result = await lifeOsClient.createTask({
      title,
      due_at: dueAt,
      decay_after_hours: decayAfterHours
    });

    await interaction.reply(`Created task #${result.task.id}: ${result.task.title}`);
    return;
  }

  if (name === 'task-done') {
    const id = interaction.options.getInteger('id', true);
    const result = await lifeOsClient.completeTask(id);
    await interaction.reply(`Completed task #${result.task.id}: ${result.task.title}`);
    return;
  }

  if (name === 'task-snooze') {
    const id = interaction.options.getInteger('id', true);
    const minutes = interaction.options.getInteger('minutes', true);
    const result = await lifeOsClient.snoozeTask(id, minutes);
    await interaction.reply(`Snoozed task #${result.task.id} to ${result.task.due_at} UTC`);
    return;
  }

  if (name === 'health-summary') {
    const date = interaction.options.getString('date') || new Date().toISOString().slice(0, 10);
    const result = await lifeOsClient.healthSummary(date);
    const summary = result.summary || {};
    await interaction.reply(
      [
        `Health summary for ${summary.date || date}`,
        `Steps: ${summary.steps ?? 0}`,
        `Sleep hours: ${summary.sleep_hours ?? 0}`,
        `Average heart rate: ${summary.average_heart_rate ?? 'n/a'}`,
        `Latest weight: ${summary.latest_weight ?? 'n/a'}`
      ].join('\n')
    );
    return;
  }

  if (name === 'finance-recent') {
    const days = interaction.options.getInteger('days') || 30;
    const result = await lifeOsClient.financeRecent(days);
    const transactions = result.transactions || [];

    if (transactions.length === 0) {
      await interaction.reply('No recent finance transactions are available yet.');
      return;
    }

    const lines = transactions.slice(0, 10).map((transaction) => {
      const amount = Number(transaction.amount).toFixed(2);
      return `${transaction.date_posted} ${transaction.name} ${amount}`;
    });

    await interaction.reply(lines.join('\n'));
  }
}

module.exports = {
  commandDefinitions,
  handleInteraction
};
