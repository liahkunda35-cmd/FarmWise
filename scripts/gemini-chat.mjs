/**
 * FarmWise Gemini bridge using the official @google/genai SDK.
 * Invoked by PHP (GeminiService) with JSON on stdin; writes JSON on stdout.
 *
 * Input:  { apiKey, model, system, message, history:[{role,text}], farmContext? }
 * Output: { ok:true, text } | { ok:false, error }
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
  process.stdout.write(JSON.stringify({ ok: false, error }));
  process.exit(code);
}

try {
  const raw = await readStdin();
  const input = JSON.parse(raw || '{}');
  const apiKey = String(input.apiKey || process.env.GEMINI_API_KEY || '').trim();
  if (!apiKey) {
    fail('GEMINI_API_KEY is not configured on the server.');
  }

  const model = String(input.model || process.env.GEMINI_MODEL || 'gemini-3.8-flash').trim();
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

  const ai = new GoogleGenAI({ apiKey });
  const response = await ai.models.generateContent({
    model,
    contents,
    config: {
      systemInstruction,
      temperature: 0.6,
      maxOutputTokens: 1024,
    },
  });

  const text = String(response.text || '').trim();
  if (!text) {
    fail('Gemini returned an empty response. Try again or check the model name.');
  }
  process.stdout.write(JSON.stringify({ ok: true, text }));
} catch (err) {
  let msg = err && err.message ? String(err.message) : 'Gemini request failed.';
  try {
    const nested = JSON.parse(msg);
    if (nested?.error?.message) msg = String(nested.error.message);
  } catch {
    /* plain string */
  }
  // Never echo API keys
  fail(msg.replace(/key=[^&\s]+/gi, 'key=REDACTED').slice(0, 400));
}
