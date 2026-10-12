<?php

namespace App\Services;

use App\Enums\ImportStatus;
use App\Enums\ParticipantStatus;
use App\Exceptions\ReclubImportException;
use App\Models\Player;
use App\Models\ReclubImport;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Imports the roster of a Reclub meet from its public page,
 * https://reclub.co/m/{code}.
 *
 * Reclub has no public API, so this reads the server-rendered page: each
 * list ("Confirmed · 19", "Waitlisted · 4") is a heading followed by a grid
 * of player links (/players/@handle) with an avatar and a name. Invited
 * players are only a count on the page and are not imported.
 *
 * Every imported person becomes (or is matched to) a guest player keyed by
 * their Reclub handle, so later imports reuse them.
 */
class ReclubImporter
{
    public const BASE_URL = 'https://reclub.co';

    /**
     * @return array{code: string, title: ?string, url: string, players: list<array{player: Player, status: string, reclub_username: ?string}>}
     *
     * @throws ReclubImportException
     */
    public function import(string $code, ?Player $requestedBy = null): array
    {
        $code = strtoupper(trim($code));

        if (! preg_match('/^[A-Z0-9]{4,12}$/', $code)) {
            throw new ReclubImportException('That does not look like a Reclub meet code.');
        }

        $log = ReclubImport::query()->create([
            'match_code' => $code,
            'requested_by_player_id' => $requestedBy?->id,
            'status' => ImportStatus::Pending,
            'created_at' => now(),
        ]);

        try {
            $html = $this->fetch($code);
            $meet = $this->parse($html);

            if ($meet['people'] === []) {
                throw new ReclubImportException('No confirmed or waitlisted players were found on that meet.');
            }

            $players = DB::transaction(fn () => array_map(fn (array $person) => [
                'player' => $this->resolve($person),
                'status' => $person['status'],
                'reclub_username' => $person['username'],
            ], $meet['people']));
        } catch (ReclubImportException $e) {
            $log->forceFill(['status' => ImportStatus::Failed, 'error_message' => $e->getMessage(), 'completed_at' => now()])->save();

            throw $e;
        }

        $log->forceFill([
            'status' => ImportStatus::Success,
            'imported_count' => count($players),
            'raw_response' => ['title' => $meet['title'], 'people' => $meet['people']],
            'completed_at' => now(),
        ])->save();

        return [
            'code' => $code,
            'title' => $meet['title'],
            'url' => self::BASE_URL."/m/{$code}",
            'players' => $players,
        ];
    }

    private function fetch(string $code): string
    {
        try {
            $response = Http::timeout(20)
                ->withHeaders(['User-Agent' => 'Padelicious/1.0 (+roster import)', 'Accept' => 'text/html'])
                ->get(self::BASE_URL."/m/{$code}");
        } catch (ConnectionException) {
            throw new ReclubImportException('Reclub could not be reached. Try again in a moment.');
        }

        if ($response->status() === 404) {
            throw new ReclubImportException("No Reclub meet was found for code {$code}.");
        }

        if (! $response->successful()) {
            throw new ReclubImportException("Reclub answered with an error ({$response->status()}).");
        }

        return $response->body();
    }

    /**
     * @return array{title: ?string, people: list<array{name: string, username: ?string, avatar_url: ?string, status: string}>}
     */
    public function parse(string $html): array
    {
        $dom = new DOMDocument;
        // The page is HTML5; libxml warns about its tags but parses fine.
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($dom);

        $title = trim((string) $xpath->evaluate('string(//title)'));
        $title = $title === '' ? null : preg_replace('/^Reclub\s+/u', '', $title);

        $people = [];
        $seen = [];

        // Each list starts at a <p> heading ("Confirmed · 19", "Waitlisted · 4")
        // and runs until the next heading. Confirmed is a grid of tiles;
        // the waitlist is a run of numbered rows. Both hold /players/@ links.
        foreach ($xpath->query('//p') as $heading) {
            $status = $this->listStatus($heading->textContent);

            if ($status === null) {
                continue;
            }

            foreach ($this->entriesAfter($heading, $xpath) as $entry) {
                $name = $this->entryName($entry, $xpath);
                if ($name === '') {
                    continue;
                }

                $href = (string) $xpath->evaluate("string(.//a[contains(@href, '/players/@')]/@href)", $entry)
                    ?: $entry->getAttribute('href');
                $username = preg_match('#/players/@([^/?\#"]+)#', $href, $m) ? strtolower($m[1]) : null;
                $avatar = (string) $xpath->evaluate('string(.//img/@src)', $entry);

                // Someone on both lists counts once, as confirmed.
                $key = $username ?? 'name:'.mb_strtolower($name);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $people[] = [
                    'name' => $name,
                    'username' => $username,
                    'avatar_url' => str_starts_with($avatar, 'https://') ? $avatar : null,
                    'status' => $status,
                ];
            }
        }

        return ['title' => $title, 'people' => $people];
    }

    private function listStatus(string $text): ?string
    {
        $label = strtolower(trim(preg_replace('/\s+/u', ' ', $text)));

        return match (true) {
            str_starts_with($label, 'confirmed') => ParticipantStatus::Confirmed->value,
            str_starts_with($label, 'waitlist') => ParticipantStatus::Waitlisted->value,
            default => null,
        };
    }

    private function isHeading(DOMElement $node): bool
    {
        if ($node->nodeName !== 'p') {
            return false;
        }

        $label = strtolower(trim($node->textContent));

        return $this->listStatus($label) !== null || str_starts_with($label, 'invited');
    }

    /**
     * The person entries that follow a heading, up to the next heading: the
     * children of a grid, or the rows themselves.
     *
     * @return list<DOMElement>
     */
    private function entriesAfter(DOMElement $heading, DOMXPath $xpath): array
    {
        $entries = [];

        for ($n = $heading->nextSibling; $n; $n = $n->nextSibling) {
            if (! $n instanceof DOMElement) {
                continue;
            }
            if ($this->isHeading($n)) {
                break;
            }

            if (str_contains(' '.$n->getAttribute('class').' ', ' grid ')) {
                foreach ($n->childNodes as $child) {
                    if ($child instanceof DOMElement) {
                        $entries[] = $child;
                    }
                }

                continue;
            }

            if ($xpath->query(".//a[contains(@href, '/players/@')]", $n)->length > 0 || $n->nodeName === 'a') {
                $entries[] = $n;
            }
        }

        return $entries;
    }

    /**
     * A tile's name is its last <p>; a waitlist row's is the text link
     * beside the avatar (its <p> is the queue position).
     */
    private function entryName(DOMElement $entry, DOMXPath $xpath): string
    {
        $link = trim((string) $xpath->evaluate("string((.//a[contains(@href, '/players/@')][not(.//img)])[last()])", $entry));
        if ($link !== '') {
            return $link;
        }

        $name = trim((string) $xpath->evaluate('string(.//p[last()])', $entry));

        return preg_match('/^\d+$/', $name) ? '' : $name;
    }

    /**
     * The player for a Reclub person: matched by handle, else created as a
     * guest. People without a profile link are always created fresh — a
     * bare display name is not enough to match on safely.
     *
     * @param  array{name: string, username: ?string, avatar_url: ?string, status: string}  $person
     */
    private function resolve(array $person): Player
    {
        if ($person['username'] && $existing = Player::query()->where('reclub_username', $person['username'])->first()) {
            // Keep the photo current; leave everything the host edited alone.
            if ($person['avatar_url'] && $existing->avatar_url !== $person['avatar_url']) {
                $existing->forceFill(['avatar_url' => $person['avatar_url']])->save();
            }

            return $existing;
        }

        $player = Player::query()->create([
            'display_name' => mb_substr($person['name'], 0, 60),
            // Reclub handles may contain '-', which ours do not; derive one.
            'username' => Player::usernameFrom($person['username'] ?? $person['name']),
            'reclub_username' => $person['username'],
            'initials' => $this->initials($person['name']),
            'avatar_url' => $person['avatar_url'],
            'avatar_color_index' => abs(crc32($person['username'] ?? $person['name'])) % 8,
        ]);

        // player_id is guarded on PlayerStat; the relation sets it.
        $player->stats()->firstOrCreate([]);

        return $player->refresh();
    }

    private function initials(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($words) >= 2) {
            return mb_strtoupper(mb_substr($words[0], 0, 1).mb_substr($words[1], 0, 1));
        }

        return mb_strtoupper(str_pad(mb_substr($words[0] ?? 'PL', 0, 2), 2, 'X'));
    }
}
