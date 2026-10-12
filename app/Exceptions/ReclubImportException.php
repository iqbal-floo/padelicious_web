<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A Reclub meet could not be imported: unknown code, Reclub unreachable,
 * or a page we could not read.
 */
class ReclubImportException extends RuntimeException {}
