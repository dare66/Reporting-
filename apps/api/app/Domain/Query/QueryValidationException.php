<?php

namespace App\Domain\Query;

use RuntimeException;

/** A semantic query that cannot be compiled. Message is safe to show to users. */
class QueryValidationException extends RuntimeException {}
