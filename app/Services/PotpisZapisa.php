<?php

namespace App\Services;

use LogicException;
use SodiumException;

/**
 * Digitalni potpis zapisa audit loga (Ed25519, libsodium).
 *
 * Hes lanac sam nema tajnu: ko ima pristup bazi i zna algoritam moze da dopise
 * uredan zapis ili da ceo lanac ponovo izracuna. Potpis trazi tajni kljuc koji
 * stoji van baze (.env), a proverava se javnim kljucem, koji sme da zna svako.
 *
 * Potpisuje se hes zapisa (64 hex znaka). Ed25519 je deterministican: isti
 * kljuc i isti hes uvek daju isti potpis.
 */
class PotpisZapisa
{
    public function imaKljuc(): bool
    {
        return filled(config('magacin.kljuc_potpisa'));
    }

    /**
     * Potpis (128 hex znakova) za dati hes zapisa.
     */
    public function potpisi(string $hes): string
    {
        return bin2hex(sodium_crypto_sign_detached($hes, $this->tajniKljuc()));
    }

    /**
     * Javni kljuc (64 hex znaka) izveden iz tajnog. Njega vredi sacuvati van
     * servera, kao i sidro: tako se otkriva i zamena kljuca.
     */
    public function javniKljuc(): string
    {
        return bin2hex(sodium_crypto_sign_publickey_from_secretkey($this->tajniKljuc()));
    }

    /**
     * Da li potpis odgovara hesu. Javni kljuc je hex; neispravan oblik
     * potpisa ili kljuca znaci samo "ne odgovara".
     */
    public function odgovara(string $hes, string $potpis, string $javniKljuc): bool
    {
        if (strlen($potpis) !== SODIUM_CRYPTO_SIGN_BYTES * 2 || ! ctype_xdigit($potpis)
            || strlen($javniKljuc) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES * 2 || ! ctype_xdigit($javniKljuc)) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached(hex2bin($potpis), $hes, hex2bin($javniKljuc));
        } catch (SodiumException) {
            return false;
        }
    }

    /**
     * Nov par kljuceva; vraca tajni kljuc u obliku za .env (base64).
     */
    public static function noviKljuc(): string
    {
        return base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
    }

    private function tajniKljuc(): string
    {
        $kljuc = config('magacin.kljuc_potpisa');

        if (blank($kljuc)) {
            throw new LogicException(
                'Nema ključa za potpis audit loga (AUDIT_KLJUC_POTPISA u .env). Napravi ga sa: php artisan audit:kljuc'
            );
        }

        $bajtovi = base64_decode($kljuc, true);

        if ($bajtovi === false || strlen($bajtovi) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new LogicException('AUDIT_KLJUC_POTPISA nije ispravan Ed25519 ključ (očekuje se base64 od 64 bajta).');
        }

        return $bajtovi;
    }
}
