<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Uloga korisnika: "magacioner" (dodaje i menja artikle) ili "sef"
     * (uz to brise artikle i vraca stanje iz loga).
     *
     * Nalog sef@magacin.test iz KorisniciSeeder-a je mozda vec napravljen pre
     * ove kolone, a seeder postojece naloge ne dira. Zato mu se uloga daje ovde.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('uloga', 20)->default('magacioner')->after('email');
        });

        DB::table('users')->where('email', 'sef@magacin.test')->update(['uloga' => 'sef']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('uloga');
        });
    }
};
