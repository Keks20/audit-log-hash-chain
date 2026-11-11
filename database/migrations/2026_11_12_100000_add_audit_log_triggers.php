<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Baza sama odbija izmenu i brisanje zapisa loga, i dozvoljava da se glava
     * pomeri samo na sledeci zapis lanca.
     *
     * Ovo sprecava slucajnu ili naivnu izmenu (i iz phpMyAdmin-a). Ko ima prava
     * nad semom moze da obrise trigger ili uradi TRUNCATE (TRUNCATE ne pokrece
     * DELETE trigger); to i dalje otkriva provera lanca, odnosno sidro.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_log_bez_izmene BEFORE UPDATE ON audit_log FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log: zapis se ne menja'
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_log_bez_brisanja BEFORE DELETE ON audit_log FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log: zapis se ne brise'
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_log_glava_bez_brisanja BEFORE DELETE ON audit_log_glava FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log_glava: glava se ne brise'
            SQL);

        // Glava sme da ide samo napred: na zapis ciji je prethodnik dosadasnja glava
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_log_glava_samo_napred BEFORE UPDATE ON audit_log_glava FOR EACH ROW
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM audit_log
                    WHERE id = NEW.poslednji_id
                      AND hes = NEW.poslednji_hes
                      AND prethodni_hes = OLD.poslednji_hes
                ) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'audit_log_glava: glava se pomera samo na sledeci zapis lanca';
                END IF;
            END
            SQL);
    }

    public function down(): void
    {
        foreach (['audit_log_bez_izmene', 'audit_log_bez_brisanja', 'audit_log_glava_bez_brisanja', 'audit_log_glava_samo_napred'] as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }
};
