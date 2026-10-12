<?php

namespace App\Services;

use App\Enums\ScoringType;
use App\Enums\TennisMode;
use App\Models\MatchGame;
use App\Models\Session;

/**
 * When a match ends, derived from the session's scoring settings.
 *
 *  - set   : first to 6 games — the historic default.
 *  - race  : first to N games (tennis_mode raceN).
 *  - total : exactly N games are played and the side with more games
 *            wins (tennis_mode totalN). With an even N the match can end
 *            level, in which case one golden point decides it.
 *
 * `normal` scoring with a points_target (every rally is one point, first
 * to 24 and so on) is a different scoring system and is not implemented
 * yet: those sessions still count 0/15/30/40 up to six games.
 */
final class MatchFormat
{
    public const SET = 'set';

    public const RACE = 'race';

    public const TOTAL = 'total';

    private function __construct(
        public readonly string $kind,
        public readonly int $games,
    ) {}

    public static function set(): self
    {
        return new self(self::SET, PadelScoring::GAMES_TO_WIN_SET);
    }

    public static function race(int $games): self
    {
        return new self(self::RACE, $games);
    }

    public static function total(int $games): self
    {
        return new self(self::TOTAL, $games);
    }

    public static function fromSession(Session $session): self
    {
        if ($session->scoring_type !== ScoringType::Tennis) {
            return self::set();
        }

        return self::fromTennisMode($session->tennis_mode);
    }

    /**
     * A match's own format if it has one, otherwise its session's. Lets a
     * single sheet play the men to 5 games and the women to 4.
     */
    public static function forMatch(MatchGame $match): self
    {
        if ($match->tennis_mode instanceof TennisMode) {
            return self::fromTennisMode($match->tennis_mode);
        }

        return self::fromSession($match->round->session);
    }

    public static function fromTennisMode(?TennisMode $mode): self
    {
        if (! $mode instanceof TennisMode) {
            return self::set();
        }

        // race3 … total7: the last character is the game count.
        $games = (int) substr($mode->value, -1);

        return str_starts_with($mode->value, 'race')
            ? self::race($games)
            : self::total($games);
    }

    /**
     * A total-games match with an even count can finish level.
     */
    public function canEndLevel(): bool
    {
        return $this->kind === self::TOTAL && $this->games % 2 === 0;
    }

    /**
     * All games have been played and the score is level: the next point
     * decides the match.
     */
    public function deciderDue(int $gamesA, int $gamesB): bool
    {
        return $this->canEndLevel()
            && $gamesA + $gamesB === $this->games
            && $gamesA === $gamesB;
    }

    /**
     * Has the match been won on games alone, without needing a decider?
     */
    public function wonOnGames(int $gamesA, int $gamesB): bool
    {
        return match ($this->kind) {
            self::TOTAL => $gamesA + $gamesB >= $this->games && $gamesA !== $gamesB,
            default => max($gamesA, $gamesB) >= $this->games,
        };
    }

    /**
     * Human label for a printed sheet, e.g. "Total 5 game".
     */
    public function label(): string
    {
        return match ($this->kind) {
            self::RACE => "Race to {$this->games}",
            self::TOTAL => "Total {$this->games} game"
                .($this->canEndLevel() ? " ({$this->half()}-{$this->half()}: 1 golden point)" : ''),
            default => "Set to {$this->games}",
        };
    }

    private function half(): int
    {
        return intdiv($this->games, 2);
    }
}
