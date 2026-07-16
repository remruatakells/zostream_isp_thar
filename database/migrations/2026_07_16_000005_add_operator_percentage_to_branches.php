<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->decimal('operator_percentage', 5, 2)->nullable()->after('name');
        });

        DB::table('branches')
            ->whereRaw('LOWER(name) = ?', ['pawlrang'])
            ->update(['operator_percentage' => 40]);
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('operator_percentage');
        });
    }
};
