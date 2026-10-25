<?php

namespace Tests\Unit\Services;

use App\Models\Lead;
use App\Models\Property;
use App\Services\ClientClusterService;
use Tests\TestCase;

class ClientClusterServiceTest extends TestCase
{
    protected ClientClusterService $clusterService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clusterService = new ClientClusterService();
        $this->createTenantWithAdmin();
    }

    public function test_empty_tenant_returns_zero_clusters(): void
    {
        $result = $this->clusterService->getClusters($this->tenant->id, ['zoom' => 3]);

        $this->assertEmpty($result['data']['clusters']);
        $this->assertEquals(0, $result['data']['summary']['totalMatchingClients']);
        $this->assertEquals(0, $result['data']['summary']['clientsWithCoordinates']);
        $this->assertEquals(0, $result['data']['summary']['clientsWithoutCoordinates']);
    }

    public function test_separates_clients_with_and_without_coordinates(): void
    {
        // 2 leads with coordinates
        $this->createLead(['latitude' => 28.5383, 'longitude' => -81.3792]);
        $this->createLead(['latitude' => 28.5400, 'longitude' => -81.3800]);

        // 1 lead without coordinates
        $this->createLead(['latitude' => null, 'longitude' => null]);

        $result = $this->clusterService->getClusters($this->tenant->id, ['zoom' => 6]);

        $this->assertEquals(3, $result['data']['summary']['totalMatchingClients']);
        $this->assertEquals(2, $result['data']['summary']['clientsWithCoordinates']);
        $this->assertEquals(1, $result['data']['summary']['clientsWithoutCoordinates']);
        $this->assertNotEmpty($result['data']['clusters']);
    }

    public function test_clusters_respect_tenant_isolation(): void
    {
        $otherTenant = \App\Models\Tenant::create([
            'name' => 'Competitor CRM',
            'slug' => 'competitor-crm',
            'email' => 'comp@example.com',
        ]);

        Lead::withoutGlobalScopes()->create([
            'tenant_id' => $otherTenant->id,
            'first_name' => 'Secret',
            'last_name' => 'Client',
            'status' => 'new',
            'latitude' => 28.5383,
            'longitude' => -81.3792,
        ]);

        $result = $this->clusterService->getClusters($this->tenant->id, ['zoom' => 3]);

        // Our tenant has 0 leads
        $this->assertEquals(0, $result['data']['summary']['totalMatchingClients']);
        $this->assertEmpty($result['data']['clusters']);
    }

    public function test_bounding_box_filtering(): void
    {
        // Lead inside Orlando bbox
        $this->createLead(['latitude' => 28.5383, 'longitude' => -81.3792]);

        // Lead outside in Seattle
        $this->createLead(['latitude' => 47.6062, 'longitude' => -122.3321]);

        $bbox = [
            'west' => -82.0,
            'south' => 28.0,
            'east' => -81.0,
            'north' => 29.0,
        ];

        $result = $this->clusterService->getClusters($this->tenant->id, [
            'zoom' => 8,
            'bbox' => $bbox,
        ]);

        $this->assertCount(1, $result['data']['clusters']);
        $this->assertEquals(1, $result['data']['clusters'][0]['clientCount']);
    }

    public function test_single_client_detail_loaded_at_high_zoom(): void
    {
        $lead = $this->createLead([
            'first_name' => 'Alice',
            'last_name' => 'Smith',
            'latitude' => 28.5383,
            'longitude' => -81.3792,
        ]);

        $this->createProperty([
            'lead_id' => $lead->id,
            'city' => 'Orlando',
            'state' => 'FL',
        ]);

        $result = $this->clusterService->getClusters($this->tenant->id, [
            'zoom' => 14,
        ]);

        $this->assertCount(1, $result['data']['clusters']);
        $cluster = $result['data']['clusters'][0];
        $this->assertArrayHasKey('client', $cluster);
        $this->assertEquals('Alice Smith', $cluster['client']['name']);
        $this->assertEquals('Orlando', $cluster['client']['city']);
    }
}
