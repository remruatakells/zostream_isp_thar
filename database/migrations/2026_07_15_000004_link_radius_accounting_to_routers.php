<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('radacct', function (Blueprint $table) {
            $table->foreignId('router_id')->nullable()->after('radacctid')->constrained()->nullOnDelete();
        });

        DB::table('routers')->select(['id', 'host'])->orderBy('id')->each(function ($router): void {
            DB::table('radacct')
                ->whereNull('router_id')
                ->where('nasipaddress', $router->host)
                ->update(['router_id' => $router->id]);
        });

        DB::table('customers')
            ->select('username')
            ->groupBy('username')
            ->havingRaw('COUNT(*) = 1')
            ->pluck('username')
            ->chunk(200)
            ->each(function ($usernames): void {
                DB::table('customers')
                    ->whereIn('username', $usernames)
                    ->select(['id', 'router_id', 'username'])
                    ->orderBy('id')
                    ->each(function ($customer): void {
                        DB::table('radacct')
                            ->whereNull('router_id')
                            ->where('class', 'jaze-session-import')
                            ->where('username', $customer->username)
                            ->update(['router_id' => $customer->router_id]);
                    });
            });
    }

    public function down(): void
    {
        Schema::table('radacct', function (Blueprint $table) {
            $table->dropConstrainedForeignId('router_id');
        });
    }
};
