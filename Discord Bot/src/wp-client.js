const crypto = require('crypto');
const https = require('https');

class LifeOsClient {
  constructor(config) {
    this.baseUrl = config.lifeOsBaseUrl;
    this.secret = config.lifeOsSharedSecret;
    this.botId = config.lifeOsBotId;
    this.wpUserId = config.lifeOsWpUserId;
  }

  async heartbeat() {
    return this.request('/heartbeat', {
      method: 'POST',
      body: {
        source: 'discord_bot',
        heartbeat_id: crypto.randomUUID(),
        bot_version: '0.1.0',
        now_utc: new Date().toISOString(),
        wp_user_id: this.wpUserId
      },
      sourceHeader: ['X-LifeOS-Bot-Id', this.botId]
    });
  }

  async listTasks(status) {
    const params = new URLSearchParams();
    if (status) {
      params.set('status', status);
    }
    params.set('limit', '10');
    params.set('wp_user_id', String(this.wpUserId));

    return this.request(`/tasks?${params.toString()}`, {
      method: 'GET',
      sourceHeader: ['X-LifeOS-Bot-Id', this.botId]
    });
  }

  async createTask(payload) {
    return this.request('/tasks', {
      method: 'POST',
      body: {
        ...payload,
        wp_user_id: this.wpUserId
      },
      sourceHeader: ['X-LifeOS-Bot-Id', this.botId]
    });
  }

  async completeTask(id) {
    return this.request(`/tasks/${id}/complete`, {
      method: 'POST',
      body: {
        wp_user_id: this.wpUserId
      },
      sourceHeader: ['X-LifeOS-Bot-Id', this.botId]
    });
  }

  async snoozeTask(id, minutes) {
    return this.request(`/tasks/${id}/snooze`, {
      method: 'POST',
      body: {
        wp_user_id: this.wpUserId,
        minutes
      },
      sourceHeader: ['X-LifeOS-Bot-Id', this.botId]
    });
  }

  async createMood(payload) {
    return this.request('/mood', {
      method: 'POST',
      body: {
        ...payload,
        wp_user_id: this.wpUserId
      },
      sourceHeader: ['X-LifeOS-Bot-Id', this.botId]
    });
  }

  async moment(at, radiusMinutes) {
    const params = new URLSearchParams({
      at,
      radius_minutes: String(radiusMinutes || 60),
      wp_user_id: String(this.wpUserId)
    });

    return this.request(`/timeline/moment?${params.toString()}`, {
      method: 'GET',
      sourceHeader: ['X-LifeOS-Bot-Id', this.botId]
    });
  }

  async healthSummary(date) {
    const params = new URLSearchParams({
      date,
      wp_user_id: String(this.wpUserId)
    });

    return this.request(`/health/summary?${params.toString()}`, {
      method: 'GET',
      sourceHeader: ['X-LifeOS-Bot-Id', this.botId]
    });
  }

  async financeRecent(days) {
    const params = new URLSearchParams({
      days: String(days || 30),
      limit: '10',
      wp_user_id: String(this.wpUserId)
    });

    return this.request(`/finance/recent?${params.toString()}`, {
      method: 'GET',
      sourceHeader: ['X-LifeOS-Bot-Id', this.botId]
    });
  }

  async request(path, options) {
    const method = options.method || 'GET';
    const body = options.body ? JSON.stringify(options.body) : '';
    const target = this.canonicalTarget(path);
    const timestamp = new Date().toISOString();
    const nonce = crypto.randomUUID();
    const signature = crypto
      .createHmac('sha256', this.secret)
      .update(`${timestamp}.${nonce}.${method.toUpperCase()}.${target}.${body}`)
      .digest('hex');

    const headers = {
      'Content-Type': 'application/json',
      'X-LifeOS-Timestamp': timestamp,
      'X-LifeOS-Nonce': nonce,
      'X-LifeOS-Signature': signature
    };

    if (options.sourceHeader) {
      headers[options.sourceHeader[0]] = options.sourceHeader[1];
    }

    const { statusCode, rawBody } = await this.httpRequest(`${this.baseUrl}${path}`, {
      method,
      headers,
      body: method === 'GET' ? '' : body
    });

    let json;
    try {
      json = rawBody ? JSON.parse(rawBody) : {};
    } catch (error) {
      throw new Error(`LIFE OS returned invalid JSON with status ${statusCode}`);
    }

    if (statusCode < 200 || statusCode >= 300 || json.success === false) {
      const message = json?.error?.message || `LIFE OS request failed with status ${statusCode}`;
      throw new Error(message);
    }

    return json.data;
  }

  canonicalTarget(path) {
    const [pathname, queryString = ''] = path.split('?');
    if (!queryString) {
      return pathname;
    }

    const entries = [...new URLSearchParams(queryString).entries()].sort(([a], [b]) =>
      a.localeCompare(b)
    );
    const canonicalQuery = new URLSearchParams(entries).toString();

    return canonicalQuery ? `${pathname}?${canonicalQuery}` : pathname;
  }

  httpRequest(url, options) {
    return new Promise((resolve, reject) => {
      const request = https.request(url, {
        method: options.method,
        headers: options.headers
      }, (response) => {
        let rawBody = '';

        response.setEncoding('utf8');
        response.on('data', (chunk) => {
          rawBody += chunk;
        });
        response.on('end', () => {
          resolve({
            statusCode: response.statusCode || 0,
            rawBody
          });
        });
      });

      request.on('error', reject);

      if (options.body) {
        request.write(options.body);
      }

      request.end();
    });
  }
}

module.exports = {
  LifeOsClient
};
