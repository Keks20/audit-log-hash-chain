<?php

namespace App\Console\Commands;

use App\Services\AuditLog;
use Illuminate\Console\Command;

/**
 * Proverava ceo hes lanac audit loga.
 *
 * Pokretanje:  php artisan audit:provera
 *              php artisan audit:provera --sidro=<hes>
 *
 * Na kraju ispisuje "sidro" (poslednji hes). Sacuvano van baze, ono otkriva
 * i ono sto sam lanac ne vidi: obrisane poslednje zapise i ceo lanac
 * ponovo izracunat posle izmene.
 */
class ProveraAuditLoga extends Command
{
    protected $signature = 'audit:provera {--sidro= : Hes sacuvan ranije van baze; mora da postoji u lancu}';

    protected $description = 'Proverava hes lanac audit loga';

    public function handle(AuditLog $log): int
    {
        $sidro = $this->option('sidro');
        $rezultat = $log->proveri($sidro !== null ? strtolower(trim($sidro)) : null);

        if (! $rezultat->ispravan) {
            $gde = $rezultat->losZapisId !== null ? " Zapis #{$rezultat->losZapisId}." : '';
            $this->error('Lanac NIJE ispravan.' . $gde . ' ' . $rezultat->poruka);

            if ($rezultat->tragovi !== []) {
                $this->line('Tragovi:');
                foreach ($rezultat->tragovi as $trag) {
                    $this->line("  - {$trag}");
                }
            }

            $this->line("Pregledano zapisa: {$rezultat->brojZapisa}.");

            return self::FAILURE;
        }

        if ($rezultat->brojZapisa === 0) {
            $this->info('Audit log je prazan.');

            return self::SUCCESS;
        }

        $this->info("Lanac je ispravan. Zapisa: {$rezultat->brojZapisa}." . ($sidro !== null ? ' Sidro je pronađeno.' : ''));
        $this->line("Sidro (sačuvaj van baze): zapis #{$rezultat->poslednjiId}, heš {$rezultat->poslednjiHes}");

        return self::SUCCESS;
    }
}
