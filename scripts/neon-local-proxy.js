#!/usr/bin/env node
/**
 * Local Postgres proxy for XAMPP's ancient libpq (11.x).
 *
 * PHP talks cleartext to 127.0.0.1:15432 (sslmode=disable).
 * This process authenticates PHP with a cleartext password challenge,
 * opens a modern TLS+SCRAM session to Neon via `pg`, then pipes the
 * post-auth wire protocol so PDO works without SCRAM-SHA-256-PLUS.
 */
const net = require('net');
const fs = require('fs');
const path = require('path');
const { Client } = require('pg');

const ROOT = path.join(__dirname, '..');
const LISTEN_HOST = process.env.NEON_PROXY_HOST || '127.0.0.1';
const LISTEN_PORT = Number(process.env.NEON_PROXY_PORT || 15432);

function loadEnv() {
  const out = {};
  for (const file of ['.env', '.env.local']) {
    const p = path.join(ROOT, file);
    if (!fs.existsSync(p)) continue;
    for (const line of fs.readFileSync(p, 'utf8').split(/\r?\n/)) {
      const t = line.trim();
      if (!t || t.startsWith('#') || !t.includes('=')) continue;
      const i = t.indexOf('=');
      let k = t.slice(0, i).trim();
      let v = t.slice(i + 1).trim();
      if ((v.startsWith('"') && v.endsWith('"')) || (v.startsWith("'") && v.endsWith("'"))) {
        v = v.slice(1, -1);
      }
      if (k && out[k] === undefined) out[k] = v;
    }
  }
  return out;
}

function pickTarget(env) {
  const candidates = [
    process.env.NEON_PROXY_TARGET,
    env.NEON_PROXY_TARGET,
    env.DATABASE_URL_UNPOOLED,
  ].filter(Boolean);
  const url = candidates.find((u) => {
    try {
      const h = new URL(u).hostname;
      return h && h !== '127.0.0.1' && h !== 'localhost';
    } catch (_) {
      return false;
    }
  });
  if (!url) throw new Error('Set NEON_PROXY_TARGET in .env to the real Neon Postgres URL');
  const u = new URL(url);
  return {
    host: u.hostname,
    port: Number(u.port || 5432),
    user: decodeURIComponent(u.username || env.PGUSER || 'neondb_owner'),
    pass: decodeURIComponent(u.password || env.PGPASSWORD || ''),
    database: (u.pathname || '/neondb').replace(/^\//, '') || 'neondb',
    url,
  };
}

function readCStrings(buf, offset) {
  const out = [];
  let i = offset;
  while (i < buf.length) {
    if (buf[i] === 0) {
      i += 1;
      if (i < buf.length && buf[i] === 0) break;
      continue;
    }
    let end = i;
    while (end < buf.length && buf[end] !== 0) end += 1;
    out.push(buf.slice(i, end).toString('utf8'));
    i = end + 1;
  }
  return out;
}

function msg(code, body) {
  const len = Buffer.alloc(4);
  len.writeInt32BE(body.length + 4, 0);
  return Buffer.concat([Buffer.from([code]), len, body]);
}

function authCleartext() {
  const body = Buffer.alloc(4);
  body.writeInt32BE(3, 0); // AuthenticationCleartextPassword
  return msg(0x52, body); // 'R'
}

function authOk() {
  const body = Buffer.alloc(4);
  body.writeInt32BE(0, 0);
  return msg(0x52, body);
}

function parameterStatus(key, value) {
  return msg(0x53, Buffer.concat([Buffer.from(key + '\0'), Buffer.from(value + '\0')]));
}

function backendKeyData(pid, secret) {
  const body = Buffer.alloc(8);
  body.writeInt32BE(pid >>> 0, 0);
  body.writeInt32BE(secret >>> 0, 4);
  return msg(0x4b, body); // 'K'
}

function readyForQuery(status = 'I') {
  return msg(0x5a, Buffer.from(status)); // 'Z'
}

function errorResponse(message) {
  const fields = Buffer.concat([
    Buffer.from([0x53]), Buffer.from('FATAL\0'),
    Buffer.from([0x43]), Buffer.from('28000\0'),
    Buffer.from([0x4d]), Buffer.from(message + '\0'),
    Buffer.from([0]),
  ]);
  return msg(0x45, fields); // 'E'
}

const env = loadEnv();
const target = pickTarget(env);

const server = net.createServer((client) => {
  let buf = Buffer.alloc(0);
  let stage = 'init'; // init -> ssl_or_startup -> auth -> piping
  let neonClient = null;
  let neonStream = null;

  const fail = (err) => {
    if (err) console.error('[neon-proxy]', err.message || err);
    try { client.destroy(); } catch (_) {}
    try { if (neonClient) neonClient.end().catch(() => {}); } catch (_) {}
    try { if (neonStream) neonStream.destroy(); } catch (_) {}
  };

  const beginPipe = async (startupUser, startupDatabase) => {
    neonClient = new Client({
      host: target.host,
      port: target.port,
      user: target.user,
      password: target.pass,
      database: startupDatabase || target.database,
      ssl: { rejectUnauthorized: false },
      connectionTimeoutMillis: 20000,
      keepAlive: true,
    });
    await neonClient.connect();

    // Steal the live TLS socket from node-pg after SCRAM completed.
    neonStream = neonClient.connection.stream;
    neonClient.connection.stream = null;
    if (neonClient.connection) {
      try { neonClient.connection.removeAllListeners('data'); } catch (_) {}
    }
    // Detach pg parsers so we can raw-pipe.
    try { neonStream.removeAllListeners('data'); } catch (_) {}
    try { neonStream.removeAllListeners('readable'); } catch (_) {}

    // Synthesize a clean post-auth handshake for PHP's old libpq.
    client.write(authOk());
    client.write(parameterStatus('server_version', '16.0'));
    client.write(parameterStatus('server_encoding', 'UTF8'));
    client.write(parameterStatus('client_encoding', 'UTF8'));
    client.write(parameterStatus('DateStyle', 'ISO, MDY'));
    client.write(parameterStatus('integer_datetimes', 'on'));
    client.write(backendKeyData(process.pid % 100000, (Math.random() * 0x7fffffff) | 0));
    client.write(readyForQuery('I'));

    stage = 'piping';
    client.on('data', (chunk) => {
      if (neonStream && !neonStream.destroyed) neonStream.write(chunk);
    });
    neonStream.on('data', (chunk) => {
      if (!client.destroyed) client.write(chunk);
    });
    neonStream.on('error', fail);
    neonStream.on('close', () => fail());
  };

  client.on('data', async (chunk) => {
    if (stage === 'piping') return;
    buf = Buffer.concat([buf, chunk]);
    try {
      if (stage === 'init') {
        if (buf.length < 8) return;
        // SSLRequest
        if (buf.readInt32BE(0) === 8 && buf.readInt32BE(4) === 80877103) {
          client.write(Buffer.from('N'));
          buf = buf.slice(8);
          stage = 'startup';
        } else {
          stage = 'startup';
        }
      }

      if (stage === 'startup') {
        if (buf.length < 4) return;
        const len = buf.readInt32BE(0);
        if (buf.length < len) return;
        const packet = buf.slice(0, len);
        buf = buf.slice(len);
        const proto = packet.readInt32BE(4);
        if (proto !== 196608) {
          client.write(errorResponse('Unsupported protocol'));
          return fail();
        }
        const pairs = readCStrings(packet, 8);
        const params = {};
        for (let i = 0; i + 1 < pairs.length; i += 2) {
          params[pairs[i]] = pairs[i + 1];
        }
        client._fwUser = params.user || target.user;
        client._fwDatabase = params.database || target.database;
        client.write(authCleartext());
        stage = 'password';
        return;
      }

      if (stage === 'password') {
        // PasswordMessage: 'p' + int32 + password + 0
        if (buf.length < 5) return;
        if (buf[0] !== 0x70) {
          client.write(errorResponse('Expected password'));
          return fail();
        }
        const len = buf.readInt32BE(1);
        if (buf.length < 1 + len) return;
        const password = buf.slice(5, 1 + len - 1).toString('utf8');
        buf = buf.slice(1 + len);
        if (password !== target.pass) {
          client.write(errorResponse('password authentication failed'));
          return fail();
        }
        stage = 'connecting';
        client.pause();
        await beginPipe(client._fwUser, client._fwDatabase);
        client.resume();
        if (buf.length && neonStream) {
          neonStream.write(buf);
          buf = Buffer.alloc(0);
        }
      }
    } catch (err) {
      try { client.write(errorResponse(err.message || 'proxy error')); } catch (_) {}
      fail(err);
    }
  });

  client.on('error', fail);
  client.on('close', () => fail());
});

server.on('error', (err) => {
  console.error('[neon-proxy] listen failed:', err.message);
  process.exit(1);
});

server.listen(LISTEN_PORT, LISTEN_HOST, () => {
  console.log(`[neon-proxy] ${LISTEN_HOST}:${LISTEN_PORT} -> ${target.host}:${target.port} (auth-bridge)`);
});
