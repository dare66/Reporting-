<?php

namespace App\Domain\Reports\Export;

use App\Models\Report;

interface Exporter
{
    /** Writes the export to $path. */
    public function export(Report $report, string $path): void;

    public function extension(): string;

    public function mime(): string;
}
