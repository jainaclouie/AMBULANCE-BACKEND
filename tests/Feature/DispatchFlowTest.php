<?php

namespace Tests\Feature;

use App\Models\Ambulance;
use App\Models\Barangay;
use App\Models\Dispatch;
use App\Models\Hospital;
use App\Models\Incident;
use App\Models\User;
use App\Services\DispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers the rules the instructor asked about: how availability is verified,
 * ranking by travel time, the 60-second window and automatic escalation.
 */
class DispatchFlowTest extends TestCase
{
    use RefreshDatabase;

    private Barangay $barangay;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'sync']);
        // Routing server unreachable -> the service falls back to straight-line estimates.
        Http::fake(fn () => Http::response([], 500));
        $this->barangay = Barangay::create(['name' => 'Poblacion', 'latitude' => 10.2555, 'longitude' => 123.948]);
    }

    private function unit(string $name, float $lat, float $lng, array $override = []): Ambulance
    {
        $ambulance = Ambulance::create(array_merge([
            'name' => $name, 'plate_no' => $name, 'home_barangay_id' => $this->barangay->id,
            'status' => Ambulance::AVAILABLE, 'last_lat' => $lat, 'last_lng' => $lng, 'last_seen_at' => now(),
        ], $override));
        User::create([
            'name' => $name.' crew', 'email' => strtolower($name).'@test.test', 'password' => 'password',
            'role' => User::ROLE_CREW, 'ambulance_id' => $ambulance->id, 'on_duty' => true,
        ]);

        return $ambulance;
    }

    private function incident(): Incident
    {
        return Incident::create([
            'caller_name' => 'Caller', 'caller_phone' => '0917', 'barangay_id' => $this->barangay->id,
            'lat' => 10.2555, 'lng' => 123.948, 'emergency_type' => 'cardiac', 'priority' => 'critical',
            'status' => Incident::AWAITING_UNIT,
        ]);
    }

    public function test_only_available_units_with_fresh_gps_are_ranked_nearest_first(): void
    {
        $this->unit('Far', 10.2800, 123.9700);
        $this->unit('Near', 10.2556, 123.9481);
        $this->unit('Stale', 10.2556, 123.9481, ['last_seen_at' => now()->subMinutes(10)]);
        $this->unit('Busy', 10.2556, 123.9481, ['status' => Ambulance::ON_SCENE]);

        $names = app(DispatchService::class)->candidates(10.2555, 123.948, $this->barangay->id)
            ->map(fn ($c) => $c['ambulance']->name)->all();

        $this->assertSame(['Near', 'Far'], $names);
    }

    public function test_declined_offer_is_passed_to_the_next_nearest_unit(): void
    {
        $near = $this->unit('Near', 10.2556, 123.9481);
        $far = $this->unit('Far', 10.2800, 123.9700);
        $incident = $this->incident();
        $service = app(DispatchService::class);

        $service->escalate($incident);
        $offer = Dispatch::where('ambulance_id', $near->id)->firstOrFail();
        $this->assertSame(Ambulance::DISPATCHED, $near->fresh()->status);

        $service->decline($offer);

        $this->assertSame(Ambulance::AVAILABLE, $near->fresh()->status);
        $this->assertSame(Ambulance::DISPATCHED, $far->fresh()->status);
        $this->assertSame($far->id, $incident->fresh()->assigned_ambulance_id);
    }

    public function test_unanswered_offer_expires_and_escalates(): void
    {
        $near = $this->unit('Near', 10.2556, 123.9481);
        $far = $this->unit('Far', 10.2800, 123.9700);
        $incident = $this->incident();
        $service = app(DispatchService::class);

        $service->escalate($incident);
        $offer = Dispatch::where('ambulance_id', $near->id)->firstOrFail();
        $offer->update(['expires_at' => now()->subSeconds(5)]);

        $service->expire($offer);

        $this->assertSame(Dispatch::EXPIRED, $offer->fresh()->status);
        $this->assertSame($far->id, $incident->fresh()->assigned_ambulance_id);
    }

    public function test_dispatchers_are_alerted_when_no_unit_is_available(): void
    {
        $dispatcher = User::create(['name' => 'D', 'email' => 'd@test.test', 'password' => 'password', 'role' => User::ROLE_DISPATCHER]);
        $incident = $this->incident();

        app(DispatchService::class)->escalate($incident);

        $this->assertSame(Incident::AWAITING_UNIT, $incident->fresh()->status);
        $this->assertSame('No ambulance available', $dispatcher->notifications()->first()->data['title']);
    }

    public function test_accepting_after_the_window_is_rejected(): void
    {
        $near = $this->unit('Near', 10.2556, 123.9481);
        $incident = $this->incident();
        $service = app(DispatchService::class);
        $service->escalate($incident);
        $offer = Dispatch::where('ambulance_id', $near->id)->firstOrFail();
        $offer->update(['expires_at' => now()->subSeconds(30)]);

        Sanctum::actingAs(User::where('ambulance_id', $near->id)->first());
        $this->postJson("/api/crew/dispatches/{$offer->id}/accept")->assertStatus(409);
    }

    public function test_full_trip_from_offer_to_completed(): void
    {
        $near = $this->unit('Near', 10.2556, 123.9481);
        $hospital = Hospital::create(['name' => 'General', 'latitude' => 10.30, 'longitude' => 123.95]);
        $incident = $this->incident();
        app(DispatchService::class)->escalate($incident);
        $offer = Dispatch::where('ambulance_id', $near->id)->firstOrFail();

        Sanctum::actingAs(User::where('ambulance_id', $near->id)->first());
        $this->postJson("/api/crew/dispatches/{$offer->id}/accept")->assertOk();
        $this->assertSame(Ambulance::EN_ROUTE, $near->fresh()->status);
        $this->assertNotNull($offer->fresh()->route_geojson);

        $this->postJson("/api/crew/dispatches/{$offer->id}/advance", ['to' => 'on_scene'])->assertOk();
        $this->postJson("/api/crew/dispatches/{$offer->id}/advance", ['to' => 'transporting'])->assertStatus(422);
        $this->postJson("/api/crew/dispatches/{$offer->id}/advance", ['to' => 'transporting', 'hospital_id' => $hospital->id])->assertOk();
        $this->postJson("/api/crew/dispatches/{$offer->id}/advance", ['to' => 'at_hospital'])->assertOk();
        $this->postJson("/api/crew/dispatches/{$offer->id}/advance", ['to' => 'completed'])->assertOk();

        $this->assertSame(Ambulance::AVAILABLE, $near->fresh()->status);
        $this->assertSame(Incident::COMPLETED, $incident->fresh()->status);
    }

    public function test_reports_summarise_a_completed_trip_and_admin_can_manage_hospitals(): void
    {
        $near = $this->unit('Near', 10.2556, 123.9481);
        $hospital = Hospital::create(['name' => 'General', 'latitude' => 10.30, 'longitude' => 123.95]);
        $incident = $this->incident();
        app(DispatchService::class)->escalate($incident);
        $offer = Dispatch::where('ambulance_id', $near->id)->firstOrFail();

        Sanctum::actingAs(User::where('ambulance_id', $near->id)->first());
        $this->postJson("/api/crew/dispatches/{$offer->id}/accept")->assertOk();
        $this->postJson("/api/crew/dispatches/{$offer->id}/advance", ['to' => 'on_scene'])->assertOk();
        $this->postJson("/api/crew/dispatches/{$offer->id}/advance", ['to' => 'completed'])->assertOk();

        $admin = User::create(['name' => 'A', 'email' => 'a@test.test', 'password' => 'password', 'role' => User::ROLE_ADMIN]);
        Sanctum::actingAs($admin);
        $this->getJson('/api/reports?days=30')->assertOk()
            ->assertJsonPath('totals.total', 1)->assertJsonPath('totals.completed', 1)
            ->assertJsonPath('by_ambulance.0.name', 'Near');

        $this->postJson('/api/admin/hospitals', ['name' => 'New Hospital', 'lat' => 10.3, 'lng' => 123.9])->assertCreated();
        $this->deleteJson("/api/admin/hospitals/{$hospital->id}")->assertOk();
    }
}
