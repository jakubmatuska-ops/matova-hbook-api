#!/usr/bin/env node
// CLI used by the workflows. No dependencies.
//   node tools/rollout-cli.mjs start   <update.json> <release.json> [changelog-file]
//   node tools/rollout-cli.mjs step    <update.json>          (needs ROLLOUT_URL + OIDC_TOKEN)
//   node tools/rollout-cli.mjs control <update.json> halt|resume
//   node tools/rollout-cli.mjs describe <update.json>
import { readFileSync, writeFileSync, appendFileSync, existsSync } from 'node:fs';
import { startCandidate, step, control, describe } from './rollout.mjs';

const [cmd, file, arg, arg2] = process.argv.slice(2);
const read = (f) => JSON.parse(readFileSync(f, 'utf8'));
const write = (f, m) => writeFileSync(f, JSON.stringify(m, null, 2) + '\n');
const now = new Date().toISOString();
const out = (k, v) => { if (process.env.GITHUB_OUTPUT) appendFileSync(process.env.GITHUB_OUTPUT, `${k}=${v}\n`); };

async function call(action, resort, extra = {}) {
  const res = await fetch(process.env.ROLLOUT_URL, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${process.env.OIDC_TOKEN}` },
    body: JSON.stringify({ action, resort, ...extra }),
    signal: AbortSignal.timeout(200_000),
  });
  const body = await res.json().catch(() => null);
  if (!res.ok || !body?.ok) throw new Error(`rollout backend ${action}/${resort}: http ${res.status} ${body?.error ?? ''}`);
  return body;
}
const api = {
  status: async (r) => { try { const b = await call('status', r); return { ...b.status, write_modes: b.write_modes }; } catch (e) { console.log(String(e.message)); return null; } },
  update: async (r) => { try { return (await call('update', r)).result; } catch (e) { console.log(String(e.message)); return { outcome: 'request_failed' }; } },
  verify: async (r, version, baseline) => { try { return (await call('verify', r, { expect: version, baseline })).verify; } catch (e) { console.log(String(e.message)); return null; } },
  rollback: async (r) => (await call('rollback', r)).result,
};

if (cmd === 'start') {
  const changelog = arg2 && existsSync(arg2) ? readFileSync(arg2, 'utf8').slice(0, 1500) : '';
  write(file, startCandidate(existsSync(file) ? read(file) : {}, read(arg), now, changelog));
} else if (cmd === 'step') {
  const r = await step(read(file), api, now);
  for (const e of r.events) console.log(JSON.stringify(e));
  if (r.changed) write(file, r.manifest);
  out('changed', r.changed ? 'true' : 'false');
  const halted = r.events.find((e) => e.type === 'halted');
  out('halted', halted ? 'true' : 'false');
  if (halted) out('halt_reason', String(halted.reason).replace(/[\r\n]+/g, ' '));
  out('completed', r.events.some((e) => e.type === 'completed') ? 'true' : 'false');
  console.log(describe(r.manifest));
} else if (cmd === 'control') {
  write(file, control(read(file), arg, now));
  console.log(describe(read(file)));
} else if (cmd === 'describe') {
  console.log(describe(read(file)));
} else {
  console.error('unknown command'); process.exit(2);
}
