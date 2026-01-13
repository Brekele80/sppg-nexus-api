<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $t) {
            $t->dropForeign(['company_id']);
        });

        Schema::table('profiles', function (Blueprint $t) {
            $t->dropForeign(['company_id']);
        });

        DB::statement("
            ALTER TABLE branches
            ADD CONSTRAINT branches_company_id_foreign
            FOREIGN KEY (company_id) REFERENCES companies(id)
            ON DELETE RESTRICT
        ");

        DB::statement("
            ALTER TABLE profiles
            ADD CONSTRAINT profiles_company_id_foreign
            FOREIGN KEY (company_id) REFERENCES companies(id)
            ON DELETE RESTRICT
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
