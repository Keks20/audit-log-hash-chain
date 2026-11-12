<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Digitalni potpis zapisa (Ed25519, hex). Potpisuje se hes zapisa, a hes
     * vec pokriva sva polja i vezu sa prethodnim zapisom.
     *
     * NULL imaju samo zapisi nastali pre uvodjenja potpisa. Oni se ne mogu
     * naknadno potpisati (trigger odbija UPDATE), pa provera dozvoljava
     * nepotpisane zapise samo na pocetku lanca, pre prvog potpisanog.
     *
     * Potpis ne ulazi u hes, pa stari hesevi ostaju isti.
     * ALTER TABLE ne pokrece trigere nad audit_log.
     */
    public function up(): void
    {
        Schema::table('audit_log', function (Blueprint $table) {
            $table->char('potpis', 128)->nullable()->after('hes');
        });
    }

    public function down(): void
    {
        Schema::table('audit_log', function (Blueprint $table) {
            $table->dropColumn('potpis');
        });
    }
};
