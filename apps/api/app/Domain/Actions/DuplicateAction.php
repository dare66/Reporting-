<?php

namespace App\Domain\Actions;

use InvalidArgumentException;

/** The same issue already has an open incident or a proposal waiting for approval. */
class DuplicateAction extends InvalidArgumentException {}
