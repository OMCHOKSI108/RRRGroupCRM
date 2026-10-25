<?php

namespace Tests\Unit\Services;

use App\Models\Buyer;
use App\Services\BuyerScoreService;
use Tests\TestCase;

class BuyerScoreServiceTest extends TestCase
{
    public function test_new_buyer_starts_at_zero(): void
    {
        $buyer = new Buyer();
        $this->assertEquals(0, BuyerScoreService::calculate($buyer));
    }

    public function test_pof_verified_adds_twenty_points(): void
    {
        $buyer = new Buyer(['pof_verified' => true]);
        $this->assertEquals(20, BuyerScoreService::calculate($buyer));
    }

    public function test_purchase_volume_and_speed_scoring(): void
    {
        // 6+ purchases (+30), <14 avg close days (+20), <30 days recency (+15)
        $buyer = new Buyer([
            'total_purchases' => 7,
            'avg_close_days' => 10,
            'last_purchase_at' => now()->subDays(5),
        ]);

        $this->assertEquals(65, BuyerScoreService::calculate($buyer));
    }

    public function test_profile_completeness_and_preferences(): void
    {
        $buyer = new Buyer([
            'phone' => '555-1234',
            'email' => 'buyer@example.com',
            'company' => 'Acme Capital',
            'preferred_property_types' => ['single_family'],
            'preferred_zip_codes' => ['32801'],
        ]);

        // phone+email+company = +5, preferences = +10 -> total 15
        $this->assertEquals(15, BuyerScoreService::calculate($buyer));
    }

    public function test_score_is_capped_at_one_hundred(): void
    {
        $buyer = new Buyer([
            'pof_verified' => true, // +20
            'total_purchases' => 10, // +30
            'avg_close_days' => 7, // +20
            'last_purchase_at' => now()->subDays(2), // +15
            'phone' => '555-1234', //
            'email' => 'buyer@example.com', //
            'company' => 'Acme Capital', // +5
            'preferred_property_types' => ['single_family'],
            'preferred_zip_codes' => ['32801'], // +10
        ]);

        // Total raw points = 20 + 30 + 20 + 15 + 5 + 10 = 100
        $this->assertEquals(100, BuyerScoreService::calculate($buyer));
    }
}
