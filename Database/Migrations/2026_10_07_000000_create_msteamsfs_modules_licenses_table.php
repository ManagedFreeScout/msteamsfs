<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MSTeamsFS keeps its licence in the modules_licenses table that StackPros
// modules share. Until now only StickyMenu created it, so on a FreeScout
// without StickyMenu every Teams sign-in failed with "license not active"
// (card #267). Same schema as StickyMenu's migration, and both create the
// table only when it is missing, so either module may come first. The class
// name must differ from StickyMenu's CreateModulesLicensesTable.
class CreateMsteamsfsModulesLicensesTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('modules_licenses')) {
            Schema::create('modules_licenses', function (Blueprint $table) {
                $table->increments('id');
                $table->string('module_alias')->index();
                $table->string('license_key')->nullable();
                $table->boolean('is_valid')->default(false);
                $table->string('status')->default('inactive');
                $table->string('license_type')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->string('domain')->nullable();
                $table->text('response_data')->nullable();
                $table->timestamps();
            });
            return;
        }

        if (!Schema::hasColumn('modules_licenses', 'module_alias')) {
            Schema::table('modules_licenses', function (Blueprint $table) {
                $table->string('module_alias')->after('id')->index();
            });
        }
    }

    public function down()
    {
        // Shared with other StackPros modules: never dropped here.
    }
}
