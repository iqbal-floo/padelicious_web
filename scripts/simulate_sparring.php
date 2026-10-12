#!/usr/bin/env php
<?php

/**
 * Plays out the Padelicious vs Terpadel sparring sheet as two Team
 * Americano sessions — men on 2 courts (total 5 games), women on 1 court
 * (total 4 games, 2-2 decided by a golden point) — entering every single
 * point through the same HTTP API the app uses, until every pair has met
 * every other pair. Each session's order of play is then downloaded as PDF.
 *
 * Usage:
 *   php scripts/simulate_sparring.php [--base=http://127.0.0.1:8000] [--out=DIR]
 *       [--email=demo.player@example.com] [--password=secret123]
 *       [--max-rounds=60] [--seed=2026]
 *
 * Needs the roster:  php artisan db:seed --class=SparringRosterSeeder
 */
declare(strict_types=1);

$opts = getopt('', ['base::', 'out::', 'email::', 'password::', 'max-rounds::', 'seed::']);

$ctx = [
    'base' => rtrim($opts['base'] ?? (getenv('API_BASE_URL') ?: 'http://127.0.0.1:8000'), '/'),
    'out' => rtrim($opts['out'] ?? getcwd(), '/'),
    'maxRounds' => (int) ($opts['max-rounds'] ?? 60),
];
$email = $opts['email'] ?? 'demo.player@example.com';
$password = $opts['password'] ?? 'secret123';

// Deterministic, so a re-run reproduces the same sheet.
mt_srand((int) ($opts['seed'] ?? 2026));

const MEN_PAIRS = [
    ['Dikdoyo', 'Reyhan'], ['Akhmad', 'Fuad'], ['Mahdi', 'Afin'], ['Alan', 'Risan'], ['Ekkow', 'Nagib'],
    ['Hafid', 'Kautsar'], ['Ivan', 'Agil'], ['Hijrin', 'Denis'], ['Afkar', 'Hadzieq'], ['Rezha', 'Boy'],
];
const WOMEN_PAIRS = [
    ['Fuji', 'Dini'], ['Risky', 'Aghnia'], ['Sarah', 'Erlina'],
    ['Lala', 'Shela'], ['Shafira', 'Nisa'], ['Azmil', 'Nadya'],
];

function fail(string $message): never
{
    fwrite(STDERR, "ERROR: {$message}\n");
    exit(1);
}

/** @return array{0: int, 1: array<string, mixed>} */
function api(string $method, string $url, ?array $body = null, ?string $token = null): array
{
    $headers = ['Accept: application/json', 'Content-Type: application/json'];
    if ($token) {
        $headers[] = "Authorization: Bearer {$token}";
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $raw = curl_exec($ch);
    if ($raw === false) {
        fail('curl: '.curl_error($ch));
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return [$status, json_decode((string) $raw, true) ?? []];
}

/** @return array<string, mixed> */
function must(array $response, string $what): array
{
    [$status, $json] = $response;
    if ($status < 200 || $status >= 300) {
        fail("{$what} -> HTTP {$status}: ".($json['message'] ?? json_encode($json)));
    }

    return $json;
}

// 1. Sign in as the host (register one if the demo account is absent).
[$status, $json] = api('POST', "{$ctx['base']}/api/v1/auth/login", ['email' => $email, 'password' => $password]);
if ($status !== 200) {
    echo "login as {$email} failed ({$status}); registering sparring.host@example.com\n";
    $json = must(api('POST', "{$ctx['base']}/api/v1/auth/register", [
        'full_name' => 'Sparring Host',
        'username' => 'sparring.host',
        'email' => 'sparring.host@example.com',
        'password' => 'secret123',
        'password_confirmation' => 'secret123',
    ]), 'register');
}
$ctx['token'] = $json['token'];
$ctx['hostPlayerId'] = $json['user']['player']['id'] ?? fail('host account has no player profile');

// 2. Resolve every name on the sheet to a player id.
$byName = [];
$page = 1;
do {
    $json = must(api('GET', "{$ctx['base']}/api/v1/players?per_page=100&page={$page}", null, $ctx['token']), 'players');
    foreach ($json['data'] as $player) {
        $byName[$player['display_name']] = $player['id'];
    }
    $page++;
} while (! empty($json['links']['next']));

$missing = [];
foreach (array_merge(MEN_PAIRS, WOMEN_PAIRS) as $pair) {
    foreach ($pair as $name) {
        if (! isset($byName[$name])) {
            $missing[] = $name;
        }
    }
}
if ($missing) {
    fail('players not found: '.implode(', ', $missing).'  — run: php artisan db:seed --class=SparringRosterSeeder');
}
$ctx['byName'] = $byName;

// 3. Play both sessions to completion.
foreach ([
    ['name' => 'Sparing Padelicious vs Terpadel — Cowok', 'pairs' => MEN_PAIRS, 'courts' => 2, 'mode' => 'total5', 'file' => 'cowok'],
    ['name' => 'Sparing Padelicious vs Terpadel — Cewek', 'pairs' => WOMEN_PAIRS, 'courts' => 1, 'mode' => 'total4', 'file' => 'cewek'],
] as $spec) {
    playSession($ctx, $spec);
}

/**
 * @param  array<string, mixed>  $ctx
 * @param  array{name: string, pairs: list<array{0: string, 1: string}>, courts: int, mode: string, file: string}  $spec
 */
function playSession(array $ctx, array $spec): void
{
    $base = $ctx['base'];
    $token = $ctx['token'];

    $participants = [];
    foreach ($spec['pairs'] as [$a, $b]) {
        $participants[] = ['player_id' => $ctx['byName'][$a]];
        $participants[] = ['player_id' => $ctx['byName'][$b]];
    }

    $session = must(api('POST', "{$base}/api/v1/sessions", [
        'name' => $spec['name'],
        'icon' => '🎾',
        'match_type_code' => 'team_americano',
        'scheduled_date' => '2026-10-18',
        'court_count' => $spec['courts'],
        'scoring_type' => 'tennis',
        'tennis_mode' => $spec['mode'],
        'golden_point' => true,
        'participants' => $participants,
    ], $token), 'create session')['data'];
    $sid = $session['id'];

    // The host is added to every roster automatically; they are not
    // playing today, so withdraw them before pairs are formed.
    foreach ($session['participants'] as $participant) {
        if ($participant['player_id'] === $ctx['hostPlayerId']) {
            must(api('DELETE', "{$base}/api/v1/sessions/{$sid}/participants/{$participant['id']}", null, $token), 'withdraw host');
        }
    }

    $teamCount = count($spec['pairs']);
    $target = intdiv($teamCount * ($teamCount - 1), 2);

    // A hidden strength per pair keeps the results varied but reproducible.
    $strength = [];
    foreach ($spec['pairs'] as $pair) {
        $strength[implode(' & ', $pair)] = mt_rand(35, 65);
    }

    $met = [];
    $no = 0;
    $rounds = 0;
    $points = 0;
    $deciders = 0;

    printf("\n== %s  (%d pairs · %d court · %s) ==\n", $spec['name'], $teamCount, $spec['courts'], $spec['mode']);
    printf("%-3s %-5s %-5s %-20s %-7s %-20s %s\n", 'No', 'Ronde', 'Court', 'Tim A', 'Skor', 'Tim B', 'GP');

    while (count($met) < $target && $rounds < $ctx['maxRounds']) {
        [$status, $json] = api('POST', "{$base}/api/v1/sessions/{$sid}/rounds", null, $token);
        if ($status !== 201) {
            fail("generate round -> HTTP {$status}: ".($json['message'] ?? ''));
        }
        $round = $json['data'];
        $rounds++;

        foreach ($round['matches'] as $match) {
            $teams = [];
            foreach ($match['teams'] as $team) {
                $teams[$team['side']] = implode(' & ', array_column($team['players'], 'display_name'));
            }
            $key = $teams['a'] < $teams['b'] ? "{$teams['a']}|{$teams['b']}" : "{$teams['b']}|{$teams['a']}";
            $met[$key] = ($met[$key] ?? 0) + 1;

            $chanceA = $strength[$teams['a']] / ($strength[$teams['a']] + $strength[$teams['b']]);

            // Enter points one at a time, exactly as the score screen does,
            // until the server says the match is finished.
            $state = $match;
            $guard = 0;
            while (($state['status'] ?? '') !== 'finished' && $guard++ < 300) {
                $side = (mt_rand() / mt_getrandmax()) < $chanceA ? 'a' : 'b';
                $state = must(api('POST', "{$base}/api/v1/matches/{$match['id']}/points", ['team' => $side], $token), 'point')['data'];
                $points++;
            }
            if (($state['status'] ?? '') !== 'finished') {
                fail("match {$match['id']} did not finish within 300 points");
            }

            $level = $state['games_a'] === $state['games_b'];
            if ($level) {
                $deciders++;
            }

            $no++;
            printf(
                "%-3d %-5d C%-4d %-20s %d – %-3d %-20s %s\n",
                $no, $round['round_number'], $match['court']['court_number'],
                $teams['a'], $state['games_a'], $state['games_b'], $teams['b'],
                $level ? 'GP → '.strtoupper($state['winner_team']) : '–',
            );
        }

        must(api('POST', "{$base}/api/v1/rounds/{$round['id']}/complete", null, $token), 'complete round');
    }

    $repeats = array_sum(array_map(fn (int $c) => $c - 1, $met));
    printf(
        "-- %d rounds · %d matches · %d points entered · %d golden-point deciders · %d/%d pairings met · %d repeat matchups\n",
        $rounds, $no, $points, $deciders, count($met), $target, $repeats,
    );

    $board = must(api('GET', "{$base}/api/v1/sessions/{$sid}/leaderboard", null, $token), 'leaderboard')['data'] ?? [];
    echo '-- top 4: ';
    foreach (array_slice($board, 0, 4) as $row) {
        $name = $row['display_name'] ?? ($row['participant']['display_name'] ?? '?');
        printf('#%s %s (%s pts) ', $row['rank'] ?? '?', $name, $row['points'] ?? '?');
    }
    echo "\n";

    // 4. Export the sheet.
    $pdfUrl = "{$base}/sessions/{$sid}/order-of-play?format=pdf&start=08:15&slot=15";
    $ch = curl_init($pdfUrl);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60]);
    $pdf = (string) curl_exec($ch);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if (! str_starts_with($pdf, '%PDF')) {
        fail("order of play export did not return a PDF ({$contentType})");
    }

    $file = "{$ctx['out']}/order-of-play-{$spec['file']}.pdf";
    file_put_contents($file, $pdf);

    printf("-- session %s\n   html: %s/sessions/%s/order-of-play?start=08:15\n   pdf : %s (%d bytes)\n", $sid, $base, $sid, $file, strlen($pdf));
}
