<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('radcheck', function (Blueprint $table) {
            $table->id();
            $table->string('username', 64)->index();
            $table->string('attribute', 64);
            $table->string('op', 2)->default(':=');
            $table->string('value', 253);
        });

        Schema::create('radreply', function (Blueprint $table) {
            $table->id();
            $table->string('username', 64)->index();
            $table->string('attribute', 64);
            $table->string('op', 2)->default(':=');
            $table->string('value', 253);
        });

        Schema::create('nas', function (Blueprint $table) {
            $table->id();
            $table->string('nasname', 128)->unique();
            $table->string('shortname', 64);
            $table->string('type', 30)->default('other');
            $table->unsignedInteger('ports')->nullable();
            $table->string('secret', 60);
            $table->string('server', 64)->nullable();
            $table->string('community', 50)->nullable();
            $table->string('description', 200)->nullable();
        });

        Schema::create('radacct', function (Blueprint $table) {
            $table->bigIncrements('radacctid');
            $table->string('acctsessionid', 64)->index();
            $table->string('acctuniqueid', 32)->unique();
            $table->string('username', 64)->index();
            $table->string('realm', 64)->nullable();
            $table->string('nasipaddress', 15)->index();
            $table->string('nasportid', 32)->nullable();
            $table->string('nasporttype', 32)->nullable();
            $table->dateTime('acctstarttime')->nullable()->index();
            $table->dateTime('acctupdatetime')->nullable();
            $table->dateTime('acctstoptime')->nullable()->index();
            $table->unsignedInteger('acctinterval')->nullable();
            $table->unsignedBigInteger('acctsessiontime')->nullable();
            $table->string('acctauthentic', 32)->nullable();
            $table->string('connectinfo_start', 50)->nullable();
            $table->string('connectinfo_stop', 50)->nullable();
            $table->unsignedBigInteger('acctinputoctets')->nullable();
            $table->unsignedBigInteger('acctoutputoctets')->nullable();
            $table->string('calledstationid', 50)->nullable();
            $table->string('callingstationid', 50)->nullable();
            $table->string('acctterminatecause', 32)->nullable();
            $table->string('servicetype', 32)->nullable();
            $table->string('framedprotocol', 32)->nullable();
            $table->string('framedipaddress', 15)->nullable()->index();
            $table->string('framedipv6address', 45)->nullable();
            $table->string('framedipv6prefix', 45)->nullable();
            $table->string('framedinterfaceid', 44)->nullable();
            $table->string('delegatedipv6prefix', 45)->nullable();
            $table->string('class', 64)->nullable();
        });

        Schema::create('radpostauth', function (Blueprint $table) {
            $table->id();
            $table->string('username', 64);
            $table->string('pass', 64)->nullable();
            $table->string('reply', 32)->nullable();
            $table->timestamp('authdate')->useCurrent();
            $table->index(['username', 'authdate']);
        });

        Schema::table('routers', function (Blueprint $table) {
            $table->text('radius_secret')->nullable()->after('password');
            $table->boolean('radius_enabled')->default(false)->after('radius_secret');
        });
    }

    public function down(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->dropColumn(['radius_secret', 'radius_enabled']);
        });

        Schema::dropIfExists('radpostauth');
        Schema::dropIfExists('radacct');
        Schema::dropIfExists('nas');
        Schema::dropIfExists('radreply');
        Schema::dropIfExists('radcheck');
    }
};
