<?php

namespace Tests\Feature;

use App\Actions\RegisterLibrary;
use App\Models\Library;
use App\Models\User;
use App\Models\WhatsappTemplate;
use App\Support\BillingSettings;
use App\Support\LibraryRoles;
use App\Support\MessageTemplates;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MessageTemplatesTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Library $a;
    protected Library $b;
    protected User $ownerA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        $this->admin->forceFill(['is_super_admin' => true])->save();
        $this->admin = $this->admin->fresh();
        $this->actingAs($this->admin);

        $this->a = app(RegisterLibrary::class)(['name' => 'Alpha', 'status' => 'active'], ['name' => 'Owner A', 'email' => 'a@tpl.test']);
        $this->b = app(RegisterLibrary::class)(['name' => 'Beta', 'status' => 'active'], ['name' => 'Owner B', 'email' => 'b@tpl.test']);
        $this->ownerA = User::where('email', 'a@tpl.test')->first();
        Tenancy::set($this->a);
    }

    protected function tearDown(): void
    {
        BillingSettings::flush();
        Tenancy::flush();
        parent::tearDown();
    }

    public function test_a_new_library_starts_with_the_default_whatsapp_templates(): void
    {
        $this->assertSame(10, WhatsappTemplate::count());
        $this->assertSame(['Membership', 'Due Payment Reminder', 'Renewal', 'Birthday', 'Offers', 'Holiday', 'Anniversary', 'Renewed', 'Payment Received', 'Inquiry'], WhatsappTemplate::orderBy('sort')->pluck('name')->all());

        MessageTemplates::seedWhatsapp($this->a);          // running it again adds nothing
        $this->assertSame(10, WhatsappTemplate::count());
    }

    public function test_the_page_lists_system_sms_templates_as_read_only_and_the_librarys_whatsapp_ones(): void
    {
        $this->actingAs($this->ownerA);

        $this->get("/app/{$this->a->slug}/templates")->assertOk()->assertSee('SMS Templates')->assertSee('Joining')->assertSee('Birthday')->assertSee('Not Editable')->assertSee('13');
        $this->get("/app/{$this->a->slug}/templates?channel=whatsapp")->assertOk()->assertSee('Add WhatsApp')->assertSee('Due Payment Reminder')->assertSee('Delete');
        $this->assertCount(13, MessageTemplates::sms());
    }

    public function test_a_library_edits_adds_and_deletes_its_own_whatsapp_templates_only(): void
    {
        $this->actingAs($this->ownerA);
        $page = Livewire::withQueryParams(['channel' => 'whatsapp'])->test('pages::app.templates.index');

        $id = WhatsappTemplate::where('name', 'Offers')->value('id');
        $page->set("rows.{$id}.message", 'Our own offer text')->call('save', $id)->assertHasNoErrors();
        $this->assertSame('Our own offer text', WhatsappTemplate::find($id)->message);

        $page->call('add');
        $this->assertSame(11, WhatsappTemplate::count());

        $page->call('delete', $id);
        $this->assertSame(10, WhatsappTemplate::count());

        Tenancy::set($this->b);
        $this->assertSame(10, WhatsappTemplate::count());                    // Beta is untouched
        $this->assertNotContains('Our own offer text', WhatsappTemplate::pluck('message')->all());
    }

    public function test_a_template_needs_a_name_and_a_message(): void
    {
        $this->actingAs($this->ownerA);
        $id = WhatsappTemplate::first()->id;

        Livewire::withQueryParams(['channel' => 'whatsapp'])->test('pages::app.templates.index')
            ->set("rows.{$id}.message", '')->call('save', $id)->assertHasErrors("rows.{$id}.message");
    }

    public function test_people_without_settings_permission_can_read_but_not_change(): void
    {
        $reader = User::factory()->create();
        $this->a->users()->attach($reader->id, ['is_active' => true]);
        Tenancy::run($this->a, function () use ($reader) {
            $role = \Spatie\Permission\Models\Role::where('name', LibraryRoles::MANAGER)->where('library_id', $this->a->id)->firstOrFail();
            $role->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('billing.view', 'web'));
            $reader->assignRole($role);
        });
        $this->actingAs($reader->fresh());

        $id = WhatsappTemplate::first()->id;
        $this->get("/app/{$this->a->slug}/templates?channel=whatsapp")->assertOk()->assertDontSee('Add WhatsApp');
        Livewire::withQueryParams(['channel' => 'whatsapp'])->test('pages::app.templates.index')->call('save', $id)->assertForbidden();
    }

    public function test_super_admin_manages_the_sms_list_and_the_starter_set_for_new_libraries(): void
    {
        $page = Livewire::test('pages::admin.settings.index')->set('tab', 'templates');
        $page->set('smsRows.0.message', 'Welcome {name} to {library}, from the platform.')
            ->call('addTemplate', 'wa')->set('waRows.10.name', 'Exam wishes')->set('waRows.10.message', 'Best of luck {name}!')
            ->call('saveTemplates')->assertHasNoErrors();
        BillingSettings::flush();

        $this->assertSame('Welcome {name} to {library}, from the platform.', MessageTemplates::sms()[0]['message']);

        $c = app(RegisterLibrary::class)(['name' => 'Gamma', 'status' => 'active'], ['name' => 'Owner C', 'email' => 'c@tpl.test']);
        Tenancy::set($c);
        $this->assertSame(11, WhatsappTemplate::count());
        $this->assertContains('Exam wishes', WhatsappTemplate::pluck('name')->all());

        Tenancy::set($this->a);
        $this->assertSame(10, WhatsappTemplate::count(), 'libraries that already exist keep their own set');
    }

    public function test_blank_template_rows_are_refused_and_reset_restores_the_built_in_sets(): void
    {
        Livewire::test('pages::admin.settings.index')->set('tab', 'templates')->call('addTemplate', 'sms')->call('saveTemplates')->assertHasErrors();

        Livewire::test('pages::admin.settings.index')->set('tab', 'templates')->set('smsRows', [['name' => 'Only one', 'message' => 'x']])->call('saveTemplates');
        BillingSettings::flush();
        $this->assertCount(1, MessageTemplates::sms());

        Livewire::test('pages::admin.settings.index')->set('tab', 'templates')->call('resetTemplates');
        BillingSettings::flush();
        $this->assertCount(13, MessageTemplates::sms());
    }

    public function test_placeholders_are_filled_in(): void
    {
        $this->assertSame('Hi Asha, pay ₹500 to Alpha.', MessageTemplates::fill('Hi {name}, pay {amount} to {library}.', ['name' => 'Asha', 'amount' => '₹500', '{library}' => 'Alpha']));
        $this->assertSame('Due {unknown}', MessageTemplates::fill('Due {unknown}', ['name' => 'x']));
    }
}
