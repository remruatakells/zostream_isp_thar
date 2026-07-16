<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('operator_id')->nullable()->after('customer_id')->constrained('users')->nullOnDelete();
            $table->decimal('package_amount', 12, 2)->nullable()->after('operator_id');
            $table->decimal('operator_commission', 12, 2)->default(0)->after('package_amount');
        });

        Schema::table('payment_checkouts', function (Blueprint $table) {
            $table->decimal('package_amount', 12, 2)->nullable()->after('razorpay_key_id');
            $table->decimal('operator_commission', 12, 2)->default(0)->after('package_amount');
        });

        DB::table('payments')->update([
            'package_amount' => DB::raw('amount'),
            'operator_commission' => 0,
        ]);
        DB::table('payment_checkouts')->update([
            'package_amount' => DB::raw('amount'),
            'operator_commission' => 0,
        ]);
    }

    public function down(): void
    {
        Schema::table('payment_checkouts', function (Blueprint $table) {
            $table->dropColumn(['package_amount', 'operator_commission']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('operator_id');
            $table->dropColumn(['package_amount', 'operator_commission']);
        });
    }
};
