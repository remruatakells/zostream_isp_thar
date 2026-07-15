<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('radusergroup', function (Blueprint $table) {
            $table->id();
            $table->string('username', 64)->index();
            $table->string('groupname', 64);
            $table->integer('priority')->default(1);
            $table->index(['username', 'priority']);
        });

        Schema::create('radgroupcheck', function (Blueprint $table) {
            $table->id();
            $table->string('groupname', 64)->index();
            $table->string('attribute', 64);
            $table->string('op', 2)->default(':=');
            $table->string('value', 253);
        });

        Schema::create('radgroupreply', function (Blueprint $table) {
            $table->id();
            $table->string('groupname', 64)->index();
            $table->string('attribute', 64);
            $table->string('op', 2)->default(':=');
            $table->string('value', 253);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('radgroupreply');
        Schema::dropIfExists('radgroupcheck');
        Schema::dropIfExists('radusergroup');
    }
};
