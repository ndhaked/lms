<?php

namespace Tests\Feature;

use App\Actions\RegisterLibrary;
use App\Models\Lead;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\BillingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    protected SubscriptionPlan $starter;
    protected SubscriptionPlan $pro;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('lead:127.0.0.1');

        $this->starter = SubscriptionPlan::create(['name' => 'Starter', 'description' => 'One small room', 'price_monthly' => 499, 'price_annual' => 4999, 'max_students' => 100, 'max_seats' => 50, 'max_staff' => 3, 'modules' => ['attendance']]);
        $this->pro = SubscriptionPlan::create(['name' => 'Premium', 'price_monthly' => 1999, 'modules' => ['attendance', 'books', 'ebooks']]);
        SubscriptionPlan::create(['name' => 'Retired Plan', 'price_monthly' => 1, 'status' => 'inactive']);
    }

    protected function tearDown(): void
    {
        BillingSettings::flush();
        parent::tearDown();
    }

    protected function admin(array $permissions = null): User
    {
        $u = User::factory()->create();
        $u->forceFill(['is_super_admin' => true, 'platform_permissions' => $permissions])->save();

        return $u->fresh();
    }

    protected function lead(array $over = []): Lead
    {
        return Lead::create(array_merge(['type' => 'trial', 'name' => 'Asha Rao', 'email' => 'asha@example.com', 'phone' => '9876543210', 'library_name' => 'Sunrise Study Hub'], $over));
    }

    // ===================== the public page =====================

    public function test_visitors_see_the_marketing_page_with_the_offer_and_all_features(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        foreach (['first 2 months free', 'Book a demo', 'Claim 2 months free', 'Study libraries', 'Students &amp; memberships', 'Halls, floors &amp; seats', 'Attendance',
            'Fees, dues &amp; receipts', 'Reports &amp; exports', 'Team &amp; permissions', 'Barcode &amp; QR labels', 'Stock-taking', 'Reservations', 'E-books', 'Fines &amp; waivers',
            'How it works', 'Pricing', 'Log in'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        $this->assertStringContainsString(route('login'), $html);
    }

    public function test_pricing_shows_active_plans_only_with_their_real_prices_and_limits(): void
    {
        $html = $this->get('/')->getContent();

        $this->assertStringContainsString('Starter', $html);
        $this->assertStringContainsString('₹499', $html);
        $this->assertStringContainsString('₹4,999', $html);
        $this->assertStringContainsString('Up to 100 students', $html);
        $this->assertStringContainsString('Premium', $html);
        $this->assertStringContainsString('Unlimited students', $html);
        $this->assertStringContainsString('Books &amp; circulation', $html);
        $this->assertStringNotContainsString('Retired Plan', $html);
        $this->assertStringContainsString('?plan='.$this->starter->id.'#offer', $html);
    }

    public function test_add_on_prices_come_from_platform_settings(): void
    {
        BillingSettings::set(['module_prices' => ['books' => ['monthly' => '350'], 'ebooks' => ['monthly' => '150']]]);

        $html = $this->get('/')->getContent();
        $this->assertStringContainsString('₹350', $html);
        $this->assertStringContainsString('₹150', $html);
    }

    public function test_the_offer_follows_the_setting(): void
    {
        BillingSettings::set(['offer_free_days' => 30]);
        $this->get('/')->assertSee('first 1 month free')->assertDontSee('2 months');

        BillingSettings::set(['offer_free_days' => 15]);
        $this->get('/')->assertSee('first 15 days free');

        BillingSettings::set(['offer_free_days' => 0]);
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('free for new libraries', $html);
        $this->assertStringNotContainsString('Limited offer', $html);
        $this->assertStringContainsString('Book a demo', $html);
    }

    public function test_the_page_can_be_switched_off(): void
    {
        BillingSettings::set(['landing_enabled' => false]);
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_signed_in_people_still_go_to_their_workspace(): void
    {
        $this->actingAs($this->admin())->get('/')->assertRedirect('/admin');

        auth()->logout();
        $this->actingAs(User::factory()->create());
        // a user with no library is sent to choose one, never shown the sales page
        $this->get('/')->assertRedirect(route('library.choose'));
    }

    public function test_the_page_ships_its_screenshots_and_search_tags(): void
    {
        foreach (['dashboard', 'books', 'circulation', 'portal'] as $img) {
            $this->assertFileExists(public_path("marketing/{$img}.png"));
        }

        $this->get('/')->assertSee('<title>', false)->assertSee('name="description"', false)->assertSee('rel="canonical"', false)->assertSee('marketing/dashboard.png', false);
    }

    public function test_the_page_never_shows_library_data(): void
    {
        app(RegisterLibrary::class)(['name' => 'Secret Library', 'status' => 'active'], ['name' => 'Owner', 'email' => 'secret-owner@x.test']);

        $html = $this->get('/')->getContent();
        $this->assertStringNotContainsString('Secret Library', $html);
        $this->assertStringNotContainsString('secret-owner@x.test', $html);
    }

    // ===================== the sign-up / demo form =====================

    public function test_claiming_the_offer_saves_a_lead_and_tells_the_team(): void
    {
        $team = $this->admin();

        Livewire::test('lead-form')
            ->set('name', 'Asha Rao')->set('email', 'ASHA@Example.com ')->set('phone', '+91 98765-43210')->set('library_name', 'Sunrise Study Hub')
            ->set('city', 'Pune')->set('students', '51-150')->set('plan', (string) $this->starter->id)->set('message', 'We have 2 halls')
            ->call('submit')->assertHasNoErrors()->assertSet('done', true);

        $lead = Lead::first();
        $this->assertSame('trial', $lead->type);
        $this->assertSame('new', $lead->status);
        $this->assertSame('asha@example.com', $lead->email, 'emails are stored lower-case');
        $this->assertSame($this->starter->id, $lead->subscription_plan_id);
        $this->assertSame('Pune', $lead->city);
        $this->assertSame(1, $team->notifications()->where('data->kind', 'lead.new')->count());
    }

    public function test_booking_a_demo_keeps_the_preferred_time(): void
    {
        Livewire::test('lead-form')->call('setType', 'demo')
            ->set('name', 'Ravi')->set('email', 'ravi@example.com')->set('phone', '9876543210')->set('library_name', 'City Reading Room')
            ->set('preferred_date', today()->addDays(3)->toDateString())->set('preferred_time', 'evening')
            ->call('submit')->assertHasNoErrors();

        $lead = Lead::first();
        $this->assertSame('demo', $lead->type);
        $this->assertSame(today()->addDays(3)->toDateString(), $lead->preferred_date->toDateString());
        $this->assertSame('evening', $lead->preferred_time);
    }

    public function test_the_form_checks_its_input(): void
    {
        $form = fn () => Livewire::test('lead-form')->set('name', 'A')->set('email', 'a@b.com')->set('phone', '9876543210')->set('library_name', 'Lib');

        Livewire::test('lead-form')->call('submit')->assertHasErrors(['name', 'email', 'phone', 'library_name']);
        $form()->set('email', 'not-an-email')->call('submit')->assertHasErrors('email');
        $form()->set('phone', 'abc')->call('submit')->assertHasErrors('phone');
        $form()->set('phone', '12')->call('submit')->assertHasErrors('phone');
        $form()->set('students', '9999')->call('submit')->assertHasErrors('students');
        $form()->call('setType', 'demo')->set('preferred_date', today()->subDay()->toDateString())->call('submit')->assertHasErrors('preferred_date');
        $form()->call('setType', 'demo')->set('preferred_date', today()->addDays(90)->toDateString())->call('submit')->assertHasErrors('preferred_date');
        $form()->call('setType', 'demo')->set('preferred_time', 'midnight')->call('submit')->assertHasErrors('preferred_time');
        $form()->set('message', str_repeat('x', 1001))->call('submit')->assertHasErrors('message');
        $this->assertSame(0, Lead::count());
    }

    public function test_an_unknown_or_retired_plan_is_ignored(): void
    {
        $retired = SubscriptionPlan::where('name', 'Retired Plan')->first();

        Livewire::test('lead-form')->set('name', 'A')->set('email', 'a@b.com')->set('phone', '9876543210')->set('library_name', 'Lib')
            ->set('plan', (string) $retired->id)->call('submit')->assertHasNoErrors();

        $this->assertNull(Lead::first()->subscription_plan_id);
    }

    public function test_a_plan_link_preselects_that_plan(): void
    {
        Livewire::withQueryParams(['plan' => (string) $this->pro->id])->test('lead-form')->assertSet('plan', (string) $this->pro->id);
        Livewire::withQueryParams(['plan' => 'abc'])->test('lead-form')->assertSet('plan', '');
        Livewire::withQueryParams(['type' => 'demo'])->test('lead-form')->assertSet('type', 'demo');
    }

    public function test_bots_that_fill_the_hidden_field_leave_nothing_behind(): void
    {
        Livewire::test('lead-form')->set('name', 'Bot')->set('email', 'bot@example.com')->set('phone', '9876543210')->set('library_name', 'Spam')
            ->set('website', 'http://spam.example')->call('submit')->assertSet('done', true);

        $this->assertSame(0, Lead::count());
    }

    public function test_the_same_person_cannot_flood_the_inbox(): void
    {
        $send = fn () => Livewire::test('lead-form')->set('name', 'Asha')->set('email', 'asha@example.com')->set('phone', '9876543210')->set('library_name', 'Hub')->call('submit');

        $send()->assertSet('done', true);
        $again = $send();
        $this->assertSame(1, Lead::count(), 'a repeat within a day is not a second request');
        $this->assertStringContainsString('already have your request', $again->get('doneNote'));
    }

    public function test_requests_from_one_connection_are_rate_limited(): void
    {
        foreach (range(1, 5) as $i) {
            Livewire::test('lead-form')->set('name', 'P'.$i)->set('email', "p{$i}@example.com")->set('phone', '9876543210')->set('library_name', 'Lib '.$i)->call('submit')->assertHasNoErrors();
        }
        $this->assertSame(5, Lead::count());

        Livewire::test('lead-form')->set('name', 'P6')->set('email', 'p6@example.com')->set('phone', '9876543210')->set('library_name', 'Lib 6')->call('submit')->assertHasErrors('email');
        $this->assertSame(5, Lead::count());
    }

    public function test_the_same_email_may_ask_for_a_demo_after_the_offer(): void
    {
        $fill = fn ($c) => $c->set('name', 'Asha')->set('email', 'asha@example.com')->set('phone', '9876543210')->set('library_name', 'Hub');

        $fill(Livewire::test('lead-form'))->call('submit');
        $fill(Livewire::test('lead-form')->call('setType', 'demo'))->call('submit');

        $this->assertSame(['demo', 'trial'], Lead::orderBy('id', 'desc')->pluck('type')->all());
    }

    // ===================== the admin side =====================

    public function test_leads_page_is_for_platform_staff_who_manage_libraries(): void
    {
        $this->lead();

        $this->actingAs($this->admin())->get('/admin/leads')->assertOk()->assertSee('Sunrise Study Hub');
        $this->actingAs($this->admin(['plans.manage']))->get('/admin/leads')->assertForbidden();
        $this->actingAs(User::factory()->create())->get('/admin/leads')->assertForbidden();
        auth()->logout();
        $this->get('/admin/leads')->assertRedirect(route('login'));
    }

    public function test_the_menu_shows_how_many_new_leads_wait(): void
    {
        $this->lead();
        $this->lead(['email' => 'two@example.com', 'library_name' => 'Two']);
        $this->lead(['email' => 'three@example.com', 'library_name' => 'Three', 'status' => 'closed']);
        Lead::where('library_name', 'Three')->update(['status' => 'closed']);

        $this->actingAs($this->admin())->get('/admin')->assertOk()->assertSee('Leads');
        $this->assertSame(2, Lead::where('status', 'new')->count());
    }

    public function test_staff_work_through_a_lead(): void
    {
        $lead = $this->lead();
        $this->actingAs($this->admin());

        $page = Livewire::test('pages::admin.leads.index');
        $page->call('mark', $lead->id, 'contacted');
        $this->assertSame('contacted', $lead->fresh()->status);
        $this->assertNotNull($lead->fresh()->handled_by);

        $page->set('status', 'contacted')->assertSee('Sunrise Study Hub');
        $page->call('mark', $lead->id, 'closed');
        $page->call('mark', $lead->id, 'new');
        $this->assertSame('new', $lead->fresh()->status);

        $page->call('openNote', $lead->id)->set('note', 'Called, wants a demo Friday')->call('saveNote');
        $this->assertSame('Called, wants a demo Friday', $lead->fresh()->notes);

        $page->call('mark', $lead->id, 'weird')->assertStatus(422);
    }

    public function test_leads_can_be_filtered_and_searched_and_html_is_escaped(): void
    {
        $this->lead(['library_name' => '<script>alert(1)</script> Hub']);
        $this->lead(['email' => 'demo@example.com', 'type' => 'demo', 'library_name' => 'Other Place', 'city' => 'Nagpur']);
        $this->actingAs($this->admin());

        $page = Livewire::test('pages::admin.leads.index')->set('status', 'all');
        $html = $page->html();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);

        $page->set('type', 'demo')->assertSee('Other Place')->assertDontSee('Hub');
        $page->set('type', '')->set('search', 'nagpur')->assertSee('Other Place');
    }

    public function test_a_library_is_created_from_a_free_months_request_with_the_free_period_started(): void
    {
        $lead = $this->lead(['subscription_plan_id' => $this->starter->id]);
        $this->actingAs($this->admin());

        $page = Livewire::withQueryParams(['lead' => (string) $lead->id])->test('pages::admin.libraries.create');
        $page->assertSet('name', 'Sunrise Study Hub')->assertSet('owner_email', 'asha@example.com')->assertSet('free_days', '60')
            ->assertSet('plan_id', (string) $this->starter->id)->assertSet('status', 'active');
        $page->call('save')->assertHasNoErrors();

        $library = \App\Models\Library::where('name', 'Sunrise Study Hub')->first();
        $sub = Subscription::currentFor($library->id);
        $this->assertTrue($sub->is_complimentary);
        $this->assertSame('active', $sub->status);
        $this->assertSame(today()->addDays(59)->toDateString(), $sub->expires_on->toDateString());

        $lead->refresh();
        $this->assertSame('converted', $lead->status);
        $this->assertSame($library->id, $lead->library_id);
        $this->get('/admin/leads?status=converted')->assertSee('Open library');
    }

    public function test_a_demo_request_creates_a_library_without_free_months(): void
    {
        $lead = $this->lead(['type' => 'demo']);
        $this->actingAs($this->admin());

        Livewire::withQueryParams(['lead' => (string) $lead->id])->test('pages::admin.libraries.create')->assertSet('free_days', '0');
    }

    public function test_a_free_period_needs_a_plan(): void
    {
        $this->actingAs($this->admin());

        Livewire::test('pages::admin.libraries.create')->set('name', 'X')->set('owner_name', 'O')->set('owner_email', 'o@x.test')->set('owner_phone', '9000000000')->set('free_days', '30')->call('save')->assertHasErrors('plan_id');
        $this->assertSame(0, \App\Models\Library::count());
    }

    public function test_a_converted_lead_cannot_be_changed_or_reused(): void
    {
        $lead = $this->lead(['status' => 'converted']);
        Lead::whereKey($lead->id)->update(['status' => 'converted']);
        $this->actingAs($this->admin());

        Livewire::test('pages::admin.leads.index')->call('mark', $lead->id, 'closed')->assertDispatched('notify');
        $this->assertSame('converted', $lead->fresh()->status);

        $page = Livewire::withQueryParams(['lead' => (string) $lead->id])->test('pages::admin.libraries.create');
        $page->assertSet('name', '');   // a lead that already became a library does not prefill again
    }

    public function test_the_offer_is_set_in_platform_settings(): void
    {
        $this->actingAs($this->admin());

        Livewire::test('pages::admin.settings.index')->set('offer_free_days', '400')->call('saveSubscription')->assertHasErrors('offer_free_days');
        Livewire::test('pages::admin.settings.index')->set('offer_free_days', '30')->set('landing_enabled', false)->call('saveSubscription')->assertHasNoErrors();

        $this->assertSame(30, BillingSettings::get('offer_free_days'));
        $this->assertFalse(BillingSettings::get('landing_enabled'));
    }
    // ===================== direct contact =====================

    public function test_buyers_can_contact_the_team_directly_by_whatsapp_and_email(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('Want this for your library?', $html);
        $this->assertStringContainsString('+91 82099 90511', $html);
        $this->assertStringContainsString('https://wa.me/918209990511', $html);
        $this->assertStringContainsString('mailto:nirbhaydhaked@gmail.com', $html);
        $this->assertStringContainsString('Chat with us on WhatsApp', $html, 'the floating chat button');
        $this->assertStringContainsString('Developed by', $html);
        $this->assertStringContainsString('laravelexpert.in', $html);
    }

    public function test_the_contact_details_follow_platform_settings(): void
    {
        BillingSettings::set(['support_phone' => '9876543210', 'support_email' => 'sales@example.com']);

        $html = $this->get('/')->getContent();
        $this->assertStringContainsString('https://wa.me/919876543210', $html, 'a 10-digit number gets the country code');
        $this->assertStringContainsString('mailto:sales@example.com', $html);

        BillingSettings::set(['support_phone' => '', 'support_email' => '']);
        $html = $this->get('/')->getContent();
        $this->assertStringNotContainsString('wa.me', $html);
        $this->assertStringNotContainsString('Want this for your library?', $html);
        $this->assertStringNotContainsString('Chat with us on WhatsApp', $html);
    }

    public function test_the_whatsapp_link_is_built_safely(): void
    {
        $this->assertSame('https://wa.me/918209990511?text='.rawurlencode('Hi, I would like to know more about A&B for my library.'), \App\Http\Controllers\HomeController::whatsappLink('+91 82099-90511', 'A&B'));
        $this->assertNull(\App\Http\Controllers\HomeController::whatsappLink('', 'X'));
        $this->assertNull(\App\Http\Controllers\HomeController::whatsappLink('abc', 'X'));
        $this->assertNull(\App\Http\Controllers\HomeController::whatsappLink('12345', 'X'));
    }
}
