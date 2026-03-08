#!/usr/bin/env node
'use strict';

const crypto = require('crypto');
const http = require('http');
const { execFile } = require('child_process');
const path = require('path');

const PORT = Number(process.env.WEBHOOK_PORT || 9000);
const SECRET = process.env.WEBHOOK_SECRET || '';
const APP_DIR = __dirname;
const DEPLOY_SCRIPT = path.join(APP_DIR, 'scripts', 'deploy.sh');
const REPO = process.env.WEBHOOK_REPO || 'gitmadub/video-engine';

function sendJson(res, statusCode, payload) {
  res.writeHead(statusCode, { 'Content-Type': 'application/json; charset=utf-8' });
  res.end(JSON.stringify(payload));
}

function hasValidSignature(body, signature) {
  if (!SECRET) {
    return true;
  }

  if (typeof signature !== 'string' || signature.length === 0) {
    return false;
  }

  const expected = 'sha256=' + crypto.createHmac('sha256', SECRET).update(body).digest('hex');
  const left = Buffer.from(signature);
  const right = Buffer.from(expected);

  return left.length === right.length && crypto.timingSafeEqual(left, right);
}

const server = http.createServer((req, res) => {
  if (req.method !== 'POST' || req.url !== '/webhook') {
    sendJson(res, 404, { ok: false, error: 'not_found' });
    return;
  }

  const chunks = [];

  req.on('data', (chunk) => chunks.push(chunk));
  req.on('end', () => {
    const body = Buffer.concat(chunks);

    if (!hasValidSignature(body, req.headers['x-hub-signature-256'])) {
      sendJson(res, 401, { ok: false, error: 'invalid_signature' });
      return;
    }

    let payload;

    try {
      payload = JSON.parse(body.toString('utf8'));
    } catch (error) {
      sendJson(res, 400, { ok: false, error: 'invalid_json' });
      return;
    }

    const repo = payload?.repository?.full_name;
    const ref = payload?.ref;

    if (repo !== REPO) {
      sendJson(res, 202, { ok: true, ignored: 'repository' });
      return;
    }

    if (ref !== 'refs/heads/main') {
      sendJson(res, 202, { ok: true, ignored: 'branch' });
      return;
    }

    execFile('/bin/bash', [DEPLOY_SCRIPT], { cwd: APP_DIR }, (error, stdout, stderr) => {
      if (error) {
        sendJson(res, 500, {
          ok: false,
          error: 'deploy_failed',
          stdout: stdout.trim(),
          stderr: stderr.trim(),
        });
        return;
      }

      sendJson(res, 200, {
        ok: true,
        deployed: true,
        stdout: stdout.trim(),
        stderr: stderr.trim(),
      });
    });
  });
});

server.listen(PORT, '127.0.0.1');
