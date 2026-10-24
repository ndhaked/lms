<?php

namespace Tests\Feature;

use App\Actions\Billing\ApproveSubscriptionPayment;
use App\Actions\Billing\CreateSubscription;
use App\Actions\Billing\GrantFreePeriod;
use App\Actions\Billing\SubmitSubscriptionPayment;
use App\Actions\RegisterLibrary;
use App\Exceptions\BusinessRuleException;
use App\Models\Library;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\Entitlements;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class FreeAccessTest extends TestCase
{
    use RefreshDatabase;

    protected Library $library;
    protected User $owner;
    protected User $root;
    protected SubscriptionPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['billing.require_subscription' => true]);

        $this->root = User::factory()->create();
        $this->root->forceFill(['is_super_admin' => true])->save();
        $this->root = $this->root->fresh();
        $this->actingAs($this->root);

        $this->library = app(RegisterLibrary::class)(['name' => 'Alpha', 'status' => 'active'], ['name' => 'Owner', 'email' => 'o@alpha.test']);
        $this->owner = User::where('email', 'o@alpha.test')->first();
        $this->plan = SubscriptionPlan::create(['name' => 'Std', 'price_monthly' => 1000, 'max_students' => 5]);
    }

    protected function tearDown(): void
    {
        \App\Support\BillingSettings::flush();
        Tenancy::flush();
        Entitlements::flush();
        parent::tearDown();
    }

    public function test_library_without_a_subscription_is_restricted_to_the_billing_page(): void
    {
        $this->actingAs($this->owner);

        $this->get("/app/{$this->library->slug}")->assertRedirect(route('app.subscription.index', $this->library->slug));
        $this->get("/app/{$this->library->slug}/students")->assertRedirect();
        $this->get("/app/{$this->library->slug}/subscription")->assertOk()->assertSee('no active plan');
    }

    public function test_a_library_without_a_plan_can_request_access(): void
    {
        $this->actingAs($this->owner);
        $this->get("/app/{$this->library->slug}/subscription")->assertSee('Request access');

        Livewire::test('pages::app.subscription.index')->call('openSupport')->set('subject', 'Need access')->set('supportMessage', 'Please enable us')->call('sendSupport')->assertHasNoErrors();

        $this->assertSame(1, \App\Models\SupportRequest::where('library_id', $this->library->id)->count());
        $this->assertSame(1, $this->root->notifications()->where('data->kind', 'support.request')->count());
    }

    public function test_the_requirement_can_be_switched_off(): void
    {
        config(['billing.require_subscription' => false]);
        $this->actingAs($this->owner)->get("/app/{$this->library->slug}")->assertOk();
    }

    public function test_free_presets_give_exact_periods_and_unlock_the_library(): void
    {
        foreach ([15, 30, 60] as $days) {
            $library = app(RegisterLibrary::class)(['name' => "L{$days}", 'status' => 'active'], ['name' => 'O', 'email' => "o{$days}@x.test"]);
            $s = app(GrantFreePeriod::class)($library, $this->plan, $days, 'Launch offer');

            $this->assertTrue($s->is_complimentary);
            $this->assertSame('active', $s->status);
            $this->assertSame(today()->addDays($days - 1)->toDateString(), $s->expires_on->toDateString());
            $this->assertSame('0.00', (string) $s->price);
            $this->assertSame('full', Entitlements::state($library->id));
        }

        app(GrantFreePeriod::class)($this->library, $this->plan, 15, 'Trial for owner');
        $this->actingAs($this->owner)->get("/app/{$this->library->slug}")->assertOk();
        $this->assertSame(1, $this->library->fresh()->subscriptions()->count());
    }

    public function test_free_access_applies_the_plan_limits(): void
    {
        app(GrantFreePeriod::class)($this->library, $this->plan, 30, 'Offer');
        Tenancy::set($this->library);
        Entitlements::flush();

        $this->assertSame(5, Entitlements::subscription()->limit('students'));
    }

    public function test_free_access_ends_and_the_library_is_paused_again(): void
    {
        $s = app(GrantFreePeriod::class)($this->library, $this->plan, 15, 'Offer');

        $this->assertSame('full', $s->accessState());
        $this->assertSame('restricted', $s->accessState(today()->addDays(15 + 30)));
    }

    public function test_extending_continues_from_the_end_of_the_free_period(): void
    {
        $first = app(GrantFreePeriod::class)($this->library, $this->plan, 15, 'Offer');
        $end = $first->expires_on->copy();

        $again = app(GrantFreePeriod::class)($this->library, $this->plan, 30, 'More');

        $this->assertSame($first->id, $again->id);
        $this->assertSame($end->addDays(30)->toDateString(), $again->expires_on->toDateString());
    }

    public function test_an_expired_free_period_can_be_granted_again_from_today(): void
    {
        $s = app(GrantFreePeriod::class)($this->library, $this->plan, 15, 'Offer');
        $s->forceFill(['expires_on' => today()->subDays(20)])->save();

        $again = app(GrantFreePeriod::class)($this->library, $this->plan, 30, 'Second chance');

        $this->assertSame(today()->addDays(29)->toDateString(), $again->expires_on->toDateString());
    }

    public function test_a_running_paid_subscription_and_invalid_input_are_refused(): void
    {
        app(CreateSubscription::class)($this->library, $this->plan, 'monthly', false);
        $this->actingAs($this->owner);
        Tenancy::set($this->library);
        $pay = app(SubmitSubscriptionPayment::class)($this->library, $this->plan, 'monthly', 'UTR1', 1000, 'upi', today(), null, [UploadedFile::fake()->image('p.png')]);
        $this->actingAs($this->root);
        app(ApproveSubscriptionPayment::class)($pay);

        try {
            app(GrantFreePeriod::class)($this->library, $this->plan, 15, 'x');
            $this->fail('a running paid subscription must be refused');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('paid subscription', $e->getMessage());
        }

        $other = app(RegisterLibrary::class)(['name' => 'Z', 'status' => 'active'], ['name' => 'Z', 'email' => 'z@z.test']);
        foreach ([[0, 'r'], [731, 'r'], [10, ' ']] as [$days, $reason]) {
            try {
                app(GrantFreePeriod::class)($other, $this->plan, $days, $reason);
                $this->fail('invalid input accepted');
            } catch (BusinessRuleException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(0, $other->subscriptions()->count());
    }

    public function test_a_payment_turns_free_access_into_a_paid_plan(): void
    {
        $s = app(GrantFreePeriod::class)($this->library, $this->plan, 15, 'Offer');
        $this->actingAs($this->owner);
        Tenancy::set($this->library);
        $pay = app(SubmitSubscriptionPayment::class)($this->library, $this->plan, 'monthly', 'UTR2', 1000, 'upi', today(), null, [UploadedFile::fake()->image('p.png')]);
        $this->actingAs($this->root);
        app(ApproveSubscriptionPayment::class)($pay);

        $s->refresh();
        $this->assertFalse($s->is_complimentary);
        $this->assertSame('1000.00', (string) $s->price);
    }

    public function test_only_platform_staff_with_the_permission_can_grant(): void
    {
        $limited = User::factory()->create();
        $limited->forceFill(['is_super_admin' => true, 'platform_permissions' => ['reports.view']])->save();
        $this->actingAs($limited->fresh());

        $this->expectException(HttpException::class);
        app(GrantFreePeriod::class)($this->library, $this->plan, 15, 'x');
    }

    public function test_grant_from_the_library_page(): void
    {
        Livewire::test('pages::admin.libraries.show', ['library' => (string) $this->library->id])
            ->call('openFree')->set('freeDays', 'custom')->set('freeCustom', '45')->set('freeReason', 'Partner')->call('grantFree')->assertHasNoErrors();

        $s = Subscription::currentFor($this->library->id);
        $this->assertSame(today()->addDays(44)->toDateString(), $s->expires_on->toDateString());

        Livewire::test('pages::admin.libraries.show', ['library' => (string) $this->library->id])
            ->call('openFree')->set('freeDays', 'custom')->set('freeCustom', '')->set('freeReason', 'x')->call('grantFree')->assertHasErrors('freeCustom');
    }
}
