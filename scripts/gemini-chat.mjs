/**
 * FarmWise Gemini bridge using the official @google/genai SDK.
 * Invoked by PHP (GeminiService) with JSON on stdin; writes JSON on stdout.
 *
 * Input:  { apiKey, model, models?, system, message, history:[{role,text}], farmContext?, timeout? }
 * Output: { ok:true, text, model } | { ok:false, error }
 */
import { GoogleGenAI } from '@google/genai';

async function readStdin() {
  const chunks = [];
  for await (const chunk of process.stdin) {
    chunks.push(chunk);
  }
  return Buffer.concat(chunks).toString('utf8');
}

function fail(error, code = 1) {
  process.stdout.write(JSON.stringify({ ok: false, error: String(error).slice(0, 400) }));
  process.exit(code);
}

function redact(msg) {
  return String(msg || '')
    .replace(/key=[^&\s]+/gi, 'key=REDACTED')
    .replace(/AIza[0-9A-Za-z_-]+/g, 'REDACTED')
    .replace(/AQ\.[0-9A-Za-z_-]+/g, 'REDACTED')
    .slice(0, 400);
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function isRetryable(err) {
  const msg = String(err?.message || err || '').toLowerCase();
  const status = err?.status || err?.code || err?.error?.code;
  return (
    status === 429 ||
    status === 500 ||
    status === 503 ||
    msg.includes('high demand') ||
    msg.includes('try again') ||
    msg.includes('resource_exhausted') ||
    msg.includes('unavailable') ||
    msg.includes('timeout') ||
    msg.includes('fetch failed')
  );
}

function isModelMissing(err) {
  const msg = String(err?.message || err || '').toLowerCase();
  const status = err?.status || err?.code;
  return status === 404 || msg.includes('not found') || msg.includes('no longer available');
}

try {
  const raw = await readStdin();
  const input = JSON.parse(raw || '{}');
  const apiKey = String(input.apiKey || process.env.GEMINI_API_KEY || '').trim();
  if (!apiKey) {
    fail('GEMINI_API_KEY is not configured on the server.');
  }

  const primary = String(input.model || process.env.GEMINI_MODEL || 'gemini-3.8-flash').trim();
  const models = Array.isArray(input.models) && input.models.length
    ? [...new Set(input.models.map((m) => String(m || '').trim()).filter(Boolean))]
    : [
        primary,
        'gemini-flash-lite-latest',
        'gemini-3.1-flash-lite',
        'gemini-3.5-flash-lite',
        'gemini-3.7-flash',
        'gemini-3.6-flash',
      ];
  if (!models.includes(primary)) models.unshift(primary);

  const hardDeadline = Date.now() + 48000;

  const system = String(input.system || '');
  const message = String(input.message || '').trim();
  if (!message) {
    fail('Message is required.');
  }

  const history = Array.isArray(input.history) ? input.history : [];
  const contents = [];
  for (const turn of history.slice(-16)) {
    const role = turn.role === 'model' || turn.role === 'assistant' ? 'model' : 'user';
    const text = String(turn.text || turn.content || '').trim();
    if (!text) continue;
    contents.push({ role, parts: [{ text }] });
  }
  contents.push({ role: 'user', parts: [{ text: message }] });

  let systemInstruction = system;
  if (input.farmContext) {
    systemInstruction +=
      '\n\n--- Authorized farm records snapshot (read-only; do not invent beyond this) ---\n' +
      String(input.farmContext);
  }

  const temperature = Number.isFinite(Number(input.temperature))
    ? Number(input.temperature)
    : 0.6;

  const ai = new GoogleGenAI({ apiKey });
  let lastError = 'Gemini request failed.';

  for (const model of models) {
    if (Date.now() >= hardDeadline) break;
    for (let attempt = 1; attempt <= 2; attempt++) {
      if (Date.now() >= hardDeadline) break;
      try {
        const response = await ai.models.generateContent({
          model,
          contents,
          config: {
            systemInstruction,
            temperature,
            maxOutputTokens: 1400,
          },
        });

        const text = String(response.text || '').trim();
        if (!text) {
          throw new Error('Gemini returned an empty response. Try again or check the model name.');
        }
        process.stdout.write(JSON.stringify({ ok: true, text, model }));
        process.exit(0);
      } catch (err) {
        lastError = redact(err?.message || err);
        try {
          const nested = JSON.parse(String(err?.message || ''));
          if (nested?.error?.message) lastError = redact(nested.error.message);
        } catch {
          /* plain */
        }

        if (isModelMissing(err)) {
          break; // next model
        }
        if (isRetryable(err) && attempt < 2) {
          await sleep(300 * attempt);
          continue;
        }
        break; // try next model quickly under load
      }
    }
  }

  fail(lastError);
} catch (err) {
  let msg = err && err.message ? String(err.message) : 'Gemini request failed.';
  try {
    const nested = JSON.parse(msg);
    if (nested?.error?.message) msg = String(nested.error.message);
  } catch {
    /* plain string */
  }
  fail(redact(msg));
}
