<?php

namespace App\Console\Commands;

use App\Services\AuditLog;
use App\Services\SkladisteSidra;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Provera bez coveka: pokrece je raspored (routes/console.php) svakog sata.
 *
 * Proverava lanac, potpise i stanje artikala; ako postoji sacuvano sidro,
 * trazi i njega. Kad je sve u redu dopisuje novo sidro, a kad nije upisuje
 * gresku u log aplikacije. Ishod pamti u kesu, da ga stranica /audit-log
 * prikaze i onome ko nikad ne klikne "Proveri lanac".
 */
class NadzorAuditLoga extends Command
{
    public const KLJUC_KESA = 'audit.nadzor';

    protected $signature = 'audit:nadzor';

    protected $description = 'Automatska provera audit loga: pamti ishod, cuva sidro, javlja gresku u log';

    public function handle(AuditLog $log, SkladisteSidra $sidra): int
    {
        $sidro = $sidra->poslednje();
        $rezultat = $log->proveri($sidro['hes'] ?? null);
        $uRedu = $rezultat->sveURedu();

        if ($uRedu && $rezultat->poslednjiHes !== null) {
            $sidra->sacuvaj($rezultat->poslednjiId, $rezultat->poslednjiHes);
        }

        $opis = match (true) {
            ! $rezultat->ispravan => $rezultat->poruka,
            ! $uRedu              => 'Artikli se ne slažu sa logom (' . count($rezultat->neslaganja) . ').',
            default               => null,
        };

        Cache::forever(self::KLJUC_KESA, [
            'vreme'      => now('UTC')->format('Y-m-d H:i:s'),
            'uRedu'      => $uRedu,
            'brojZapisa' => $rezultat->brojZapisa,
            'opis'       => $opis,
        ]);

        if (! $uRedu) {
            Log::error('Audit log: automatska provera NIJE prošla. ' . $opis, [
                'losZapisId' => $rezultat->losZapisId,
                'tragovi'    => $rezultat->tragovi,
                'neslaganja' => array_slice($rezultat->neslaganja, 0, 20),
            ]);
            $this->error('Provera NIJE prošla. ' . $opis);

            return self::FAILURE;
        }

        $this->info("Provera je prošla. Zapisa: {$rezultat->brojZapisa}.");

        return self::SUCCESS;
    }
}
