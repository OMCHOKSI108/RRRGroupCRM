<?php

namespace Database\Seeders;

use App\Models\Lead;
use App\Models\Property;
use Illuminate\Database\Seeder;

class LeadCoordinatesSeeder extends Seeder
{
    public function run(): void
    {
        $tenant1Metros = [
            ['city' => 'Dallas', 'state' => 'TX', 'lat' => 32.7767, 'lng' => -96.7970, 'weight' => 30],
            ['city' => 'Fort Worth', 'state' => 'TX', 'lat' => 32.7555, 'lng' => -97.3308, 'weight' => 15],
            ['city' => 'Houston', 'state' => 'TX', 'lat' => 29.7604, 'lng' => -95.3698, 'weight' => 25],
            ['city' => 'Austin', 'state' => 'TX', 'lat' => 30.2672, 'lng' => -97.7431, 'weight' => 15],
            ['city' => 'San Antonio', 'state' => 'TX', 'lat' => 29.4241, 'lng' => -98.4936, 'weight' => 10],
            ['city' => 'Atlanta', 'state' => 'GA', 'lat' => 33.7490, 'lng' => -84.3880, 'weight' => 10],
            ['city' => 'Tampa', 'state' => 'FL', 'lat' => 27.9506, 'lng' => -82.4572, 'weight' => 8],
            ['city' => 'Phoenix', 'state' => 'AZ', 'lat' => 33.4484, 'lng' => -112.0740, 'weight' => 8],
            ['city' => 'London', 'state' => 'UK', 'lat' => 51.5074, 'lng' => -0.1278, 'weight' => 2],
        ];

        $tenant2Metros = [
            ['city' => 'Miami', 'state' => 'FL', 'lat' => 25.7617, 'lng' => -80.1918, 'weight' => 25],
            ['city' => 'Orlando', 'state' => 'FL', 'lat' => 28.5383, 'lng' => -81.3792, 'weight' => 15],
            ['city' => 'New York', 'state' => 'NY', 'lat' => 40.7128, 'lng' => -74.0060, 'weight' => 25],
            ['city' => 'Los Angeles', 'state' => 'CA', 'lat' => 34.0522, 'lng' => -118.2437, 'weight' => 20],
            ['city' => 'Chicago', 'state' => 'IL', 'lat' => 41.8781, 'lng' => -87.6298, 'weight' => 15],
            ['city' => 'Seattle', 'state' => 'WA', 'lat' => 47.6062, 'lng' => -122.3321, 'weight' => 10],
            ['city' => 'Denver', 'state' => 'CO', 'lat' => 39.7392, 'lng' => -104.9903, 'weight' => 10],
            ['city' => 'Tokyo', 'state' => 'JP', 'lat' => 35.6762, 'lng' => 139.6503, 'weight' => 2],
        ];

        $this->seedTenantCoordinates(1, $tenant1Metros);
        $this->seedTenantCoordinates(2, $tenant2Metros);
    }

    private function seedTenantCoordinates(int $tenantId, array $metros): void
    {
        $leads = Lead::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->get();

        $metroPool = [];
        foreach ($metros as $metro) {
            for ($i = 0; $i < $metro['weight']; $i++) {
                $metroPool[] = $metro;
            }
        }

        $count = $leads->count();
        $nullCount = max(1, (int) round($count * 0.06)); // ~6% null coordinates

        foreach ($leads as $index => $lead) {
            if ($index < $nullCount) {
                // Keep coordinates null for testing clientsWithoutCoordinates
                $lead->updateQuietly([
                    'latitude' => null,
                    'longitude' => null,
                ]);

                Property::withoutGlobalScopes()
                    ->where('lead_id', $lead->id)
                    ->update([
                        'latitude' => null,
                        'longitude' => null,
                    ]);
                continue;
            }

            $metro = $metroPool[array_rand($metroPool)];

            // Create realistic jitter around the metro
            // Jitter between -0.15 and +0.15 degrees (~10-15 miles)
            $latJitter = (mt_rand(-1500, 1500) / 10000);
            $lngJitter = (mt_rand(-1500, 1500) / 10000);

            $lat = round($metro['lat'] + $latJitter, 7);
            $lng = round($metro['lng'] + $lngJitter, 7);

            $lead->updateQuietly([
                'latitude' => $lat,
                'longitude' => $lng,
            ]);

            Property::withoutGlobalScopes()
                ->where('lead_id', $lead->id)
                ->update([
                    'latitude' => $lat,
                    'longitude' => $lng,
                    'city' => $metro['city'],
                    'state' => $metro['state'],
                ]);
        }
    }
}
