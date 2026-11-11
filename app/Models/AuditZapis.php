<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Jedan zapis audit loga (tabela audit_log).
 * Upisuje ga samo App\Services\AuditLog; posle upisa se ne menja i ne brise.
 */
class AuditZapis extends Model
{
    public const DODAT = 'dodat';
    public const IZMENJEN = 'izmenjen';
    public const OBRISAN = 'obrisan';

    // "Prethodni hes" prvog zapisa u lancu
    public const POCETNI_HES = '0000000000000000000000000000000000000000000000000000000000000000';

    protected $table = 'audit_log';

    public $timestamps = false;

    protected $fillable = [
        'artikal_id',
        'sifra',
        'akcija',
        'korisnik_id',
        'korisnik_email',
        'pre',
        'posle',
        'vreme',
        'prethodni_hes',
        'hes',
    ];

    protected function casts(): array
    {
        return [
            'artikal_id'  => 'integer',
            'korisnik_id' => 'integer',
            'vreme'       => 'datetime',   // u bazi je UTC
        ];
    }

    protected static function booted(): void
    {
        // Zapis se samo dodaje. Direktan SQL ovo zaobilazi, ali to otkriva provera lanca.
        static::updating(fn () => throw new LogicException('Zapis audit loga se ne menja.'));
        static::deleting(fn () => throw new LogicException('Zapis audit loga se ne briše.'));
    }

    public function preNiz(): ?array
    {
        return $this->pre === null ? null : json_decode($this->pre, true, 512, JSON_THROW_ON_ERROR);
    }

    public function posleNiz(): ?array
    {
        return $this->posle === null ? null : json_decode($this->posle, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Polja koja su se promenila: ['naziv' => ['staro', 'novo'], ...].
     * Kod dodavanja je "staro" null, kod brisanja je "novo" null.
     */
    public function izmene(): array
    {
        $pre = $this->preNiz() ?? [];
        $posle = $this->posleNiz() ?? [];

        $izmene = [];
        foreach (array_unique([...array_keys($pre), ...array_keys($posle)]) as $polje) {
            $staro = $pre[$polje] ?? null;
            $novo = $posle[$polje] ?? null;

            if ($staro !== $novo) {
                $izmene[$polje] = [$staro, $novo];
            }
        }

        return $izmene;
    }

    public function vremePrikaz(): string
    {
        return $this->vreme->timezone(config('magacin.zona_prikaza'))->format('d.m.Y. H:i:s');
    }
}
