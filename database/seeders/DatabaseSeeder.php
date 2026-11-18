<?php

namespace Database\Seeders;

use App\Models\Ambulance;
use App\Models\Barangay;
use App\Models\Hospital;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Sample data. Barangay and hospital coordinates are APPROXIMATE placeholders:
 * verify them on a map and correct them before any real use.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $barangays = [
            // name => [lat, lng, is_island]
            'Alegria' => [10.2410, 123.9380, false],
            'Bangbang' => [10.2650, 123.9620, false],
            'Buagsong' => [10.2480, 123.9560, false],
            'Catarman' => [10.2530, 123.9420, false],
            'Cogon' => [10.2580, 123.9540, false],
            'Dapitan' => [10.2620, 123.9500, false],
            'Day-as' => [10.2520, 123.9600, false],
            'Gabi' => [10.2440, 123.9500, false],
            'Gilutongan' => [10.2050, 123.9900, true],
            'Ibabao' => [10.2680, 123.9560, false],
            'Pilipog' => [10.2600, 123.9440, false],
            'Poblacion' => [10.2555, 123.9480, false],
            'San Miguel' => [10.2470, 123.9450, false],
        ];
        $ids = [];
        foreach ($barangays as $name => [$lat, $lng, $island]) {
            $ids[$name] = Barangay::updateOrCreate(['name' => $name], ['latitude' => $lat, 'longitude' => $lng, 'is_island' => $island])->id;
        }

        foreach ([
            ['Cordova Rural Health Unit (placeholder)', '032-000-0000', 10.2556, 123.9485],
            ["Mactan Doctors' Hospital (approx. location)", '032-000-0001', 10.3157, 123.9425],
            ['Lapu-Lapu City District Hospital (approx. location)', '032-000-0002', 10.3140, 123.9480],
        ] as [$name, $contact, $lat, $lng]) {
            Hospital::updateOrCreate(['name' => $name], ['contact' => $contact, 'latitude' => $lat, 'longitude' => $lng]);
        }

        $units = [
            'Poblacion-01' => ['ABC 1234', 'Poblacion'],
            'Gabi-01' => ['DEF 5678', 'Gabi'],
            'Day-as-01' => ['GHI 9012', 'Day-as'],
            'Pilipog-01' => ['JKL 3456', 'Pilipog'],
            'Catarman-01' => ['MNO 7890', 'Catarman'],
            'San Miguel-01' => ['PQR 2468', 'San Miguel'],
            'Gilutongan-01' => ['STU 1357', 'Gilutongan'],
        ];

        // Test accounts (password for all: "password")
        User::updateOrCreate(['email' => 'admin@cordova.test'], ['name' => 'MDRRMO Admin', 'password' => 'password', 'role' => User::ROLE_ADMIN, 'phone' => '0917 000 0001']);
        User::updateOrCreate(['email' => 'dispatcher@cordova.test'], ['name' => 'Dispatcher on Duty', 'password' => 'password', 'role' => User::ROLE_DISPATCHER, 'phone' => '0917 000 0002']);
        User::updateOrCreate(['email' => 'dispatcher2@cordova.test'], ['name' => 'Dispatcher (night shift)', 'password' => 'password', 'role' => User::ROLE_DISPATCHER, 'phone' => '0917 000 0003']);

        foreach ($units as $name => [$plate, $home]) {
            $ambulance = Ambulance::updateOrCreate(['name' => $name], ['plate_no' => $plate, 'home_barangay_id' => $ids[$home]]);
            $slug = strtolower(str_replace(' ', '', $home));
            User::updateOrCreate(['email' => "crew.$slug@cordova.test"], [
                'name' => "$home Crew",
                'password' => 'password',
                'role' => User::ROLE_CREW,
                'phone' => '0917 000 1'.str_pad((string) $ambulance->id, 3, '0', STR_PAD_LEFT),
                'ambulance_id' => $ambulance->id,
            ]);
        }
    }
}
