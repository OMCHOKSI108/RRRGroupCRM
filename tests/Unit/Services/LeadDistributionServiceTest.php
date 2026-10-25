<?php

namespace Tests\Unit\Services;

use App\Models\Lead;
use App\Models\User;
use App\Services\LeadDistributionService;
use Tests\TestCase;

class LeadDistributionServiceTest extends TestCase
{
    protected LeadDistributionService $distributionService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->distributionService = new LeadDistributionService();
        $this->createTenantWithAdmin(['distribution_method' => 'round_robin']);
    }

    public function test_round_robin_distributes_sequentially_between_agents(): void
    {
        $agent1 = $this->createUserWithRole('acquisition_agent');
        $agent2 = $this->createUserWithRole('acquisition_agent');

        // All users for tenant are admin, agent1, agent2
        $lead1 = $this->createLead(['agent_id' => null]);
        $assigned1 = $this->distributionService->distribute($lead1, $this->tenant);

        $lead2 = $this->createLead(['agent_id' => null]);
        $assigned2 = $this->distributionService->distribute($lead2, $this->tenant);

        $this->assertNotNull($assigned1);
        $this->assertNotNull($assigned2);
        $this->assertNotEquals($assigned1->id, $assigned2->id);
    }

    public function test_round_robin_only_assigns_to_assignable_roles(): void
    {
        // Deactivate admin so only scout and an agent exist
        $this->adminUser->update(['is_active' => false]);

        $scout = $this->createUserWithRole('field_scout');
        $agent = $this->createUserWithRole('acquisition_agent');

        // Set round robin index to 0, which would pick scout if scout were included
        $this->tenant->update(['round_robin_index' => 0]);

        $lead = $this->createLead(['agent_id' => null]);
        $assigned = $this->distributionService->distribute($lead, $this->tenant);

        // Scout must NEVER be assigned
        $this->assertNotNull($assigned);
        $this->assertEquals($agent->id, $assigned->id);
        $this->assertTrue($assigned->canManageLeads());
    }

    public function test_shark_tank_leaves_lead_unassigned(): void
    {
        $this->tenant->update(['distribution_method' => 'shark_tank']);
        $lead = $this->createLead(['agent_id' => null]);

        $assigned = $this->distributionService->distribute($lead, $this->tenant);

        $this->assertNull($assigned);
        $this->assertNull($lead->fresh()->agent_id);
    }

    public function test_hybrid_clears_agent_and_leaves_unassigned(): void
    {
        $this->tenant->update(['distribution_method' => 'hybrid']);
        $lead = $this->createLead(['agent_id' => $this->adminUser->id]);

        $assigned = $this->distributionService->distribute($lead, $this->tenant);

        $this->assertNull($assigned);
        $this->assertNull($lead->fresh()->agent_id);
    }
}
