<?php

namespace Tests\Unit\Services;

use App\Models\DoNotContact;
use App\Models\Lead;
use App\Services\DncService;
use Tests\TestCase;

class DncServiceTest extends TestCase
{
    protected DncService $dncService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dncService = new DncService();
        $this->createTenantWithAdmin();
    }

    public function test_lead_flagged_dnc_returns_true(): void
    {
        $lead = $this->createLead(['do_not_contact' => true]);
        $this->assertTrue($this->dncService->check($lead));
    }

    public function test_matching_phone_on_dnc_list(): void
    {
        DoNotContact::create([
            'tenant_id' => $this->tenant->id,
            'phone' => '5551234567',
            'reason' => 'Requested opt-out',
        ]);

        $lead = $this->createLead([
            'phone' => '5551234567',
            'email' => 'clean@example.com',
            'do_not_contact' => false,
        ]);

        $this->assertTrue($this->dncService->check($lead));
    }

    public function test_matching_email_on_dnc_list(): void
    {
        DoNotContact::create([
            'tenant_id' => $this->tenant->id,
            'email' => 'optout@example.com',
            'reason' => 'Requested opt-out',
        ]);

        $lead = $this->createLead([
            'phone' => '5559876543',
            'email' => 'optout@example.com',
            'do_not_contact' => false,
        ]);

        $this->assertTrue($this->dncService->check($lead));
    }

    public function test_dnc_respects_tenant_isolation(): void
    {
        $otherTenant = \App\Models\Tenant::create([
            'name' => 'Other Co',
            'slug' => 'other-co',
            'email' => 'other@test.com',
        ]);

        DoNotContact::create([
            'tenant_id' => $otherTenant->id,
            'phone' => '5551112222',
            'reason' => 'Other tenant opt-out',
        ]);

        $lead = $this->createLead([
            'phone' => '5551112222',
            'do_not_contact' => false,
        ]);

        $this->assertFalse($this->dncService->check($lead));
    }

    public function test_lead_without_phone_or_email_returns_false_for_dnc(): void
    {
        // Add an unrelated DNC entry for this tenant
        DoNotContact::create([
            'tenant_id' => $this->tenant->id,
            'phone' => '5559998888',
            'reason' => 'Unrelated DNC entry',
        ]);

        $leadWithNoContactInfo = $this->createLead([
            'phone' => null,
            'email' => null,
            'do_not_contact' => false,
        ]);

        // A lead without phone or email must NOT be flagged as DNC
        $this->assertFalse($this->dncService->check($leadWithNoContactInfo));
    }
}
