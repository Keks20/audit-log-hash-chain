<?php

namespace App\Console\Commands;

use App\Services\AuditLog;
use App\Services\RezultatProvere;
use App\Services\SkladisteSidra;
use Illuminate\Console\Command;

/**
 * Proverava ceo hes lanac audit loga, potpise i stanje artikala prema logu.
 *
 * Pokretanje:  php artisan audit:provera
 *              php artisan audit:provera --sidro=<hes>
 *              php artisan audit:provera --sidro=poslednje   (poslednje iz fajla sidara)
 *              php artisan audit:provera --sacuvaj           (ispravan lanac: dopisi sidro u fajl)
 *              php artisan audit:provera --javni-kljuc=<hex> (kljuc sacuvan van servera)
 *              php artisan audit:provera --json              (za skripte i nadzor)
 *
 * "Sidro" je poslednji hes. Sacuvano van baze, ono otkriva i ono sto sam
 * lanac ne vidi: obrisane poslednje zapise i ceo lanac ponovo izracunat.
 *
 * Izlazni kod: 0 ako je sve u redu, 1 ako lanac nije ispravan ili se
 * artikli ne slazu sa logom.
 */
class ProveraAuditLoga extends Command
{
    protected $signature = 'audit:provera
        {--sidro= : Hes sacuvan ranije van baze (mora da postoji u lancu), ili "poslednje" za poslednje iz fajla sidara}
        {--sacuvaj : Ako je sve u redu, dopisuje poslednji hes u fajl sidara}
        {--javni-kljuc= : Javni kljuc (hex) kojim se proveravaju potpisi, umesto onog izvedenog iz .env}
        {--json : Ispis u JSON-u}';

    protected $description = 'Proverava hes lanac audit loga, potpise i stanje artikala';

    public function handle(AuditLog $log, SkladisteSidra $sidra): int
    {
        $sidro = $this->option('sidro');

        if ($sidro !== null) {
            $sidro = strtolower(trim($sidro));

            if ($sidro === 'poslednje') {
                $sacuvano = $sidra->poslednje();

                if ($sacuvano === null) {
                    $this->error('Nema sačuvanog sidra (' . $sidra->putanja() . '). Sačuvaj ga sa: php artisan audit:provera --sacuvaj');

                    return self::FAILURE;
                }

                $sidro = $sacuvano['hes'];
            }
        }

        $javniKljuc = $this->option('javni-kljuc');
        $rezultat = $log->proveri($sidro, $javniKljuc !== null ? strtolower(trim($javniKljuc)) : null);

        // Sidro se cuva samo kad je sve u redu: los lanac ne sme da postane novo sidro
        $sacuvano = $this->option('sacuvaj') && $rezultat->sveURedu() && $rezultat->poslednjiHes !== null
            ? $sidra->sacuvaj($rezultat->poslednjiId, $rezultat->poslednjiHes)
            : null;

        if ($this->option('json')) {
            $this->line(json_encode(
                $rezultat->toArray() + ['sveURedu' => $rezultat->sveURedu(), 'sidroSacuvano' => $sacuvano],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ));

            return $rezultat->sveURedu() ? self::SUCCESS : self::FAILURE;
        }

        if (! $rezultat->ispravan) {
            return $this->neispravanLanac($rezultat);
        }

        if ($rezultat->brojZapisa === 0) {
            $this->info('Audit log je prazan.');
        } else {
            $this->info("Lanac je ispravan. Zapisa: {$rezultat->brojZapisa}." . ($sidro !== null ? ' Sidro je pronađeno.' : ''));

            if ($rezultat->nepotpisanih > 0) {
                $this->line("Nepotpisanih zapisa sa početka lanca (pre uvođenja potpisa): {$rezultat->nepotpisanih}.");
            }

            $this->line("Sidro (sačuvaj van baze): zapis #{$rezultat->poslednjiId}, heš {$rezultat->poslednjiHes}");
        }

        if ($rezultat->neslaganja !== []) {
            $this->error('Artikli se NE slažu sa logom (' . count($rezultat->neslaganja) . '):');
            foreach ($rezultat->neslaganja as $n) {
                $this->line("  - {$n}");
            }

            if ($this->option('sacuvaj')) {
                $this->line('Sidro nije sačuvano.');
            }

            return self::FAILURE;
        }

        if ($sacuvano === true) {
            $this->line('Sidro je dopisano u ' . $sidra->putanja());
        } elseif ($sacuvano === false) {
            $this->line('Isto sidro je već poslednje u fajlu, nije dopisano ponovo.');
        }

        return self::SUCCESS;
    }

    private function neispravanLanac(RezultatProvere $rezultat): int
    {
        $gde = $rezultat->losZapisId !== null ? " Zapis #{$rezultat->losZapisId}." : '';
        $this->error('Lanac NIJE ispravan.' . $gde . ' ' . $rezultat->poruka);

        if ($rezultat->tragovi !== []) {
            $this->line('Tragovi:');
            foreach ($rezultat->tragovi as $trag) {
                $this->line("  - {$trag}");
            }
        }

        $this->line("Pregledano zapisa: {$rezultat->brojZapisa}.");

        if ($this->option('sacuvaj')) {
            $this->line('Sidro nije sačuvano.');
        }

        return self::FAILURE;
    }
}
