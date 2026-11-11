<?php

namespace App\Services;

/**
 * Ishod provere lanca (AuditLog::proveri).
 */
final readonly class RezultatProvere
{
    public function __construct(
        public bool $ispravan,
        public int $brojZapisa,
        public ?string $poruka = null,
        public ?int $losZapisId = null,     // prvi zapis na kome je lanac pukao
        public ?int $poslednjiId = null,
        public ?string $poslednjiHes = null, // "sidro" koje vredi sacuvati van baze
        public array $tragovi = [],          // sta se ne slaze (DijagnostikaLanca)
    ) {}

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
