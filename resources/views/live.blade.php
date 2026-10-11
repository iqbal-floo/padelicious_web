<!DOCTYPE html>
<html lang="en" data-match="{{ $matchId }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Live Score · Padelicious</title>
<style>
  :root {
    color-scheme: light dark;
    --bg: #0d1117;
    --surface: #161b22;
    --surface-alt: #1f2630;
    --text: #e6edf3;
    --muted: #8b949e;
    --accent: #3fb950;
    --serve: #d29922;
    --line: #30363d;
  }
  * { box-sizing: border-box; }
  body {
    margin: 0;
    background: var(--bg);
    color: var(--text);
    font: 16px/1.4 -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif;
    padding-block: 24px;
    padding-inline: 16px;
    display: flex;
    justify-content: center;
  }
  .wrap { width: 100%; max-width: 560px; }
  .meta { text-align: center; color: var(--muted); font-size: 14px; margin-bottom: 4px; }
  .session { text-align: center; font-size: 20px; font-weight: 650; margin-bottom: 20px; }
  .status {
    display: inline-block; font-size: 12px; font-weight: 700; letter-spacing: .06em;
    text-transform: uppercase; padding: 3px 9px; border-radius: 999px;
    background: var(--surface-alt); color: var(--muted);
  }
  .status[data-s="live"] { background: rgba(63,185,80,.16); color: var(--accent); }
  .status[data-s="finished"] { background: rgba(110,118,129,.2); color: var(--muted); }
  .card {
    background: var(--surface); border: 1px solid var(--line);
    border-radius: 14px; padding: 18px; margin-bottom: 12px;
    display: flex; align-items: center; gap: 14px;
    transition: background .25s ease;
  }
  .card.flash { background: var(--surface-alt); }
  .card.winner { border-color: var(--accent); }
  .names { flex: 1; min-width: 0; }
  .name { font-size: 17px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .serve-dot {
    display: inline-block; width: 8px; height: 8px; border-radius: 50%;
    background: var(--serve); margin-right: 7px; vertical-align: middle;
  }
  .scores { display: flex; gap: 10px; align-items: center; }
  .games, .points {
    min-width: 54px; text-align: center; border-radius: 10px; padding: 8px 6px;
    font-variant-numeric: tabular-nums;
  }
  .games { background: var(--surface-alt); font-size: 26px; font-weight: 700; }
  .points { background: transparent; font-size: 26px; font-weight: 700; color: var(--accent); }
  .lbl { display: block; font-size: 10px; font-weight: 700; letter-spacing: .08em; color: var(--muted); text-transform: uppercase; }
  .foot { text-align: center; color: var(--muted); font-size: 13px; margin-top: 18px; }
  .dot { display: inline-block; width: 7px; height: 7px; border-radius: 50%; background: var(--muted); margin-right: 6px; }
  .dot.on { background: var(--accent); }
  @media (prefers-color-scheme: light) {
    :root:not([data-theme="dark"]) {
      --bg: #f6f8fa; --surface: #fff; --surface-alt: #eef1f4;
      --text: #1f2328; --muted: #656d76; --line: #d8dee4;
    }
  }
</style>
</head>
<body>
<div class="wrap">
  <div class="meta" id="meta">Court · Round</div>
  <div class="session" id="session">Loading…</div>
  <div style="text-align:center;margin-bottom:16px"><span class="status" id="status">connecting</span></div>

  <div class="card" id="team-a">
    <div class="names"><div class="name" id="name-a">—</div></div>
    <div class="scores">
      <div class="games"><span class="lbl">Games</span><span id="games-a">0</span></div>
      <div class="points"><span class="lbl">Points</span><span id="points-a">0</span></div>
    </div>
  </div>

  <div class="card" id="team-b">
    <div class="names"><div class="name" id="name-b">—</div></div>
    <div class="scores">
      <div class="games"><span class="lbl">Games</span><span id="games-b">0</span></div>
      <div class="points"><span class="lbl">Points</span><span id="points-b">0</span></div>
    </div>
  </div>

  <div class="foot"><span class="dot" id="dot"></span><span id="conn">connecting…</span></div>
</div>

<script>
(function () {
  var matchId = document.documentElement.dataset.match;
  var base = '/api/v1/live/matches/' + encodeURIComponent(matchId);
  var prev = {};
  var es = null;

  function $(id) { return document.getElementById(id); }

  function names(team) {
    if (!team || !team.players || !team.players.length) return '—';
    return team.players.map(function (p) { return p.display_name; }).join(' / ');
  }

  function render(d) {
    var a = (d.teams || [])[0], b = (d.teams || [])[1];

    $('session').textContent = (d.session && d.session.name) || 'Match';
    var bits = [];
    if (d.court && d.court.label) bits.push(d.court.label);
    if (d.round_number) bits.push('Round ' + d.round_number);
    $('meta').textContent = bits.join(' · ') || '—';

    $('status').textContent = d.status || '—';
    $('status').dataset.s = d.status || '';

    $('name-a').innerHTML = (d.serving_team === 'a' ? '<span class="serve-dot"></span>' : '') + escapeHtml(names(a));
    $('name-b').innerHTML = (d.serving_team === 'b' ? '<span class="serve-dot"></span>' : '') + escapeHtml(names(b));

    setVal('games-a', d.games && d.games.a);
    setVal('games-b', d.games && d.games.b);
    setVal('points-a', d.points && d.points.a);
    setVal('points-b', d.points && d.points.b);

    $('team-a').classList.toggle('winner', d.winner_team === 'a');
    $('team-b').classList.toggle('winner', d.winner_team === 'b');
  }

  // Flash the card whose number just changed, so a point is visible.
  function setVal(id, value) {
    var v = (value === null || value === undefined) ? '0' : String(value);
    var el = $(id);
    if (el.textContent !== v) {
      el.textContent = v;
      var card = el.closest('.card');
      if (card && prev[id] !== undefined) {
        card.classList.add('flash');
        setTimeout(function () { card.classList.remove('flash'); }, 450);
      }
    }
    prev[id] = v;
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function conn(on, text) {
    $('dot').classList.toggle('on', !!on);
    $('conn').textContent = text;
  }

  function connect() {
    if (es) es.close();
    es = new EventSource(base + '/stream');

    es.addEventListener('score', function (e) {
      conn(true, 'live');
      try { render(JSON.parse(e.data)); } catch (err) {}
    });

    es.addEventListener('closed', function () {
      conn(false, 'reconnecting…');
    });

    es.onopen = function () { conn(true, 'live'); };

    es.onerror = function () {
      conn(false, 'reconnecting…');
      // EventSource retries on its own; re-open if it gave up.
      if (es.readyState === 2) setTimeout(connect, 3000);
    };
  }

  // Paint immediately from a one-shot fetch, then stream.
  fetch(base, { headers: { Accept: 'application/json' } })
    .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
    .then(function (j) { render(j.data); })
    .catch(function () { $('session').textContent = 'Match not found'; })
    .finally(connect);
})();
</script>
</body>
</html>
