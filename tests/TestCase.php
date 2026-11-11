<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Osigurac: RefreshDatabase brise sve tabele, pa testovi smeju da rade
     * samo nad bazom cije ime zavrsava na "_test". Proverava se ovde, pre
     * nego sto se pokrenu trait-ovi (pa i RefreshDatabase).
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $konekcija = $app['config']->get('database.default');
        $baza = (string) $app['config']->get("database.connections.{$konekcija}.database");

        if (! str_ends_with($baza, '_test')) {
            throw new RuntimeException(
                "Testovi odbijaju da rade nad bazom \"{$baza}\" (konekcija {$konekcija}). "
                . 'Ocekuje se baza koja se zavrsava na _test, npr. audit_log_test iz phpunit.xml.'
            );
        }

        return $app;
    }
}
