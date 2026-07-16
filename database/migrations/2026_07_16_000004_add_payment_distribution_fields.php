<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('ott_deduction', 12, 2)->default(0)->after('package_amount');
            $table->decimal('distributable_amount', 12, 2)->nullable()->after('ott_deduction');
            $table->decimal('operator_percentage', 5, 2)->default(0)->after('distributable_amount');
        });

        Schema::table('payment_checkouts', function (Blueprint $table) {
            $table->decimal('ott_deduction', 12, 2)->default(0)->after('package_amount');
            $table->decimal('distributable_amount', 12, 2)->nullable()->after('ott_deduction');
            $table->decimal('operator_percentage', 5, 2)->default(0)->after('distributable_amount');
        });
    }

    public function down(): void
    {
        Schema::table('payment_checkouts', function (Blueprint $table) {
            $table->dropColumn(['ott_deduction', 'distributable_amount', 'operator_percentage']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['ott_deduction', 'distributable_amount', 'operator_percentage']);
        });
    }
};
