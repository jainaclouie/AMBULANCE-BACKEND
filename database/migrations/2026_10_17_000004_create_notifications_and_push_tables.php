<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Laravel database notifications (the in-app notification bell).
        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->morphs('notifiable');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }

        // Web Push subscriptions (phone notifications), schema of laravel-notification-channels/webpush.
        $name = config('webpush.table_name', 'push_subscriptions');
        if (! Schema::hasTable($name)) {
            Schema::create($name, function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->morphs('subscribable');
                $table->string('endpoint', 500)->unique();
                $table->string('public_key')->nullable();
                $table->string('auth_token')->nullable();
                $table->string('content_encoding')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(config('webpush.table_name', 'push_subscriptions'));
        Schema::dropIfExists('notifications');
    }
};
