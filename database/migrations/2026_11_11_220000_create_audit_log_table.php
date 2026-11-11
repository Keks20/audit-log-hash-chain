<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit log sa hes lancem: svaki zapis nosi hes prethodnog.
     *
     * Namerno BEZ stranih kljuceva (ni ka artikli, ni ka users):
     * ON DELETE SET NULL / CASCADE bi menjali ili brisali zapise loga
     * i tako pokidali lanac. Zato se cuvaju kopije (sifra, email).
     * Nema ni updated_at: zapis se samo dodaje.
     */
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();                                   // redosled u lancu
            $table->unsignedBigInteger('artikal_id')->index();
            $table->string('sifra', 6)->index();            // citljivo i posle brisanja artikla
            $table->string('akcija', 10);                   // dodat / izmenjen / obrisan
            $table->unsignedBigInteger('korisnik_id')->nullable();
            $table->string('korisnik_email')->nullable();   // kopija u trenutku izmene
            $table->text('pre')->nullable();                // kanonski JSON, NULL kod dodavanja
            $table->text('posle')->nullable();              // kanonski JSON, NULL kod brisanja
            $table->dateTime('vreme');                      // UTC (DATETIME se ne preracunava po zoni)
            // UNIQUE: dva zapisa ne mogu imati istog prethodnika (lanac ne sme da se racva)
            $table->char('prethodni_hes', 64)->unique();
            $table->char('hes', 64)->unique();              // SHA-256, hex
        });

        // "Glava" lanca: tacno jedan red sa poslednjim hesom. Svaki upis prvo
        // zakljuca ovaj red (brava na jedan red po primarnom kljucu), pa se
        // upisi redaju jedan za drugim. SELECT ... ORDER BY id DESC FOR UPDATE
        // nad samim logom zakljucava i "prazninu" posle poslednjeg reda, a to
        // pri istovremenim upisima pravi deadlock.
        Schema::create('audit_log_glava', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('poslednji_id')->nullable();
            $table->char('poslednji_hes', 64);
        });

        DB::table('audit_log_glava')->insert([
            'id'            => 1,
            'poslednji_id'  => null,
            'poslednji_hes' => str_repeat('0', 64),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log_glava');
        Schema::dropIfExists('audit_log');
    }
};
