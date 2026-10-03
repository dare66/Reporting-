<?php

namespace App\Domain\Analytics;

use RuntimeException;

/** The analytics engine (AI service) is unreachable or refused a request; rendered as 503 engine_unavailable. */
final class AnalyticsEngineException extends RuntimeException {}
