<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateMsteamsfsUserLinksTable extends Migration
{
    public $timestamps = false;

    /**
     * Maps a FreeScout user to the Teams/AAD identity captured at their most
     * recent SSO handoff, so conversation-event notifications can be pushed
     * to the correct Teams user via Graph's activity feed API (tid + oid are
     * what that API needs to target a recipient).
     *
     * @return void
     */
    public function up()
    {
        Schema::create('msteamsfs_user_links', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id')->unsigned();
            $table->string('tid', 64);
            $table->string('oid', 64);
            $table->timestamp('updated_at')->nullable();

            $table->unique('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('msteamsfs_user_links');
    }
}
