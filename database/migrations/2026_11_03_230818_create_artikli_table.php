<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cista tabela artikala za Laravel aplikaciju.
     * Sirovi podaci iz starog programa ostaju u tabeli "magacin".
     */
    public function up(): void
    {
        Schema::create('artikli', function (Blueprint $table) {
            $table->id();
            $table->string('sifra', 6)->unique();          // SIFRA iz .dbf-a
            $table->string('naziv', 30);                   // NAZIV
            $table->string('jedinica_mere', 3);            // JM: kg, l, kom
            $table->decimal('kolicina', 10, 3)->nullable(); // NULL = nije mogla da se procita
            $table->decimal('cena', 12, 2)->nullable();
            $table->date('datum_nabavke')->nullable();     // DAT_NAB
            $table->boolean('aktivan')->nullable();        // NULL = u starom programu "?"
            $table->text('napomena')->nullable();
            $table->timestamps();                          // created_at, updated_at
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artikli');
    }
};