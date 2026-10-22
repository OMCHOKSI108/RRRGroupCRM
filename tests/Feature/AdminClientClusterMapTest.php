<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Tests\TestCase;

class AdminClientClusterMapTest extends TestCase
{
    /**
     * 1. Authorized admin can retrieve clusters.
     */
    public function test_authorized_admin_can_retrieve_clusters(): void
    {
        $this->actingAsAdmin();

        $this->createLead([
            'latitude' => 32.7767,
            'longitude' => -96.7970,
        ]);
        $this->createLead([
            'latitude' => 32.7555,
            'longitude' => -97.3308,
        ]);

        $response = $this->getJson('/api/v1/admin/analytics/client-map/clusters?zoom=4');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'clusters' => [
                        '*' => [
                            'id',
                            'latitude',
                            'longitude',
                            'clientCount',
                            'bounds' => ['south', 'west', 'north', 'east'],
                        ],
                    ],
                    'summary' => [
                        'totalMatchingClients',
                        'clientsWithCoordinates',
                        'clientsWithoutCoordinates',
                        'returnedClusterCount',
                    ],
                    'meta' => [
                        'zoom',
                        'filtersApplied',
                        'hasMore',
                    ],
                ],
            ]);

        $this->assertGreaterThanOrEqual(1, count($response->json('data.clusters')));
        $this->assertEquals(2, $response->json('data.summary.totalMatchingClients'));
        $this->assertEquals(2, $response->json('data.summary.clientsWithCoordinates'));
        $this->assertEquals(0, $response->json('data.summary.clientsWithoutCoordinates'));
    }

    /**
     * 2. Unauthenticated and unauthorized requests are rejected.
     */
    public function test_unauthenticated_requests_are_rejected(): void
    {
        $response = $this->getJson('/api/v1/admin/analytics/client-map/clusters');
        $response->assertStatus(401);
    }

    public function test_non_admin_users_are_forbidden(): void
    {
        $this->actingAsRole('agent');

        $response = $this->getJson('/api/v1/admin/analytics/client-map/clusters');
        $response->assertStatus(403);

        $viewResponse = $this->get('/admin/client-map');
        $viewResponse->assertStatus(403);
    }

    /**
     * 3. Clients from another tenant are never included.
     */
    public function test_clients_from_another_tenant_are_never_included(): void
    {
        $this->actingAsAdmin();

        // 3 leads for Tenant A
        $this->createLead(['latitude' => 32.7767, 'longitude' => -96.7970]);
        $this->createLead(['latitude' => 32.7768, 'longitude' => -96.7971]);
        $this->createLead(['latitude' => 32.7769, 'longitude' => -96.7972]);

        // Create Tenant B with its own leads at the same location
        $tenantB = Tenant::create([
            'name' => 'Competitor Corp',
            'slug' => 'competitor-corp',
            'email' => 'admin@competitor.com',
            'status' => 'active',
        ]);
        $adminRole = Role::where('name', 'admin')->first();
        $adminB = User::factory()->create([
            'tenant_id' => $tenantB->id,
            'role_id' => $adminRole->id,
        ]);

        Lead::withoutGlobalScopes()->create([
            'tenant_id' => $tenantB->id,
            'agent_id' => $adminB->id,
            'first_name' => 'Rival',
            'last_name' => 'Client',
            'status' => 'new',
            'lead_source' => 'website',
            'temperature' => 'warm',
            'latitude' => 32.7767,
            'longitude' => -96.7970,
        ]);

        // Admin A queries clusters
        $response = $this->getJson('/api/v1/admin/analytics/client-map/clusters?zoom=10');

        $response->assertStatus(200);
        $this->assertEquals(3, $response->json('data.summary.totalMatchingClients'));
        $this->assertEquals(3, $response->json('data.summary.clientsWithCoordinates'));

        $totalClientsInClusters = array_sum(array_column($response->json('data.clusters'), 'clientCount'));
        $this->assertEquals(3, $totalClientsInClusters);
    }

    /**
     * 4. Duplicate clients are not double-counted.
     */
    public function test_duplicate_clients_are_not_double_counted(): void
    {
        $this->actingAsAdmin();

        $this->createLead(['latitude' => 30.2672, 'longitude' => -97.7431]);
        $this->createLead(['latitude' => 30.2672, 'longitude' => -97.7431]);
        $this->createLead(['latitude' => 30.2672, 'longitude' => -97.7431]);

        $response = $this->getJson('/api/v1/admin/analytics/client-map/clusters?zoom=6');

        $response->assertStatus(200);
        $totalClientsInClusters = array_sum(array_column($response->json('data.clusters'), 'clientCount'));
        $this->assertEquals(3, $totalClientsInClusters);
        $this->assertEquals(3, $response->json('data.summary.clientsWithCoordinates'));
    }

    /**
     * 5. Missing or invalid coordinates are handled correctly.
     */
    public function test_missing_coordinates_are_excluded_from_clusters_and_counted_in_summary(): void
    {
        $this->actingAsAdmin();

        $this->createLead(['latitude' => 33.7490, 'longitude' => -84.3880]);
        $this->createLead(['latitude' => null, 'longitude' => null]);
        $this->createLead(['latitude' => 33.7500, 'longitude' => null]);

        $response = $this->getJson('/api/v1/admin/analytics/client-map/clusters?zoom=5');

        $response->assertStatus(200);
        $this->assertEquals(3, $response->json('data.summary.totalMatchingClients'));
        $this->assertEquals(1, $response->json('data.summary.clientsWithCoordinates'));
        $this->assertEquals(2, $response->json('data.summary.clientsWithoutCoordinates'));

        $clusters = $response->json('data.clusters');
        $this->assertCount(1, $clusters);
        $this->assertEquals(1, $clusters[0]['clientCount']);
        $this->assertEquals(33.749, round($clusters[0]['latitude'], 3));
    }

    /**
     * 6. Empty datasets return valid empty results.
     */
    public function test_empty_datasets_return_valid_empty_results(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/api/v1/admin/analytics/client-map/clusters');

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'clusters' => [],
                    'summary' => [
                        'totalMatchingClients' => 0,
                        'clientsWithCoordinates' => 0,
                        'clientsWithoutCoordinates' => 0,
                        'returnedClusterCount' => 0,
                    ],
                    'meta' => [
                        'hasMore' => false,
                    ],
                ],
            ]);
    }

    /**
     * 7. Invalid bounding boxes, zoom values, and date ranges are rejected.
     */
    public function test_invalid_parameters_return_validation_errors(): void
    {
        $this->actingAsAdmin();

        // Malformed bbox
        $this->getJson('/api/v1/admin/analytics/client-map/clusters?bbox=not-a-box')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['bbox']);

        // Inverted latitude (south > north)
        $this->getJson('/api/v1/admin/analytics/client-map/clusters?bbox=-100,50,-90,40')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['bbox']);

        // Out of range latitude
        $this->getJson('/api/v1/admin/analytics/client-map/clusters?bbox=-100,-100,-90,40')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['bbox']);

        // Invalid zoom
        $this->getJson('/api/v1/admin/analytics/client-map/clusters?zoom=30')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['zoom']);

        $this->getJson('/api/v1/admin/analytics/client-map/clusters?zoom=-2')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['zoom']);

        // Inverted date range (from > to)
        $this->getJson('/api/v1/admin/analytics/client-map/clusters?from=2026-10-20&to=2026-10-10')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['to']);

        // Invalid limit
        $this->getJson('/api/v1/admin/analytics/client-map/clusters?limit=5000')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['limit']);
    }

    /**
     * 8. Viewport filtering returns only applicable geographic clusters.
     */
    public function test_viewport_filtering_returns_only_applicable_clusters(): void
    {
        $this->actingAsAdmin();

        // Texas lead
        $this->createLead(['latitude' => 32.7767, 'longitude' => -96.7970]);
        // New York lead
        $this->createLead(['latitude' => 40.7128, 'longitude' => -74.0060]);

        // Bbox covering only Texas area
        $response = $this->getJson('/api/v1/admin/analytics/client-map/clusters?bbox=-105,25,-90,37&zoom=5');

        $response->assertStatus(200);
        $this->assertEquals(2, $response->json('data.summary.totalMatchingClients'));
        $this->assertEquals(2, $response->json('data.summary.clientsWithCoordinates'));
        $this->assertEquals(1, $response->json('data.summary.returnedClusterCount'));

        $clusters = $response->json('data.clusters');
        $this->assertCount(1, $clusters);
        $this->assertEquals(32.7767, round($clusters[0]['latitude'], 4));
    }

    /**
     * 9. Status and assigned-user filters produce correct counts.
     */
    public function test_status_and_assigned_user_filters(): void
    {
        $this->actingAsAdmin();

        $agent = $this->createUserWithRole('agent');

        $this->createLead([
            'agent_id' => $this->adminUser->id,
            'status' => 'new',
            'latitude' => 25.7617,
            'longitude' => -80.1918,
        ]);
        $this->createLead([
            'agent_id' => $agent->id,
            'status' => 'closed_won',
            'latitude' => 25.7620,
            'longitude' => -80.1920,
        ]);

        // Filter by status=new
        $statusResp = $this->getJson('/api/v1/admin/analytics/client-map/clusters?status=new');
        $statusResp->assertStatus(200);
        $this->assertEquals(1, $statusResp->json('data.summary.totalMatchingClients'));
        $this->assertEquals(1, $statusResp->json('data.summary.returnedClusterCount'));

        // Filter by assigned agent
        $agentResp = $this->getJson("/api/v1/admin/analytics/client-map/clusters?assignedUserId={$agent->id}");
        $agentResp->assertStatus(200);
        $this->assertEquals(1, $agentResp->json('data.summary.totalMatchingClients'));
        $this->assertEquals($agent->id, $agentResp->json('data.meta.filtersApplied.assignedUserId'));
    }

    /**
     * 10. Summary counts match the filtered eligible dataset.
     */
    public function test_summary_counts_consistency(): void
    {
        $this->actingAsAdmin();

        $this->createLead(['latitude' => 34.0522, 'longitude' => -118.2437]);
        $this->createLead(['latitude' => 34.0525, 'longitude' => -118.2440]);
        $this->createLead(['latitude' => null, 'longitude' => null]);
        $this->createLead(['latitude' => null, 'longitude' => null]);

        $response = $this->getJson('/api/v1/admin/analytics/client-map/clusters?zoom=6');
        $response->assertStatus(200);

        $summary = $response->json('data.summary');
        $this->assertEquals(4, $summary['totalMatchingClients']);
        $this->assertEquals(2, $summary['clientsWithCoordinates']);
        $this->assertEquals(2, $summary['clientsWithoutCoordinates']);
        $this->assertEquals($summary['totalMatchingClients'], $summary['clientsWithCoordinates'] + $summary['clientsWithoutCoordinates']);
    }

    /**
     * 11. Limit capping is applied appropriately.
     */
    public function test_limit_capping_and_has_more(): void
    {
        $this->actingAsAdmin();

        // 3 distant leads that will form 3 separate clusters
        $this->createLead(['latitude' => 10.0, 'longitude' => 10.0]);
        $this->createLead(['latitude' => 40.0, 'longitude' => 40.0]);
        $this->createLead(['latitude' => 70.0, 'longitude' => 70.0]);

        $response = $this->getJson('/api/v1/admin/analytics/client-map/clusters?zoom=5&limit=2');
        $response->assertStatus(200);

        $this->assertCount(2, $response->json('data.clusters'));
        $this->assertTrue($response->json('data.meta.hasMore'));
    }

    /**
     * 12. Antimeridian-crossing bounding boxes are handled correctly.
     */
    public function test_antimeridian_crossing_bounding_box(): void
    {
        $this->actingAsAdmin();

        // Fiji / Pacific (lng: 178.0)
        $this->createLead(['latitude' => -18.0, 'longitude' => 178.0]);
        // Samoa (lng: -172.0)
        $this->createLead(['latitude' => -14.0, 'longitude' => -172.0]);
        // Africa (lng: 20.0, outside Pacific antimeridian)
        $this->createLead(['latitude' => 0.0, 'longitude' => 20.0]);

        // Antimeridian crossing bbox: west: 170, south: -25, east: -160, north: 0
        $response = $this->getJson('/api/v1/admin/analytics/client-map/clusters?bbox=170,-25,-160,0&zoom=3');

        $response->assertStatus(200);
        $this->assertEquals(3, $response->json('data.summary.totalMatchingClients'));

        $clusters = $response->json('data.clusters');
        $this->assertCount(2, $clusters);

        $longitudes = array_column($clusters, 'longitude');
        foreach ($longitudes as $lon) {
            $this->assertTrue($lon >= 170 || $lon <= -160, "Longitude {$lon} should be within the antimeridian bbox");
        }
    }

    /**
     * 13. Existing CRM functionality remains unaffected and admin map page renders.
     */
    public function test_admin_map_page_renders_cleanly(): void
    {
        $this->actingAsAdmin();

        $response = $this->get('/admin/client-map');
        $response->assertStatus(200)
            ->assertSee('Client Cluster Map')
            ->assertSee('cluster-map')
            ->assertSee('OpenStreetMap');
    }
}
