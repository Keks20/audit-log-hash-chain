<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Automatska provera audit loga svakog sata (pokrece je "schedule:work" iz docker/start.sh)
Schedule::command('audit:nadzor')->hourly();
