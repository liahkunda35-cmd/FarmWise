#!/usr/bin/env node
/**
 * Start Neon local TLS proxy + PHP built-in server.
 * Clears inherited DATABASE_URL* so .env (via PHP bootstrap) wins.
 */
const { spawn } = require('child_process');
const path = require('path');

const root = path.join(__dirname, '..');
const php = process.env.IFMS_PHP || 'C:\\xampp\\php\\php.exe';

const env = { ...process.env };
for (const k of ['DATABASE_URL', 'DATABASE_URL_POOLED', 'DATABASE_URL_UNPOOLED', 'IFMS_DATABASE_URL']) {
  delete env[k];
}

const kids = [];

function run(cmd, args, name) {
  const child = spawn(cmd, args, { cwd: root, env, stdio: 'inherit', windowsHide: true });
  kids.push(child);
  child.on('exit', (code) => {
    console.error(`[${name}] exited (${code == null ? 'null' : code})`);
    shutdown(code || 0);
  });
  return child;
}

function shutdown(code) {
  for (const c of kids) {
    try {
      if (!c.killed) c.kill();
    } catch (_) {}
  }
  process.exit(code);
}

process.on('SIGINT', () => shutdown(0));
process.on('SIGTERM', () => shutdown(0));

run(process.execPath, [path.join(__dirname, 'neon-local-proxy.js')], 'neon-proxy');
// Give the proxy a moment to bind before PHP starts answering requests.
setTimeout(() => {
  run(php, ['-S', '127.0.0.1:8081', 'router.php'], 'php');
}, 400);
