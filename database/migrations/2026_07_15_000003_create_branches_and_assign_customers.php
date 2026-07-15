<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('phone')->constrained()->restrictOnDelete();
        });

        DB::table('customers')
            ->whereNotNull('branch')
            ->where('branch', '!=', '')
            ->select('branch')
            ->distinct()
            ->orderBy('branch')
            ->pluck('branch')
            ->groupBy(fn (string $name) => mb_strtolower(trim($name)))
            ->each(function ($sourceNames): void {
                $name = trim((string) $sourceNames->first());
                $branchId = DB::table('branches')->insertGetId([
                    'name' => $name,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('customers')->whereIn('branch', $sourceNames)->update(['branch_id' => $branchId]);
            });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['branch']);
            $table->dropColumn('branch');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('branch', 100)->nullable()->index()->after('phone');
        });

        DB::table('customers')
            ->join('branches', 'branches.id', '=', 'customers.branch_id')
            ->update(['customers.branch' => DB::raw('branches.name')]);

        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });

        Schema::dropIfExists('branches');
    }
};
