<?php

namespace Tests\Feature;

use App\Actions\RegisterLibrary;
use App\Livewire\MessageCounter;
use App\Models\Library;
use App\Models\MessageLog;
use App\Models\User;
use App\Support\Messaging;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MessageHistoryTest extends TestCase
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
        $this->lib = app(RegisterLibrary::class)(['name' => 'Alpha', 'status' => 'active'], ['name' => 'Owner', 'email' => 'o@mh.test']);
        $this->owner = User::where('email', 'o@mh.test')->first();
        Tenancy::set($this->lib);
    }

    protected function tearDown(): void
    {
        Tenancy::flush();
        parent::tearDown();
    }

    public function test_view_log_opens_the_history_page_listing_each_channels_messages(): void
    {
        $this->lib->forceFill(['sms_credits' => 10, 'whatsapp_credits' => 10])->save();
        Messaging::record('sms', '8209990511', 'Dear A, part payment added for invoice INV1.');
        Messaging::record('whatsapp', '9000000002', 'WhatsApp hello for B');

        $this->actingAs($this->owner);
        Livewire::test(MessageCounter::class)->assertSee(route('app.messages.index', ['library' => $this->lib->slug, 'channel' => 'sms']), false);

        $this->get("/app/{$this->lib->slug}/messages?channel=sms")->assertOk()->assertSee('SMS History')->assertSee('8209990511')->assertSee('part payment added')->assertSee('Sent')->assertDontSee('WhatsApp hello for B');
        $this->get("/app/{$this->lib->slug}/messages?channel=whatsapp")->assertOk()->assertSee('WhatsApp History')->assertSee('WhatsApp hello for B')->assertDontSee('part payment added');
    }

    public function test_an_empty_history_says_so_and_an_unknown_channel_falls_back_to_sms(): void
    {
        $this->actingAs($this->owner);
        $this->get("/app/{$this->lib->slug}/messages?channel=pigeon")->assertOk()->assertSee('SMS History')->assertSee('No SMS messages yet');
    }

    public function test_recording_a_message_takes_credits_by_length_and_refuses_when_short(): void
    {
        $this->lib->forceFill(['sms_credits' => 3, 'whatsapp_credits' => 0])->save();

        $long = str_repeat('a', 170);                                        // two SMS segments
        $a = Messaging::record('sms', '9000000001', $long);
        $this->assertSame([2, 'sent', 1], [$a->count, $a->status, $this->lib->fresh()->sms_credits]);

        $b = Messaging::record('sms', '9000000001', $long);                  // needs 2, has 1
        $this->assertSame(['failed', 'Not enough credits', 1], [$b->status, $b->error, $this->lib->fresh()->sms_credits]);

        $c = Messaging::record('whatsapp', '9000000001', 'hi');              // no WhatsApp credits
        $this->assertSame(['failed', 0], [$c->status, $this->lib->fresh()->whatsapp_credits]);
    }

    public function test_the_history_only_shows_this_librarys_messages(): void
    {
        $other = app(RegisterLibrary::class)(['name' => 'Beta', 'status' => 'active'], ['name' => 'Other', 'email' => 'o@beta2.test']);
        Tenancy::set($other);
        $other->forceFill(['sms_credits' => 5])->save();
        Messaging::record('sms', '7777777777', 'secret message of beta');

        Tenancy::set($this->lib);
        $this->actingAs($this->owner);
        $this->get("/app/{$this->lib->slug}/messages")->assertOk()->assertDontSee('secret message of beta');
        $this->assertSame(0, MessageLog::count());
    }

    public function test_people_without_billing_access_cannot_open_it(): void
    {
        $desk = User::factory()->create();
        $this->lib->users()->attach($desk->id, ['is_active' => true]);
        Tenancy::run($this->lib, fn () => $desk->assignRole(\App\Support\LibraryRoles::SUPERVISOR));

        $this->actingAs($desk->fresh());
        $this->get("/app/{$this->lib->slug}/messages")->assertForbidden();
    }
}
