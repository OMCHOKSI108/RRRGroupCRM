<?php

namespace Tests\Unit\Services;

use App\Models\Deal;
use App\Services\DocumentMergeService;
use Tests\TestCase;

class DocumentMergeServiceTest extends TestCase
{
    protected DocumentMergeService $mergeService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mergeService = new DocumentMergeService();
    }

    public function test_merge_succeeds_when_deal_has_lead_without_property(): void
    {
        $this->createTenantWithAdmin();
        $this->actingAs($this->adminUser);

        // Lead with no property record
        $lead = $this->createLead([
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        $deal = $this->createDeal([
            'lead_id' => $lead->id,
            'title' => 'Deal Without Property',
            'contract_price' => 150000,
        ]);

        $template = "Lead: {{lead.full_name}}, SqFt: {{property.square_footage}}";
        $merged = $this->mergeService->merge($template, $deal);

        $this->assertStringContainsString('Lead: John Doe', $merged);
        $this->assertStringContainsString('SqFt: ', $merged);
    }

    public function test_merge_does_not_crash_when_unauthenticated_and_deal_has_no_tenant(): void
    {
        auth()->logout();

        $deal = new Deal(['title' => 'Standalone Deal']);

        $merged = $this->mergeService->merge("Title: {{deal.title}}, Company: {{company.name}}", $deal);

        $this->assertStringContainsString('Title: Standalone Deal', $merged);
    }

    public function test_merge_works_successfully_when_property_and_auth_present(): void
    {
        $this->createTenantWithAdmin();
        $this->actingAs($this->adminUser);

        $lead = $this->createLead([
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        $property = $this->createProperty([
            'lead_id' => $lead->id,
            'address' => '123 Main St',
            'city' => 'Orlando',
            'state' => 'FL',
            'square_footage' => 2000,
        ]);

        $deal = $this->createDeal([
            'lead_id' => $lead->id,
            'title' => 'Main St Deal',
            'contract_price' => 250000,
            'stage' => 'under_contract',
        ]);

        $template = "Seller: {{lead.full_name}}, Address: {{property.address}}, Price: {{deal.contract_price}}";
        $merged = $this->mergeService->merge($template, $deal);

        $this->assertStringContainsString('Seller: John Doe', $merged);
        $this->assertStringContainsString('Address: 123 Main St', $merged);
        $this->assertStringContainsString('Price: $250,000.00', $merged);
    }

    public function test_preview_with_sample_data_renders_template(): void
    {
        $template = "Hello {{lead.full_name}}, your property at {{property.address}} is under contract for {{deal.contract_price}}.";
        $preview = $this->mergeService->previewWithSampleData($template);

        $this->assertStringContainsString('John Smith', $preview);
        $this->assertStringContainsString('123 Main Street', $preview);
        $this->assertStringContainsString('$185,000.00', $preview);
    }
}
