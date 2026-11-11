import test from 'node:test';
import assert from 'node:assert/strict';
import { SITES, MANIFEST_SETTLE_MINUTES, startCandidate, step, control, describe } from './rollout.mjs';

const rel = (v) => ({ version: v, download_url: `https://github.com/x/y/releases/download/v${v}/matova-hbook-api.zip`, sha256: 'a'.repeat(64), signature: 'sig-' + v });
const T0 = Date.parse('2026-11-12T08:00:00Z');
const at = (min) => new Date(T0 + min * 60000).toISOString();

/** Fake fleet: every site runs `version`; update installs what the manifest assigns. */
function fleet(start, opts = {}) {
  const sites = Object.fromEntries(SITES.map((s) => [s.resort, { version: start, remote: opts.remote ?? true }]));
  const calls = [];
  let manifest = null;
  const api = {
    setManifest: (m) => { manifest = m; },
    status: async (r) => {
      calls.push(`status:${r}`);
      if (opts.down?.includes(r)) return null;
      const host = SITES.find((s) => s.resort === r).host;
      const offered = manifest?.rollout?.by_site?.[host]?.version ?? manifest?.rollout?.default?.version ?? null;
      return { reachable: true, version: sites[r].version, remote_update: sites[r].remote, offered, write_modes: { payment: 'live' } };
    },
    update: async (r) => {
      calls.push(`update:${r}`);
      if (opts.breaks?.includes(r)) return { outcome: 'rolled_back', error: 'health_probe_failed', version: sites[r].version };
      const host = SITES.find((s) => s.resort === r).host;
      sites[r].version = manifest.rollout.by_site[host].version;
      return { outcome: 'updated', version: sites[r].version };
    },
    verify: async (r, version) => {
      calls.push(`verify:${r}`);
      const bad = opts.unhealthy?.includes(r);
      return { ok: !bad && sites[r].version === version, checks: [{ name: 'units_read', ok: !bad }] };
    },
    rollback: async (r) => { calls.push(`rollback:${r}`); sites[r].version = start; return { outcome: 'rolled_back' }; },
  };
  return { sites, calls, api };
}
async function run(m, f, minutes, everyMin = 15) {
  const events = [];
  for (let t = 0; t <= minutes; t += everyMin) {
    f.api.setManifest(m);
    const r = await step(m, f.api, at(t));
    m = r.manifest; events.push(...r.events.map((e) => ({ ...e, t })));
  }
  return { m, events };
}
const stable = (v) => ({ ...rel(v), rollout: { default: rel(v), by_site: {} } });

test('test property first, live sites strictly one at a time, then default', async () => {
  const f = fleet('2.4.0');
  let m = startCandidate(stable('2.4.0'), rel('2.4.1'), at(0));
  assert.equal(m.version, '2.4.0', 'top level stays on the stable release during a rollout');
  assert.deepEqual(m.rollout.by_site, {});
  const { m: end, events } = await run(m, f, 600);
  const updated = events.filter((e) => e.type === 'update_result').map((e) => e.site);
  assert.deepEqual(updated, ['borny', 'passage', 'srdicko', 'slunicko']);
  const verifiedAt = Object.fromEntries(events.filter((e) => e.type === 'verified').map((e) => [e.site, e.t]));
  const assignedAt = Object.fromEntries(events.filter((e) => e.type === 'assigned').map((e) => [e.site, e.t]));
  assert.ok(assignedAt.passage >= verifiedAt.borny, 'first live site is assigned only after the test property is verified');
  assert.ok(verifiedAt.borny - assignedAt.borny >= 120, 'test property soaks for two hours');
  assert.ok(assignedAt.slunicko >= verifiedAt.srdicko);
  assert.ok(end.rollout.candidate.done_at);
  assert.equal(end.rollout.default.version, '2.4.1');
  assert.equal(end.version, '2.4.1');
  assert.deepEqual(end.rollout.by_site, {});
  assert.ok(Object.values(f.sites).every((s) => s.version === '2.4.1'));
});

test('while the test property is in progress no live site is offered the release', async () => {
  const f = fleet('2.4.0');
  let m = startCandidate(stable('2.4.0'), rel('2.4.1'), at(0));
  ({ m } = await run(m, f, 90));
  assert.deepEqual(Object.keys(m.rollout.by_site), ['chatkyborny.cz']);
  assert.equal(m.rollout.default.version, '2.4.0');
  assert.equal(f.sites.slunicko.version, '2.4.0');
  assert.ok(!f.calls.some((c) => c.startsWith('update:') && c !== 'update:borny'));
});

test('does not act before the manifest change has propagated', async () => {
  const f = fleet('2.4.0');
  let m = startCandidate(stable('2.4.0'), rel('2.4.1'), at(0));
  f.api.setManifest(m); m = (await step(m, f.api, at(0))).manifest;                       // assign
  f.api.setManifest(m); m = (await step(m, f.api, at(MANIFEST_SETTLE_MINUTES - 2))).manifest;
  assert.equal(f.calls.length, 0);
});

test('a release the test property rejects never reaches a live site', async () => {
  const f = fleet('2.4.0', { breaks: ['borny'] });
  let m = startCandidate(stable('2.4.0'), rel('2.4.1'), at(0));
  const { m: end, events } = await run(m, f, 600);
  assert.ok(end.rollout.candidate.halted);
  assert.match(end.rollout.candidate.halted.reason, /borny: install rolled_back/);
  assert.deepEqual(end.rollout.by_site, {}, 'release withdrawn');
  assert.equal(end.rollout.default.version, '2.4.0');
  assert.ok(['passage', 'srdicko', 'slunicko'].every((r) => f.sites[r].version === '2.4.0'));
  assert.ok(!f.calls.some((c) => /^(update|verify):(passage|srdicko|slunicko)/.test(c)));
  assert.equal(events.filter((e) => e.type === 'halted').length, 1);
});

test('an unhealthy site after update is rolled back and the rollout halts', async () => {
  const f = fleet('2.4.0', { unhealthy: ['passage'] });
  let m = startCandidate(stable('2.4.0'), rel('2.4.1'), at(0));
  const { m: end } = await run(m, f, 900);
  assert.ok(end.rollout.candidate.halted);
  assert.ok(f.calls.includes('rollback:passage'));
  assert.equal(f.sites.passage.version, '2.4.0');
  assert.equal(f.sites.borny.version, '2.4.1', 'verified test property keeps the release');
  assert.equal(end.rollout.by_site['chatkyborny.cz'].version, '2.4.1');
  assert.equal(end.rollout.by_site['passagesplavy.cz'], undefined);
  assert.equal(f.sites.slunicko.version, '2.4.0');
});

test('a site that stops answering halts after repeated tries, not on the first', async () => {
  const f = fleet('2.4.0', { down: ['borny'] });
  let m = startCandidate(stable('2.4.0'), rel('2.4.1'), at(0));
  f.api.setManifest(m); m = (await step(m, f.api, at(0))).manifest;
  f.api.setManifest(m); m = (await step(m, f.api, at(15))).manifest;
  assert.equal(m.rollout.candidate.halted, null);
  ({ m } = await run(m, f, 120));
  assert.ok(m.rollout.candidate.halted);
});

test('first release: builds without remote update are offered it at top level and the rollout waits for them', async () => {
  const f = fleet('2.3.3', { remote: false });
  let m = startCandidate({ version: '2.3.3', download_url: 'https://raw/x.zip' }, rel('2.4.0'), at(0));
  assert.equal(m.version, '2.4.0');
  assert.equal(m.rollout.default, undefined);
  ({ m } = await run(m, f, 300));
  assert.equal(m.rollout.candidate.halted, null, 'waiting is not a failure');
  assert.equal(m.rollout.candidate.stage, 0);
  assert.match(m.rollout.candidate.note, /waiting for the first install/);
  assert.ok(!f.calls.some((c) => c.startsWith('update:')));
  // The first install happens in WordPress on the test property…
  f.sites.borny.version = '2.4.0'; f.sites.borny.remote = true;
  const r = await run(m, f, 200); m = r.m;
  assert.ok(m.rollout.candidate.verified.borny);
  assert.equal(m.rollout.candidate.stage, 1);
  assert.equal(f.sites.passage.version, '2.3.3');
});

test('owner can halt and resume; a new release supersedes a halted one', async () => {
  const f = fleet('2.4.0');
  let m = startCandidate(stable('2.4.0'), rel('2.4.1'), at(0));
  ({ m } = await run(m, f, 30));
  m = control(m, 'halt', at(31));
  assert.ok(m.rollout.candidate.halted);
  assert.deepEqual(m.rollout.by_site, {});
  const frozen = JSON.stringify(m);
  f.api.setManifest(m); assert.equal((await step(m, f.api, at(60))).changed, false);
  assert.equal(JSON.stringify(m), frozen);
  m = control(m, 'resume', at(61));
  assert.equal(m.rollout.candidate.halted, null);
  m = startCandidate(m, rel('2.4.2'), at(70));
  assert.equal(m.rollout.candidate.release.version, '2.4.2');
  assert.equal(m.rollout.candidate.stage, 0);
  assert.match(describe(m), /2\.4\.2: in progress/);
});

test('an incomplete release cannot start a rollout', () => {
  assert.throws(() => startCandidate(stable('2.4.0'), { version: '2.4.1', download_url: 'x', sha256: 'y' }, at(0)));
});
