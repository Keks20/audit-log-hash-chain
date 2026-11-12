<?php

namespace App\Console\Commands;

use App\Services\AuditLog;
use Illuminate\Console\Command;

/**
 * Upisuje pocetni snimak ("pocetno") za artikle koji nemaju nijedan zapis u logu.
 *
 * Pokretanje: php artisan audit:snimak
 *
 * Posle kloniranja ovo radi ArtikliSeeder. Komanda treba bazi koja je imala
 * artikle pre nego sto je snimak uveden. Snimak belezi stanje kakvo jeste:
 * ne moze da zna da li je artikal pre toga menjan mimo aplikacije.
 */
class PocetniSnimak extends Command
{
    protected $signature = 'audit:snimak';

    protected $description = 'Upisuje u audit log pocetno stanje artikala koji jos nemaju nijedan zapis';

    public function handle(AuditLog $log): int
    {
        $upisano = $log->pocetniSnimak();

        $this->info($upisano === 0
            ? 'Svi artikli već imaju zapis u logu.'
            : "Početni snimak upisan za artikala: {$upisano}.");

        return self::SUCCESS;
    }
}
