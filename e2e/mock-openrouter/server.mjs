// Minimal stand-in for the OpenRouter chat-completions API, used by the e2e
// suite so tests never hit the real (paid) service.
//
//   POST /api/v1/chat/completions  -> next queued response, or a default recipe
//   POST /__mock/enqueue           -> body {status?, body} queues one response
//   GET  /__mock/requests          -> every chat-completions request received
//   POST /__mock/reset             -> clear queue + recorded requests
//
// No dependencies: runs on a bare node image.
import http from 'node:http';

const PORT = Number(process.env.PORT || 4010);

export const DEFAULT_RECIPE = [
  'Mock Herb Roasted Chicken',
  '',
  'Ingredients:',
  '- 2 chicken thighs',
  '- 1 tbsp olive oil',
  '',
  'Method:',
  '1. Roast at 200C for 35 minutes.',
].join('\n');

let queue = [];
let requests = [];

function completion(content, finishReason = 'stop') {
  return {
    id: 'gen-mock-' + Date.now(),
    model: 'anthropic/claude-sonnet-4.6',
    choices: [{ index: 0, finish_reason: finishReason, message: { role: 'assistant', content } }],
    usage: { prompt_tokens: 321, completion_tokens: 123, total_tokens: 444, cost: 0.0012 },
  };
}

function send(res, status, body) {
  res.writeHead(status, { 'Content-Type': 'application/json' });
  res.end(JSON.stringify(body));
}

function readJson(req) {
  return new Promise((resolve, reject) => {
    let raw = '';
    req.on('data', (c) => (raw += c));
    req.on('end', () => {
      try {
        resolve(raw ? JSON.parse(raw) : {});
      } catch (e) {
        reject(e);
      }
    });
  });
}

const server = http.createServer(async (req, res) => {
  const path = new URL(req.url, 'http://x').pathname;
  try {
    if (req.method === 'POST' && path === '/api/v1/chat/completions') {
      const body = await readJson(req);
      requests.push({ headers: req.headers, body });
      const next = queue.shift();
      if (next) return send(res, next.status ?? 200, next.body);
      return send(res, 200, completion(DEFAULT_RECIPE));
    }
    if (req.method === 'POST' && path === '/__mock/enqueue') {
      const item = await readJson(req);
      // Shorthand: {content, finish_reason} builds a normal completion.
      if (item.content !== undefined) {
        item.body = completion(item.content, item.finish_reason);
      }
      queue.push(item);
      return send(res, 200, { queued: queue.length });
    }
    if (req.method === 'GET' && path === '/__mock/requests') return send(res, 200, requests);
    if (req.method === 'POST' && path === '/__mock/reset') {
      queue = [];
      requests = [];
      return send(res, 200, { ok: true });
    }
    if (path === '/__mock/health') return send(res, 200, { ok: true });
    send(res, 404, { error: { message: 'mock: no route ' + req.method + ' ' + path } });
  } catch (e) {
    send(res, 400, { error: { message: 'mock: ' + e.message } });
  }
});

server.listen(PORT, () => console.log(`mock openrouter listening on :${PORT}`));
process.on('SIGTERM', () => server.close(() => process.exit(0)));
