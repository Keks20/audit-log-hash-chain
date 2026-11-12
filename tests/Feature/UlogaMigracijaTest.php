<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * DatabaseMigrations, jer test sam pokrece migraciju (DDL zatvara transakciju).
 */
class UlogaMigracijaTest extends TestCase
{
    use DatabaseMigrations;

    public function test_migracija_daje_ulogu_sefa_nalogu_koji_je_vec_postojao(): void
    {
        // Stanje baze pre migracije: nalozi postoje, kolone nema
        $migracija = require database_path('migrations/2026_11_12_210000_add_uloga_to_users.php');
        $migracija->down();
        $this->assertFalse(Schema::hasColumn('users', 'uloga'));

        DB::table('users')->insert([
            ['name' => 'Jelena', 'email' => 'sef@magacin.test', 'password' => 'x'],
            ['name' => 'Marko', 'email' => 'magacioner@magacin.test', 'password' => 'x'],
        ]);

        $migracija->up();

        $this->assertSame('sef', DB::table('users')->where('email', 'sef@magacin.test')->value('uloga'));
        $this->assertSame('magacioner', DB::table('users')->where('email', 'magacioner@magacin.test')->value('uloga'));
    }

    public function test_migracija_potpisa_ne_menja_postojece_zapise(): void
    {
        $migracija = require database_path('migrations/2026_11_12_200000_add_potpis_to_audit_log.php');
        $migracija->down();
        $this->assertFalse(Schema::hasColumn('audit_log', 'potpis'));

        // Zapis iz vremena pre potpisa
        DB::table('audit_log')->insert([
            'artikal_id' => 1, 'sifra' => '001001', 'akcija' => 'obrisan', 'pre' => '{}', 'vreme' => '2026-11-11 23:05:11',
            'prethodni_hes' => str_repeat('0', 64), 'hes' => str_repeat('a', 64),
        ]);

        // Trigeri odbijaju UPDATE, a ALTER TABLE ipak prolazi
        $migracija->up();

        $red = DB::table('audit_log')->first();
        $this->assertNull($red->potpis);
        $this->assertSame(str_repeat('a', 64), $red->hes);
    }
}
