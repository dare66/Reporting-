<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('aixbi:alerts:evaluate')->everyMinute()->withoutOverlapping();
Schedule::command('aixbi:reports:run-schedules')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('aixbi:insights:generate')->hourly()->withoutOverlapping();
Schedule::command('aixbi:anomalies:scan')->dailyAt('02:15')->withoutOverlapping();
