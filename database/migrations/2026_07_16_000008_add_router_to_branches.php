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
            $table->foreignId('router_id')->nullable()->after('name')->constrained()->nullOnDelete();
        });

        DB::table('branches')->orderBy('id')->each(function ($branch): void {
            $routerIds = DB::table('customers')
                ->where('branch_id', $branch->id)
                ->distinct()
                ->pluck('router_id');

            if ($routerIds->count() === 1) {
                DB::table('branches')->where('id', $branch->id)->update([
                    'router_id' => $routerIds->first(),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('router_id');
        });
    }
};
