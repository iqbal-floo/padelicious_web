<?php

namespace App\Services;

use App\Models\Player;
use App\Models\User;

/**
 * Every user needs a player profile: it is what sessions, standings and
 * club membership actually reference.
 */
class PlayerProfileFactory
{
    public function forUser(User $user): Player
    {
        if ($existing = Player::query()->where('user_id', $user->id)->first()) {
            return $existing;
        }

        return Player::query()->create([
            'user_id' => $user->id,
            'display_name' => $this->displayName($user->full_name),
            'initials' => $this->initials($user->full_name),
            // Spread avatar colours deterministically across the 8 slots.
            'avatar_color_index' => $this->colorIndex($user->email),
        ]);
    }

    /**
     * The UI shows a short name, so use the first word.
     */
    private function displayName(string $fullName): string
    {
        $first = trim(explode(' ', trim($fullName))[0] ?? '');

        return $first !== '' ? $first : 'Player';
    }

    /**
     * Two characters: initials of the first two words, else first two
     * letters. The column is char(2).
     */
    private function initials(string $fullName): string
    {
        $words = preg_split('/\s+/', trim($fullName), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($words) >= 2) {
            return strtoupper(mb_substr($words[0], 0, 1).mb_substr($words[1], 0, 1));
        }

        $single = $words[0] ?? 'PL';

        return strtoupper(str_pad(mb_substr($single, 0, 2), 2, 'X'));
    }

    private function colorIndex(string $seed): int
    {
        return abs(crc32($seed)) % 8;
    }
}
