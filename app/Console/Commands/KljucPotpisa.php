<?php

namespace App\Console\Commands;

use App\Services\PotpisZapisa;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Pravi kljuc za potpis audit loga i upisuje ga u .env (AUDIT_KLJUC_POTPISA).
 *
 * Pokretanje:  php artisan audit:kljuc           (ne dira postojeci kljuc)
 *              php artisan audit:kljuc --javni   (samo ispisuje javni kljuc)
 *              php artisan audit:kljuc --zameni  (nov kljuc preko starog)
 *
 * Tajni kljuc se nikad ne ispisuje. Javni kljuc sme da vidi svako; vredi ga
 * sacuvati van servera i prosledjivati proveri (audit:provera --javni-kljuc=).
 */
class KljucPotpisa extends Command
{
    private const PROMENLJIVA = 'AUDIT_KLJUC_POTPISA';

    protected $signature = 'audit:kljuc
        {--javni : Samo ispisuje javni kljuc}
        {--zameni : Pravi nov kljuc i kad stari postoji (stari potpisi vise ne prolaze proveru)}';

    protected $description = 'Pravi kljuc za potpis audit loga (Ed25519) i upisuje ga u .env';

    public function handle(PotpisZapisa $potpis): int
    {
        if ($this->option('javni')) {
            if (! $potpis->imaKljuc()) {
                $this->error('Nema ključa (' . self::PROMENLJIVA . ' u .env). Napravi ga sa: php artisan audit:kljuc');

                return self::FAILURE;
            }

            $this->line($potpis->javniKljuc());

            return self::SUCCESS;
        }

        if ($potpis->imaKljuc() && ! $this->option('zameni')) {
            $this->info('Ključ već postoji, nije menjan.');
            $this->line('Javni ključ: ' . $potpis->javniKljuc());

            return self::SUCCESS;
        }

        $putanja = $this->laravel->environmentFilePath();

        if (! File::exists($putanja)) {
            $this->error("Nema fajla {$putanja}.");

            return self::FAILURE;
        }

        $kljuc = PotpisZapisa::noviKljuc();
        $red = self::PROMENLJIVA . '=' . $kljuc;
        $env = File::get($putanja);

        if (preg_match('/^' . self::PROMENLJIVA . '=.*$/m', $env)) {
            // Funkcija umesto teksta zamene: base64 moze da sadrzi znakove koje preg_replace tumaci
            $env = preg_replace_callback('/^' . self::PROMENLJIVA . '=.*$/m', fn () => $red, $env, 1);
        } else {
            $env = rtrim($env, "\r\n") . "\n\n" . $red . "\n";
        }

        File::put($putanja, $env);

        // Da ostatak ovog procesa vidi nov kljuc
        config(['magacin.kljuc_potpisa' => $kljuc]);

        $this->info('Ključ za potpis je upisan u .env.');
        $this->line('Javni ključ (sačuvaj van servera): ' . $potpis->javniKljuc());

        return self::SUCCESS;
    }
}
