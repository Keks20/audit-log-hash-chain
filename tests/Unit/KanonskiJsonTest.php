<?php

namespace Tests\Unit;

use App\Support\KanonskiJson;
use InvalidArgumentException;
use JsonException;
use PHPUnit\Framework\TestCase;
use stdClass;

class KanonskiJsonTest extends TestCase
{
    public function test_redosled_kljuceva_pri_unosu_nije_bitan(): void
    {
        $this->assertSame('{"a":2,"b":1}', KanonskiJson::encode(['b' => 1, 'a' => 2]));
        $this->assertSame(KanonskiJson::encode(['b' => 1, 'a' => 2]), KanonskiJson::encode(['a' => 2, 'b' => 1]));
    }

    public function test_ugnjezdeni_objekti_se_sortiraju_a_liste_zadrzavaju_redosled(): void
    {
        $this->assertSame(
            '{"l":[3,1,2],"z":{"x":2,"y":1}}',
            KanonskiJson::encode(['z' => ['y' => 1, 'x' => 2], 'l' => [3, 1, 2]])
        );
    }

    public function test_tekst_se_ne_normalizuje(): void
    {
        // Razmaci, \r\n i nasa slova ostaju tacno kakvi jesu; kosa crta se ne escape-uje
        $this->assertSame(
            '{"n":"Šećer  \r\nkraj ","p":"a/b"}',
            KanonskiJson::encode(['n' => "Šećer  \r\nkraj ", 'p' => 'a/b'])
        );
    }

    public function test_null_bool_i_int_zadrzavaju_tip(): void
    {
        $this->assertSame(
            '{"a":null,"b":true,"c":false,"d":0,"e":"0"}',
            KanonskiJson::encode(['a' => null, 'b' => true, 'c' => false, 'd' => 0, 'e' => '0'])
        );
    }

    public function test_float_se_odbija(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('$.cena');

        KanonskiJson::encode(['cena' => 120.5]);
    }

    public function test_objekat_se_odbija(): void
    {
        $this->expectException(InvalidArgumentException::class);

        KanonskiJson::encode(['x' => new stdClass()]);
    }

    public function test_neispravan_utf8_se_odbija(): void
    {
        $this->expectException(JsonException::class);

        KanonskiJson::encode(['x' => "\xC3\x28"]);
    }
}
