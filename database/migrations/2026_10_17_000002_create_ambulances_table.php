<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ambulances', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('plate_no')->unique();
            $table->foreignId('home_barangay_id')->nullable()->constrained('barangays')->nullOnDelete();
            // available | dispatched | en_route | on_scene | transporting | at_hospital | out_of_service | offline
            $table->string('status')->default('offline')->index();
            $table->decimal('last_lat', 10, 7)->nullable();
            $table->decimal('last_lng', 10, 7)->nullable();
            $table->float('last_accuracy')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('crew')->after('password')->index(); // admin | dispatcher | crew
            $table->string('phone')->nullable()->after('role');
            $table->foreignId('ambulance_id')->nullable()->after('phone')->constrained('ambulances')->nullOnDelete();
            $table->boolean('on_duty')->default(false)->after('ambulance_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ambulance_id');
            $table->dropColumn(['role', 'phone', 'on_duty']);
        });
        Schema::dropIfExists('ambulances');
    }
};
