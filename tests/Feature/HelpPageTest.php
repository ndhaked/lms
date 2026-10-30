<?php

namespace Tests\Feature;

use App\Actions\RegisterLibrary;
use App\Models\Library;
use App\Models\SupportRequest;
use App\Models\User;
use App\Support\BillingSettings;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class HelpPageTest extends TestCase
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
        $this->lib = app(RegisterLibrary::class)(['name' => 'Alpha', 'status' => 'active'], ['name' => 'Owner', 'email' => 'o@help.test']);
        $this->owner = User::where('email', 'o@help.test')->first();
        Tenancy::set($this->lib);
    }

    protected function tearDown(): void
    {
        BillingSettings::flush();
        Tenancy::flush();
        parent::tearDown();
    }

    public function test_help_page_shows_questions_in_both_languages_and_hides_video_buttons_without_links(): void
    {
        // a library with no plan is restricted, but the help page must stay open
        $this->actingAs($this->owner);
        $this->get("/app/{$this->lib->slug}/help")->assertOk()
            ->assertSee('How to register a new student?')->assertSee('नया स्टूडेंट कैसे रजिस्टर करें?')->assertSee('Support center')
            ->assertDontSee('Watch video');
    }

    public function test_a_platform_video_link_turns_on_the_watch_video_button(): void
    {
        $i = array_search('student', array_column(\App\Support\HelpContent::defaults(), 'key'), true);

        Livewire::test('pages::admin.settings.index')->set('tab', 'help')
            ->set("faqRows.{$i}.video", 'https://example.com/add-student')->set('help_full_video', 'https://example.com/full')
            ->call('saveHelp')->assertHasNoErrors();

        BillingSettings::flush();
        $this->actingAs($this->owner);
        $this->get("/app/{$this->lib->slug}/help")->assertOk()->assertSee('https://example.com/add-student', false)->assertSee('https://example.com/full', false);
    }

    public function test_bad_video_links_are_refused(): void
    {
        Livewire::test('pages::admin.settings.index')->set('tab', 'help')
            ->set('faqRows.0.video', 'http://insecure.example.com')->call('saveHelp')->assertHasErrors('faqRows.0.video');
        $this->assertSame([], BillingSettings::get('help_faqs'));
    }

    public function test_super_admin_can_add_edit_reorder_and_reset_questions(): void
    {
        $page = Livewire::test('pages::admin.settings.index')->set('tab', 'help')->call('addFaq');
        $last = count(\App\Support\HelpContent::defaults());
        $page->set("faqRows.{$last}.q_en", 'How to clean the desk?')->set("faqRows.{$last}.a_en", 'Wipe it daily.')->set("faqRows.{$last}.q_hi", 'डेस्क कैसे साफ करें?')
            ->call('moveFaq', $last, -1)->call('saveHelp')->assertHasNoErrors();

        BillingSettings::flush();
        $this->assertSame('How to clean the desk?', \App\Support\HelpContent::faqs()[$last - 1]['en'][0]);

        $this->actingAs($this->owner);
        $this->get("/app/{$this->lib->slug}/help")->assertOk()->assertSee('How to clean the desk?')->assertSee('डेस्क कैसे साफ करें?');

        $this->actingAs($this->admin);
        Livewire::test('pages::admin.settings.index')->set('tab', 'help')->call('resetFaqs');
        BillingSettings::flush();
        $this->assertSame([], BillingSettings::get('help_faqs'));
        $this->assertSame('How to add staff and choose what they can do?', \App\Support\HelpContent::faqs()[0]['en'][0]);
    }

    public function test_a_question_needs_an_english_title_and_answer(): void
    {
        Livewire::test('pages::admin.settings.index')->set('tab', 'help')->call('addFaq')->call('saveHelp')->assertHasErrors();
    }
    public function test_a_message_from_the_help_page_reaches_support(): void
    {
        $this->actingAs($this->owner);

        Livewire::test('pages::app.help.index')->set('subject', 'Printer')->set('body', 'Labels are cut off')->call('send')->assertHasNoErrors();

        $this->assertSame('Printer', SupportRequest::first()->subject);
    }
}
