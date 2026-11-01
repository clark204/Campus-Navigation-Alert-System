<?php

namespace App\Services\Cor;

use RuntimeException;

/**
 * The PDF could not be read at all, or has no text layer (e.g. a scan or a photo).
 */
class CorParseException extends RuntimeException {}
