<?php

namespace Tests\Unit;

use App\Models\Buyer;
use App\Models\Deal;
use App\Models\DealOffer;
use App\Models\Lead;
use App\Models\Property;
use App\Models\Showing;
use App\Models\Tenant;
use App\Models\TransactionChecklist;
use App\Services\AddressNormalizationService;
use App\Services\BuyerMatchService;
use Tests\TestCase;

class EdgeCaseBreakTest extends TestCase
{
    public function test_break_property_assignment_fee_formula_is_inverted(): void
    {
        $this->createTenantWithAdmin();

        // Wholesaling scenario:
        // ARV = $200,000, Repairs = $30,000
        // MAO = (200,000 * 0.70) - 30,000 = $110,000
        // Our Offer = $100,000
        // Expected Assignment Fee = MAO - Our Offer = +$10,000
        $property = $this->createProperty([
            'after_repair_value' => 200000,
            'repair_estimate' => 30000,
            'our_offer' => 100000,
        ]);

        // BUG CONFIRMATION: The actual calculation evaluates to:
        // 100,000 - 140,000 - 30,000 = -$70,000 (negative seventy thousand!)
        $this->assertEquals(-70000, $property->assignment_fee);
    }

    public function test_break_address_normalization_corrupts_saint_street_names(): void
    {
        // "St John St" represents "Saint John Street"
        $normalized = AddressNormalizationService::normalize('123 St John St');

        // BUG CONFIRMATION: Blind substitution replaces "St" everywhere, turning it into "Street John Street"
        $this->assertEquals('123 Street John Street', $normalized);
    }

    public function test_break_due_today_checklist_item_is_prematurely_marked_overdue(): void
    {
        $this->createTenantWithAdmin();
        $deal = $this->createDeal();

        // Deadline is set to today
        $item = TransactionChecklist::create([
            'tenant_id' => $this->tenant->id,
            'deal_id' => $deal->id,
            'item_key' => 'inspection',
            'label' => 'Home Inspection',
            'status' => 'in_progress',
            'deadline' => now()->toDateString(),
        ]);

        // BUG CONFIRMATION: Because date cast truncates to 00:00:00, isPast() is true all day today
        $this->assertTrue($item->is_overdue);
    }

    public function test_break_showing_outcome_label_crashes_with_type_error_on_null(): void
    {
        // Scheduled showing with no outcome yet
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('App\Models\Showing::outcomeLabel(): Argument #1 ($outcome) must be of type string, null given');

        Showing::outcomeLabel(null);
    }

    public function test_break_buyer_match_ignores_real_estate_mode_when_unauthenticated(): void
    {
        // Tenant is explicitly Real Estate
        $reTenant = Tenant::create([
            'name' => 'RE Brokerage',
            'slug' => 're-brokerage',
            'email' => 'broker@test.com',
            'business_mode' => 'realestate',
        ]);

        $lead = Lead::withoutGlobalScopes()->create([
            'tenant_id' => $reTenant->id,
            'first_name' => 'Home',
            'last_name' => 'Seller',
            'status' => 'active_listing',
        ]);

        $property = Property::withoutGlobalScopes()->create([
            'tenant_id' => $reTenant->id,
            'lead_id' => $lead->id,
            'address' => '456 Elm St',
            'city' => 'Chicago',
            'state' => 'IL',
            'zip_code' => '60601',
            'list_price' => 300000,
        ]);

        $deal = Deal::withoutGlobalScopes()->create([
            'tenant_id' => $reTenant->id,
            'lead_id' => $lead->id,
            'title' => '456 Elm St Listing',
            'contract_price' => null, // In RE listings, list_price on property is used
        ]);

        // Buyer who can afford $350k
        $buyer = Buyer::withoutGlobalScopes()->create([
            'tenant_id' => $reTenant->id,
            'first_name' => 'Bob',
            'last_name' => 'Buyer',
            'max_purchase_price' => 350000,
            'preferred_zip_codes' => ['60601'],
        ]);

        // Log out so no authenticated session exists (simulating queue worker or CLI)
        auth()->logout();

        $service = new BuyerMatchService();
        $matches = $service->matchForDeal($deal);

        // In Real Estate mode, buyer matches on $property->list_price <= 350k (+25) and zip (+20) -> score 45.
        // BUG CONFIRMATION: Because auth()->user() is null, it evaluates as wholesale mode,
        // which looks for $deal->contract_price (which is null) -> score 30 instead of 45!
        $this->assertEquals(30, $matches->first()['score']);
    }

    public function test_break_deal_offer_is_expired_returns_false_for_countered_or_expired_status(): void
    {
        $this->createTenantWithAdmin();
        $deal = $this->createDeal();

        // Expired offer where status is set to 'countered'
        $offer = DealOffer::create([
            'tenant_id' => $this->tenant->id,
            'deal_id' => $deal->id,
            'buyer_name' => 'Acme Holdings',
            'offer_price' => 100000,
            'status' => 'countered',
            'expiration_date' => now()->subDays(5),
        ]);

        // BUG CONFIRMATION: is_expired only checks $this->status === 'pending', so it returns false!
        $this->assertFalse($offer->is_expired);
    }
}
