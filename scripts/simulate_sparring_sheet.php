#!/usr/bin/env php
<?php

/**
 * Reproduces the Padelicious vs Terpadel order of play (18 Oct 2026)
 * exactly as printed — one session, 3 courts, 15 time slots, 43 matches,
 * men to 5 games and women to 4 with a golden-point decider at 2-2 —
 * by entering every round by hand through the API, then playing every
 * point until the last match, and exporting the sheet as PDF.
 *
 * Usage:
 *   php scripts/simulate_sparring_sheet.php [--base=http://127.0.0.1:8000] [--out=DIR]
 *       [--email=demo.player@example.com] [--password=secret123] [--seed=2026] [--no-play]
 *
 * Needs the roster:  php artisan db:seed --class=SparringRosterSeeder
 */
declare(strict_types=1);

$opts = getopt('', ['base::', 'out::', 'email::', 'password::', 'seed::', 'no-play']);
$base = rtrim($opts['base'] ?? (getenv('API_BASE_URL') ?: 'http://127.0.0.1:8000'), '/');
$out = rtrim($opts['out'] ?? getcwd(), '/');
$email = $opts['email'] ?? 'demo.player@example.com';
$password = $opts['password'] ?? 'secret123';
$play = ! isset($opts['no-play']);
mt_srand((int) ($opts['seed'] ?? 2026));

/** The sheet, row for row: time, court, category, Padelicious pair, Terpadel pair. */
const SHEET = [
    ['08:15', 1, 'M', 'Dikdoyo & Reyhan', 'Hafid & Kautsar'],
    ['08:15', 2, 'W', 'Fuji & Dini', 'Lala & Shela'],
    ['08:15', 3, 'M', 'Akhmad & Fuad', 'Ivan & Agil'],
    ['08:30', 1, 'M', 'Mahdi & Afin', 'Hijrin & Denis'],
    ['08:30', 2, 'W', 'Risky & Aghnia', 'Shafira & Nisa'],
    ['08:30', 3, 'M', 'Dikdoyo & Reyhan', 'Afkar & Hadzieq'],
    ['08:45', 1, 'M', 'Akhmad & Fuad', 'Rezha & Boy'],
    ['08:45', 2, 'W', 'Fuji & Dini', 'Azmil & Nadya'],
    ['08:45', 3, 'M', 'Alan & Risan', 'Hafid & Kautsar'],
    ['09:00', 1, 'M', 'Ekkow & Nagib', 'Afkar & Hadzieq'],
    ['09:00', 2, 'W', 'Sarah & Erlina', 'Lala & Shela'],
    ['09:00', 3, 'M', 'Dikdoyo & Reyhan', 'Ivan & Agil'],
    ['09:15', 1, 'M', 'Mahdi & Afin', 'Rezha & Boy'],
    ['09:15', 2, 'W', 'Risky & Aghnia', 'Azmil & Nadya'],
    ['09:15', 3, 'M', 'Akhmad & Fuad', 'Hafid & Kautsar'],
    ['09:30', 1, 'M', 'Dikdoyo & Reyhan', 'Hijrin & Denis'],
    ['09:30', 2, 'W', 'Sarah & Erlina', 'Shafira & Nisa'],
    ['09:30', 3, 'M', 'Alan & Risan', 'Afkar & Hadzieq'],
    ['09:45', 1, 'M', 'Mahdi & Afin', 'Hafid & Kautsar'],
    ['09:45', 2, 'W', 'Risky & Aghnia', 'Lala & Shela'],
    ['09:45', 3, 'M', 'Dikdoyo & Reyhan', 'Rezha & Boy'],
    ['10:00', 1, 'M', 'Akhmad & Fuad', 'Afkar & Hadzieq'],
    ['10:00', 2, 'W', 'Fuji & Dini', 'Shafira & Nisa'],
    ['10:00', 3, 'M', 'Ekkow & Nagib', 'Hijrin & Denis'],
    ['10:15', 1, 'M', 'Mahdi & Afin', 'Ivan & Agil'],
    ['10:15', 2, 'W', 'Sarah & Erlina', 'Azmil & Nadya'],
    ['10:15', 3, 'M', 'Alan & Risan', 'Rezha & Boy'],
    ['10:30', 1, 'M', 'Ekkow & Nagib', 'Hafid & Kautsar'],
    ['10:30', 2, 'W', 'Risky & Aghnia', 'Shafira & Nisa'],
    ['10:30', 3, 'M', 'Akhmad & Fuad', 'Hijrin & Denis'],
    ['10:45', 1, 'M', 'Alan & Risan', 'Ivan & Agil'],
    ['10:45', 2, 'W', 'Sarah & Erlina', 'Lala & Shela'],
    ['10:45', 3, 'W', 'Fuji & Dini', 'Azmil & Nadya'],
    ['11:00', 1, 'M', 'Ekkow & Nagib', 'Rezha & Boy'],
    ['11:00', 3, 'M', 'Mahdi & Afin', 'Afkar & Hadzieq'],
    ['11:15', 1, 'W', 'Sarah & Erlina', 'Shafira & Nisa'],
    ['11:15', 2, 'W', 'Risky & Aghnia', 'Azmil & Nadya'],
    ['11:15', 3, 'W', 'Fuji & Dini', 'Lala & Shela'],
    ['11:30', 1, 'M', 'Alan & Risan', 'Hijrin & Denis'],
    ['11:30', 3, 'M', 'Ekkow & Nagib', 'Ivan & Agil'],
    ['11:45', 1, 'W', 'Sarah & Erlina', 'Azmil & Nadya'],
    ['11:45', 2, 'W', 'Risky & Aghnia', 'Lala & Shela'],
    ['11:45', 3, 'W', 'Fuji & Dini', 'Shafira & Nisa'],
];
const RESERVES = ['Iqbal', 'Hardian'];
const START = '08:15';
const SLOT_MINUTES = 15;

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
        CURLOPT_TIMEOUT => 60,
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

function slotOf(string $time): int
{
    [$h, $m] = array_map('intval', explode(':', $time));
    [$sh, $sm] = array_map('intval', explode(':', START));

    return intdiv(($h * 60 + $m) - ($sh * 60 + $sm), SLOT_MINUTES) + 1;
}

// 1. Sign in.
[$status, $json] = api('POST', "{$base}/api/v1/auth/login", ['email' => $email, 'password' => $password]);
if ($status !== 200) {
    fail("login as {$email} failed ({$status})");
}
$token = $json['token'];
$hostPlayerId = $json['user']['player']['id'] ?? fail('host account has no player profile');

// 2. Every name on the sheet, plus the reserves, as player ids.
$names = [];
foreach (SHEET as [, , , $a, $b]) {
    foreach ([$a, $b] as $pair) {
        foreach (explode(' & ', $pair) as $name) {
            $names[$name] = true;
        }
    }
}
foreach (RESERVES as $name) {
    $names[$name] = true;
}

$byName = [];
$page = 1;
do {
    $json = must(api('GET', "{$base}/api/v1/players?per_page=100&page={$page}", null, $token), 'players');
    foreach ($json['data'] as $player) {
        $byName[$player['display_name']] = $player['id'];
    }
    $page++;
} while (! empty($json['links']['next']));

$missing = array_keys(array_diff_key($names, $byName));
if ($missing) {
    fail('players not found: '.implode(', ', $missing).'  — run: php artisan db:seed --class=SparringRosterSeeder');
}

// 3. One session for the whole day: 3 courts, men's rule as the default.
$session = must(api('POST', "{$base}/api/v1/sessions", [
    'name' => 'Sparing Padelicious vs Terpadel',
    'icon' => '🎾',
    'match_type_code' => 'team_americano',
    'scheduled_date' => '2026-10-18',
    'court_count' => 3,
    'scoring_type' => 'tennis',
    'tennis_mode' => 'total5',
    'golden_point' => true,
    'participants' => array_map(fn ($n) => ['player_id' => $byName[$n]], array_keys($names)),
], $token), 'create session')['data'];
$sid = $session['id'];

$participantOf = [];
foreach ($session['participants'] as $participant) {
    if ($participant['player_id'] === $hostPlayerId) {
        // The host is on every roster by default but is not playing today.
        must(api('DELETE', "{$base}/api/v1/sessions/{$sid}/participants/{$participant['id']}", null, $token), 'withdraw host');

        continue;
    }
    $participantOf[$participant['display_name']] = $participant['id'];
}
printf("session %s: %d on the roster (%d reserves)\n", $sid, count($participantOf), count(RESERVES));

// 4. Enter the sheet round by round (one round per 15-minute slot).
$bySlot = [];
foreach (SHEET as [$time, $court, $cat, $a, $b]) {
    $bySlot[slotOf($time)][] = [
        'court' => $court,
        // Women play to 4 games (2-2: golden point); men use the session's total5.
        'tennis_mode' => $cat === 'W' ? 'total4' : null,
        'team_a' => array_map(fn ($n) => $participantOf[$n], explode(' & ', $a)),
        'team_b' => array_map(fn ($n) => $participantOf[$n], explode(' & ', $b)),
    ];
}
ksort($bySlot);

$matchIds = [];
foreach ($bySlot as $slot => $matches) {
    [$status, $json] = api('POST', "{$base}/api/v1/sessions/{$sid}/rounds", ['round_number' => $slot, 'matches' => $matches], $token);
    if ($status !== 201) {
        fail("round {$slot} -> HTTP {$status}: ".($json['message'] ?? json_encode($json)));
    }
    $round = $json['data'];
    printf("round %2d (%s) %-11s %d match(es)\n", $slot, date('H:i', strtotime(START) + ($slot - 1) * SLOT_MINUTES * 60), $round['status'], count($round['matches']));
    foreach ($round['matches'] as $m) {
        $matchIds[] = $m['id'];
    }
}
printf("-- %d rounds entered, %d matches\n", count($bySlot), count($matchIds));

if (! $play) {
    exit(0);
}

// 5. Play the day: every point through the API, round by round.
$rounds = must(api('GET', "{$base}/api/v1/sessions/{$sid}/rounds", null, $token), 'rounds')['data'];
usort($rounds, fn ($x, $y) => $x['round_number'] <=> $y['round_number']);

$strength = [];
$points = 0;
$deciders = 0;
$played = 0;
printf("\n%-3s %-5s %-5s %-4s %-18s %-7s %-18s %s\n", 'No', 'Jam', 'Court', 'Kat', 'Padelicious', 'Skor', 'Terpadel', 'GP');

foreach ($rounds as $round) {
    $matches = $round['matches'];
    usort($matches, fn ($x, $y) => $x['court']['court_number'] <=> $y['court']['court_number']);

    foreach ($matches as $match) {
        $names = [];
        foreach ($match['teams'] as $team) {
            $names[$team['side']] = implode(' & ', array_column($team['players'], 'display_name'));
        }
        foreach ($names as $pair) {
            $strength[$pair] ??= mt_rand(35, 65);
        }
        $chanceA = $strength[$names['a']] / ($strength[$names['a']] + $strength[$names['b']]);

        $state = $match;
        $guard = 0;
        while (($state['status'] ?? '') !== 'finished' && $guard++ < 300) {
            $side = (mt_rand() / mt_getrandmax()) < $chanceA ? 'a' : 'b';
            $state = must(api('POST', "{$base}/api/v1/matches/{$match['id']}/points", ['team' => $side], $token), 'point')['data'];
            $points++;
        }
        if (($state['status'] ?? '') !== 'finished') {
            fail("match {$match['id']} did not finish");
        }

        $level = $state['games_a'] === $state['games_b'];
        $deciders += $level ? 1 : 0;
        $played++;
        $cat = ($match['tennis_mode'] ?? 'total5') === 'total4' ? 'W' : 'M';
        printf(
            "%-3d %-5s C%-4d %-4s %-18s %d – %-3d %-18s %s\n",
            $played,
            date('H:i', strtotime(START) + ($round['round_number'] - 1) * SLOT_MINUTES * 60),
            $match['court']['court_number'], $cat, $names['a'],
            $state['games_a'], $state['games_b'], $names['b'],
            $level ? 'GP → '.strtoupper($state['winner_team']) : '–',
        );
    }

    must(api('POST', "{$base}/api/v1/rounds/{$round['id']}/complete", null, $token), 'complete round');
}

printf("-- %d matches played · %d points entered · %d golden-point deciders\n", $played, $points, $deciders);

// 6. Export.
$pdfUrl = "{$base}/sessions/{$sid}/order-of-play?format=pdf&start=".START.'&slot='.SLOT_MINUTES;
$ch = curl_init($pdfUrl);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60]);
$pdf = (string) curl_exec($ch);
curl_close($ch);
if (! str_starts_with($pdf, '%PDF')) {
    fail('order of play export did not return a PDF');
}
$file = "{$out}/order-of-play-sparing.pdf";
file_put_contents($file, $pdf);
printf("-- html: %s/sessions/%s/order-of-play?start=%s\n-- pdf : %s (%d bytes)\n", $base, $sid, START, $file, strlen($pdf));
