<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->string('caller_name');
            $table->string('caller_phone');
            $table->foreignId('barangay_id')->constrained('barangays');
            $table->string('address')->nullable();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->string('emergency_type'); // cardiac | accident | childbirth | breathing | other
            $table->string('priority')->default('medium'); // low | medium | high | critical
            $table->text('notes')->nullable();
            // awaiting_unit | offered | en_route | on_scene | transporting | at_hospital | completed | cancelled
            $table->string('status')->default('awaiting_unit')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_ambulance_id')->nullable()->constrained('ambulances')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('dispatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ambulance_id')->constrained()->cascadeOnDelete();
            // offered | accepted | declined | expired | cancelled | completed
            $table->string('status')->default('offered')->index();
            // en_route | on_scene | transporting | at_hospital | completed (after acceptance)
            $table->string('stage')->nullable();
            $table->unsignedSmallInteger('rank')->nullable();
            $table->unsignedInteger('eta_seconds')->nullable();
            $table->unsignedInteger('distance_m')->nullable();
            $table->string('eta_source')->nullable(); // osrm | estimate
            $table->timestamp('offered_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('hospital_id')->nullable()->constrained('hospitals')->nullOnDelete();
            $table->json('route_geojson')->nullable();
            $table->unsignedInteger('route_eta_seconds')->nullable();
            $table->unsignedInteger('route_distance_m')->nullable();
            $table->timestamp('route_updated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('location_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ambulance_id')->constrained()->cascadeOnDelete();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->float('accuracy')->nullable();
            $table->float('speed')->nullable();
            $table->timestamp('recorded_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_logs');
        Schema::dropIfExists('dispatches');
        Schema::dropIfExists('incidents');
    }
};
