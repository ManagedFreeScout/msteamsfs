<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Teams channel posting (msteamsfs card #339): the built-in rule per mailbox, and which channels a
// conversation was posted to (so status and assignee changes can update those cards).
class CreateMsteamsfsChannelTables extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('msteamsfs_channel_rules')) {
            Schema::create('msteamsfs_channel_rules', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('mailbox_id')->unique();
                $table->boolean('enabled')->default(false);
                $table->string('channel_id', 191)->nullable();
                $table->text('events')->nullable();
                $table->text('card')->nullable();
                $table->string('last_result', 40)->nullable();
                $table->timestamp('last_result_at')->nullable();
                $table->timestamps();
            });
        }
        if (!Schema::hasTable('msteamsfs_channel_posts')) {
            Schema::create('msteamsfs_channel_posts', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('conversation_id');
                $table->string('channel_id', 191);
                $table->text('card')->nullable();
                $table->timestamps();
                $table->unique(['conversation_id', 'channel_id']);
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('msteamsfs_channel_posts');
        Schema::dropIfExists('msteamsfs_channel_rules');
    }
}
