<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Bez RefreshDatabase: ovaj test ne sme nista da migrira.
 */
class OsiguracTestBazeTest extends TestCase
{
    public function test_testovi_rade_nad_test_bazom_na_mariadb(): void
    {
        $this->assertSame('audit_log_test', DB::connection()->getDatabaseName());
        $this->assertSame('mysql', DB::connection()->getDriverName());
    }

    public function test_osigurac_odbija_glavnu_bazu(): void
    {
        $staro = [$_ENV['DB_DATABASE'] ?? null, $_SERVER['DB_DATABASE'] ?? null, getenv('DB_DATABASE')];

        // Kao da phpunit.xml nije preusmerio bazu
        $_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'audit_log';
        putenv('DB_DATABASE=audit_log');

        try {
            $this->createApplication();
            $this->fail('Osigurac je morao da odbije bazu audit_log.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('"audit_log"', $e->getMessage());
        } finally {
            [$env, $server, $put] = $staro;
            if ($env === null) { unset($_ENV['DB_DATABASE']); } else { $_ENV['DB_DATABASE'] = $env; }
            if ($server === null) { unset($_SERVER['DB_DATABASE']); } else { $_SERVER['DB_DATABASE'] = $server; }
            putenv($put === false ? 'DB_DATABASE' : "DB_DATABASE={$put}");
        }
    }
}
