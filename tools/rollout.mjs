// Staged rollout of a signed plugin release: test property first, then the
// live properties one at a time, each only after the previous one was updated,
// verified healthy and left to soak. Any failure halts the rollout; sites that
// were not yet reached never see the release.
//
// State lives in update.json on main (`rollout.candidate`). This module is the
// decision logic only: `step()` takes the manifest, the current time and an
// `api` (status / update / verify / rollback of ONE site, served by the Matova
// OS backend with that site's own key) and returns the changed manifest.
// It never talks to a site directly and holds no credentials.

export const SITES = [
  { resort: 'borny',    host: 'chatkyborny.cz',   soakMinutes: 120, test: true },
  { resort: 'passage',  host: 'passagesplavy.cz', soakMinutes: 60 },
  { resort: 'srdicko',  host: 'kempsrdicko.cz',   soakMinutes: 60 },
  { resort: 'slunicko', host: 'kempslunicko.cz',  soakMinutes: 30 },
];
/** raw.githubusercontent.com caches for ~5 min: act on an assignment only after this. */
export const MANIFEST_SETTLE_MINUTES = 7;
/** Consecutive failed observations before the rollout halts. */
export const MAX_STRIKES = 3;

const RELEASE_KEYS = ['version', 'download_url', 'sha256', 'signature'];
const clone = (v) => JSON.parse(JSON.stringify(v));
const minutesBetween = (a, b) => (Date.parse(b) - Date.parse(a)) / 60000;
export const validRelease = (r) => !!r && RELEASE_KEYS.every((k) => typeof r[k] === 'string' && r[k].length > 0) && /^\d+\.\d+\.\d+$/.test(r.version);

/** Start a rollout of `release` (called by the release workflow after signing). */
export function startCandidate(manifest, release, now, changelog = '') {
  if (!validRelease(release)) throw new Error('release is missing version/download_url/sha256/signature');
  const m = clone(manifest ?? {});
  const rollout = m.rollout && typeof m.rollout === 'object' ? m.rollout : {};
  const stable = validRelease(rollout.default) ? rollout.default : null;
  // A superseded candidate is withdrawn from every site that has not finished it.
  rollout.by_site = {};
  rollout.candidate = {
    release: pick(release), started_at: now, stage: 0, phase: 'assign', phase_at: now,
    verified: {}, baseline: {}, strikes: 0, halted: null, done_at: null, note: null,
  };
  if (stable) rollout.default = stable; else delete rollout.default;
  m.rollout = rollout;
  // Builds that predate staged rollouts (<= 2.3.x) read only the top level.
  // Until a staged release exists they must be offered the candidate, or they
  // could never reach a build that understands staging.
  if (!stable) Object.assign(m, pick(release));
  if (changelog) m.changelog = changelog;
  for (const k of ['requires', 'tested', 'requires_php']) if (!m[k]) m[k] = { requires: '5.8', tested: '6.7', requires_php: '7.4' }[k];
  return m;
}

function pick(r) { const o = {}; for (const k of RELEASE_KEYS) o[k] = r[k]; return o; }

function halt(m, c, sites, reason, now, events) {
  c.halted = { reason, at: now, stage: c.stage, site: sites[c.stage]?.resort ?? null };
  // Withdraw the release from every site that has not been verified on it.
  for (const s of sites) if (!c.verified[s.resort]) delete m.rollout.by_site[s.host];
  events.push({ type: 'halted', reason, site: c.halted.site });
}

/**
 * One rollout step. Returns { manifest, changed, events }.
 * `api`: { status(resort), update(resort), verify(resort, version, baseline), rollback(resort) }
 */
export async function step(manifest, api, now, sites = SITES) {
  const m = clone(manifest ?? {});
  const events = [];
  const c = m.rollout?.candidate;
  if (!c || c.halted || c.done_at || !validRelease(c.release)) return { manifest: m, changed: false, events };
  const before = JSON.stringify(m);
  const version = c.release.version;
  m.rollout.by_site ??= {};

  // All sites verified → the release becomes what every site runs.
  if (c.stage >= sites.length) {
    m.rollout.default = pick(c.release);
    m.rollout.by_site = {};
    Object.assign(m, pick(c.release));
    c.done_at = now; c.phase = 'done';
    events.push({ type: 'completed', version });
    return { manifest: m, changed: true, events };
  }

  const site = sites[c.stage];
  const strike = (reason) => {
    c.strikes = (c.strikes ?? 0) + 1; c.note = reason;
    if (c.strikes >= MAX_STRIKES) halt(m, c, sites, reason, now, events);
  };

  if (c.phase === 'assign') {
    m.rollout.by_site[site.host] = pick(c.release);
    c.phase = 'update'; c.phase_at = now; c.strikes = 0; c.note = null;
    events.push({ type: 'assigned', site: site.resort, version });
  } else if (c.phase === 'update') {
    if (minutesBetween(c.phase_at, now) >= MANIFEST_SETTLE_MINUTES) {
      const st = await api.status(site.resort);
      if (!st || !st.reachable) {
        strike(`${site.resort}: site did not answer`);
      } else {
        if (st.write_modes && !c.baseline[site.resort] && st.version !== version) c.baseline[site.resort] = st.write_modes;
        if (st.version === version) {
          c.phase = 'soak'; c.phase_at = now; c.strikes = 0; c.note = null;
          events.push({ type: 'updated', site: site.resort, version });
        } else if (!st.remote_update) {
          // First hop from a build without remote update control: the update is
          // offered in WordPress and installed there once; the rollout waits here.
          c.note = `${site.resort}: waiting for the first install from WordPress (build ${st.version} has no remote update)`;
        } else if (st.offered !== version) {
          strike(`${site.resort}: site is offered ${st.offered ?? 'nothing'}, expected ${version}`);
        } else {
          const r = await api.update(site.resort);
          const outcome = r?.outcome ?? 'request_failed';
          events.push({ type: 'update_result', site: site.resort, outcome, error: r?.error ?? null });
          if (outcome === 'updated' || (outcome === 'up_to_date' && r?.version === version)) {
            c.phase = 'soak'; c.phase_at = now; c.strikes = 0; c.note = null;
          } else if (outcome === 'busy') {
            c.note = `${site.resort}: update already running`;
          } else if (outcome === 'rolled_back' || outcome === 'failed') {
            // The site refused or reverted the release on its own: stop here.
            halt(m, c, sites, `${site.resort}: install ${outcome}${r?.error ? ` (${r.error})` : ''}`, now, events);
          } else {
            strike(`${site.resort}: update request ${outcome}`);
          }
        }
      }
    }
  } else if (c.phase === 'soak') {
    const v = await api.verify(site.resort, version, c.baseline[site.resort] ?? null);
    if (!v || !v.ok) {
      const failed = (v?.checks ?? []).filter((x) => !x.ok).map((x) => x.name).join(', ') || 'no answer';
      c.strikes = (c.strikes ?? 0) + 1; c.note = `${site.resort}: verification failed (${failed})`;
      if (c.strikes >= MAX_STRIKES) {
        const rb = await api.rollback(site.resort).catch(() => null);
        events.push({ type: 'rollback', site: site.resort, outcome: rb?.outcome ?? 'request_failed' });
        halt(m, c, sites, `${c.note}; rollback ${rb?.outcome ?? 'request_failed'}`, now, events);
      }
    } else {
      c.strikes = 0; c.note = null;
      if (minutesBetween(c.phase_at, now) >= site.soakMinutes) {
        c.verified[site.resort] = now;
        c.stage += 1; c.phase = 'assign'; c.phase_at = now;
        events.push({ type: 'verified', site: site.resort, version });
      }
    }
  }
  return { manifest: m, changed: JSON.stringify(m) !== before, events };
}

/** Owner decisions: stop a rollout, or continue one that halted. */
export function control(manifest, action, now) {
  const m = clone(manifest ?? {});
  const c = m.rollout?.candidate;
  if (!c) return m;
  if (action === 'halt' && !c.halted && !c.done_at) {
    const events = []; halt(m, c, SITES, 'halted by owner', now, events);
  } else if (action === 'resume' && c.halted) {
    c.halted = null; c.strikes = 0; c.phase = 'assign'; c.phase_at = now; c.note = 'resumed';
  }
  return m;
}

/** One line per site for the workflow summary. */
export function describe(manifest) {
  const c = manifest?.rollout?.candidate;
  if (!c) return 'No rollout in progress.';
  const head = c.done_at ? `Release ${c.release.version}: rolled out to all sites.`
    : c.halted ? `Release ${c.release.version}: HALTED — ${c.halted.reason}`
    : `Release ${c.release.version}: in progress.`;
  const lines = SITES.map((s, i) => {
    const state = c.verified[s.resort] ? 'verified' : c.done_at ? 'verified' : i === c.stage && !c.halted ? c.phase : i < c.stage ? 'verified' : 'not reached';
    return `- ${s.resort}: ${state}`;
  });
  return [head, ...lines, c.note ? `Note: ${c.note}` : ''].filter(Boolean).join('\n');
}
