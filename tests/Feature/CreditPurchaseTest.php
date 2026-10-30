<?php

namespace Tests\Feature;

use App\Actions\Billing\ReviewCreditPurchase;
use App\Actions\RegisterLibrary;
use App\Models\CreditPurchase;
use App\Models\Library;
use App\Models\User;
use App\Support\BillingSettings;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CreditPurchaseTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $owner;
    protected Library $library;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Storage::fake('local');
        $this->admin = User::factory()->create();
        $this->admin->forceFill(['is_super_admin' => true])->save();
        $this->admin = $this->admin->fresh();

        $this->actingAs($this->admin);
        $this->library = app(RegisterLibrary::class)(['name' => 'Alpha', 'status' => 'active'], ['name' => 'Owner', 'email' => 'owner@alpha.test']);
        $this->owner = User::where('email', 'owner@alpha.test')->first();
        Tenancy::set($this->library);
    }

    protected function tearDown(): void
    {
        BillingSettings::flush();
        Tenancy::flush();
        parent::tearDown();
    }

    protected function creditCheckout(string $channel, int $credits)
    {
        return Livewire::withQueryParams(['channel' => $channel, 'credits' => $credits])->test('pages::app.subscription.credits-checkout');
    }

    public function test_owner_buys_credits_through_the_checkout_and_platform_approval_adds_them(): void
    {
        $this->actingAs($this->owner);
        $this->get("/app/{$this->library->slug}/subscription/plans?tab=sms")->assertOk()->assertSee('How many SMS credits')->assertSee('Pay ₹210');

        Livewire::test('pages::app.subscription.plans')->call('buy', 1000, 'sms')
            ->assertRedirect(route('app.subscription.credits-checkout', ['library' => $this->library->slug, 'channel' => 'sms', 'credits' => 1000]));

        $this->get("/app/{$this->library->slug}/subscription/credits-checkout?channel=sms&credits=1000")->assertOk()
            ->assertSee('SMS Credits | 1,000 credits | ₹210')->assertSee('Total amount')->assertSee('Pay by Cash')->assertSee('Coming soon');

        $this->creditCheckout('sms', 1000)->call('proceed')->assertSet('showPay', true)->assertNoRedirect()
            ->set('reference', 'UTR1')->set('note', 'Paid by UPI')->set('proofs', [\Illuminate\Http\UploadedFile::fake()->image('p.png')])->call('savePay')->assertHasNoErrors();

        $p = CreditPurchase::firstOrFail();
        $this->assertSame(['sms', 1000, '210.00', 'pending'], [$p->channel, $p->credits, $p->amount, $p->status]);

        $this->actingAs($this->admin);
        app(ReviewCreditPurchase::class)->approve($p);

        $this->assertSame(1000, $this->library->fresh()->sms_credits);
        $this->assertSame(0, $this->library->fresh()->whatsapp_credits);
        $this->assertSame('approved', $p->fresh()->status);
    }

    public function test_online_payment_for_credits_only_says_coming_soon(): void
    {
        $this->actingAs($this->owner);
        $this->creditCheckout('whatsapp', 1000)->set('method', 'online')->call('proceed')->assertSet('onlineNotice', true)->assertSet('showPay', false);

        $this->assertSame(0, CreditPurchase::count());
    }

    public function test_only_configured_packs_can_be_bought_and_only_one_pending_at_a_time(): void
    {
        $this->actingAs($this->owner);
        Livewire::test('pages::app.subscription.plans')->call('buy', 123, 'whatsapp')->assertNotFound();
        $this->get("/app/{$this->library->slug}/subscription/credits-checkout?channel=whatsapp&credits=123")->assertNotFound();
        $this->get("/app/{$this->library->slug}/subscription/credits-checkout?channel=email&credits=1000")->assertNotFound();

        $this->creditCheckout('whatsapp', 2000)->call('proceed')->set('reference', 'A')->set('proofs', [\Illuminate\Http\UploadedFile::fake()->image('p.png')])->call('savePay')->assertHasNoErrors();
        $this->creditCheckout('whatsapp', 1000)->call('proceed')->set('reference', 'B')->set('proofs', [\Illuminate\Http\UploadedFile::fake()->image('p.png')])->call('savePay')->assertHasErrors('reference');

        $this->assertSame(1, CreditPurchase::count());
        $this->assertSame('200.00', CreditPurchase::first()->amount);
    }
    public function test_rejection_needs_a_reason_and_adds_nothing(): void
    {
        $p = CreditPurchase::create(['library_id' => $this->library->id, 'channel' => 'sms', 'credits' => 1000, 'rate' => '0.21', 'amount' => '210', 'method' => 'upi', 'reference' => 'X', 'paid_on' => today()]);

        $this->expectException(\App\Exceptions\BusinessRuleException::class);
        try {
            app(ReviewCreditPurchase::class)->reject($p, '');
        } finally {
            $this->assertSame(0, $this->library->fresh()->sms_credits);
        }
    }

    public function test_cannot_approve_twice(): void
    {
        $p = CreditPurchase::create(['library_id' => $this->library->id, 'channel' => 'sms', 'credits' => 1000, 'rate' => '0.21', 'amount' => '210', 'method' => 'upi', 'reference' => 'X', 'paid_on' => today()]);
        app(ReviewCreditPurchase::class)->approve($p);

        try {
            app(ReviewCreditPurchase::class)->approve($p);
            $this->fail('second approval should be refused');
        } catch (\App\Exceptions\BusinessRuleException) {
        }
        $this->assertSame(1000, $this->library->fresh()->sms_credits);
    }

    public function test_admin_page_saves_rates_that_the_library_page_then_shows(): void
    {
        Livewire::test('pages::admin.credits.index')
            ->set('rates.sms.rate', '0.30')->set('rates.sms.packs', '500, 1500')->call('saveRates')->assertHasNoErrors();

        $this->actingAs($this->owner);
        $this->get("/app/{$this->library->slug}/subscription/plans?tab=sms")->assertSee('₹0.30/SMS')->assertSee('Pay ₹150')->assertSee('Pay ₹450');
    }

    public function test_a_credit_payment_needs_proof_and_keeps_the_note_and_files_private(): void
    {
        $this->actingAs($this->owner);

        $this->creditCheckout('sms', 1000)->call('proceed')->set('reference', 'NOPROOF')->call('savePay')->assertHasErrors('proofs');
        $this->assertSame(0, CreditPurchase::count());

        $this->creditCheckout('sms', 1000)->call('proceed')->set('reference', 'WITHPROOF')->set('note', 'Paid at the desk')
            ->set('proofs', [\Illuminate\Http\UploadedFile::fake()->image('receipt.png')])->call('savePay')->assertHasNoErrors();

        $p = CreditPurchase::with('proofs')->firstOrFail();
        $this->assertSame('Paid at the desk', $p->note);
        $this->assertCount(1, $p->proofs);
        \Illuminate\Support\Facades\Storage::disk('local')->assertExists($p->proofs[0]->path);

        // the owning library can open it ...
        $this->get(route('app.subscription.credit-proof', ['library' => $this->library->slug, 'proof' => $p->proofs[0]->id]))->assertOk();
        // ... the platform reviewer can, and sees the note on the review box
        $this->actingAs($this->admin);
        $this->get(route('admin.credit-proofs.show', $p->proofs[0]))->assertOk();
        Livewire::test('pages::admin.credits.index')->call('review', $p->id)->assertSee('Paid at the desk')->assertSee('receipt.png');
    }

    public function test_another_librarys_credit_proof_is_not_reachable(): void
    {
        $this->actingAs($this->owner);
        $this->creditCheckout('sms', 1000)->call('proceed')->set('reference', 'MINE')->set('proofs', [\Illuminate\Http\UploadedFile::fake()->image('mine.png')])->call('savePay');
        $proof = CreditPurchase::firstOrFail()->proofs()->firstOrFail();

        $other = app(\App\Actions\RegisterLibrary::class)(['name' => 'Beta', 'status' => 'active'], ['name' => 'Other', 'email' => 'other@beta.test']);
        $otherOwner = User::where('email', 'other@beta.test')->first();
        Tenancy::set($other);
        $this->actingAs($otherOwner);
        $this->get(route('app.subscription.credit-proof', ['library' => $other->slug, 'proof' => $proof->id]))->assertNotFound();
    }}
