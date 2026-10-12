<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A hand-entered round that cannot be played as given: unknown court,
 * a player listed twice, a withdrawn player, a broken partnership.
 * Surfaces as 422, unlike the 409 for "finish the current round first".
 */
class InvalidRoundException extends RuntimeException {}
