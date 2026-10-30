<?php

namespace Tests\Feature;

use App\Actions\RegisterLibrary;
use App\Livewire\MessageCounter;
use App\Models\CreditPurchase;
use App\Models\Library;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MessageCounterTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Library $lib;
    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        $this->admin->forceFill(['is_super_admin' => true])->save();
        $this->admin = $this->admin->fresh();
        $this->actingAs($this->admin);
        $this->lib = app(RegisterLibrary::class)(['name' => 'Alpha', 'status' => 'active'], ['name' => 'Owner', 'email' => 'o@mc.test']);
        $this->owner = User::where('email', 'o@mc.test')->first();
        Tenancy::set($this->lib);
    }

    protected function tearDown(): void
    {
        Tenancy::flush();
        parent::tearDown();
    }

    public function test_counter_shows_credit_left_and_used_for_each_channel(): void
    {
        CreditPurchase::create(['library_id' => $this->lib->id, 'channel' => 'sms', 'credits' => 10, 'rate' => '0.21', 'amount' => '2.10', 'method' => 'upi', 'reference' => 'S1', 'paid_on' => today(), 'status' => 'approved']);
        CreditPurchase::create(['library_id' => $this->lib->id, 'channel' => 'sms', 'credits' => 500, 'rate' => '0.21', 'amount' => '105', 'method' => 'upi', 'reference' => 'S2', 'paid_on' => today(), 'status' => 'pending']);   // not counted yet
        $this->lib->forceFill(['sms_credits' => 8, 'whatsapp_credits' => 50])->save();

        $this->actingAs($this->owner);
        Livewire::test(MessageCounter::class)
            ->assertSee('SMS Counter')->assertSee('WhatsApp Counter')
            ->assertSeeInOrder(['Credit Messages', '10', 'Left Messages', '8', 'Used Messages', '2'])
            ->assertSeeInOrder(['Credit Messages', '50', 'Left Messages', '50', 'Used Messages', '0']);
    }

    public function test_header_has_the_icons_for_library_users_but_not_the_admin_console(): void
    {
        $this->lib->forceFill(['sms_credits' => 7])->save();
        $this->actingAs($this->owner);
        $this->get("/app/{$this->lib->slug}/help")->assertOk()->assertSee('SMS credits')->assertSee('WhatsApp credits');

        $this->actingAs($this->admin);
        $this->get('/admin')->assertOk()->assertDontSee('SMS Counter');
    }

    public function test_the_counter_only_reads_the_current_librarys_numbers(): void
    {
        $other = app(RegisterLibrary::class)(['name' => 'Beta', 'status' => 'active'], ['name' => 'Other', 'email' => 'o@beta.test']);
        $other->forceFill(['sms_credits' => 999])->save();
        Tenancy::set($this->lib);

        $this->actingAs($this->owner);
        Livewire::test(MessageCounter::class)->assertDontSee('999');
    }
}
