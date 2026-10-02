<?php

namespace App\Domain\Query;

use RuntimeException;

/** The user is not permitted to see what the query asks for. */
class QueryDeniedException extends RuntimeException {}
