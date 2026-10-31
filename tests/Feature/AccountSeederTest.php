<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_chart_of_accounts_are_seeded_idempotently(): void
    {
        $this->seed();
        $this->seed();

        $this->assertDatabaseCount('accounts', 122);
        $this->assertDatabaseHas('accounts', ['account' => 'AFNB SPARKLE AMAZON A/C 9897']);
        $this->assertDatabaseHas('accounts', ['account' => 'Ask My Accountant']);
        $this->assertDatabaseHas('accounts', ['account' => 'Sales Orders']);
        $this->assertSame(122, DB::table('accounts')->distinct()->count('account'));
    }
}
