<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('package_id')->nullable()->after('customer_id')
                ->constrained()->nullOnDelete();
        });

        Schema::table('payment_checkouts', function (Blueprint $table) {
            $table->foreignId('package_id')->nullable()->after('customer_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_checkouts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('package_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('package_id');
        });
    }
};
