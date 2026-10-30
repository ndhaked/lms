<?php

use App\Http\Controllers\BookCoverController;
use App\Http\Controllers\CirculationReceiptController;
use App\Http\Controllers\EbookFileController;
use App\Http\Controllers\ExpenseAttachmentController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PaymentSubmissionProofController;
use App\Http\Controllers\PlatformReportExportController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\ReportExportController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\StockTakeExportController;
use App\Http\Controllers\SubscriptionInvoiceController;
use App\Http\Controllers\SubscriptionProofController;
use Illuminate\Support\Facades\Route;

// The public website for visitors; signed-in people are sent to their workspace.
Route::get('/', HomeController::class)->name('home');
Route::get('/workspace', [SessionController::class, 'home'])->middleware('auth')->name('workspace');

/*
|--------------------------------------------------------------------------
| Guests
|--------------------------------------------------------------------------
*/
Route::middleware('guest')->group(function () {
    Route::livewire('/login', 'pages::auth.login')->name('login');
    Route::livewire('/forgot-password', 'pages::auth.forgot-password')->name('password.request');
    Route::livewire('/reset-password/{token}', 'pages::auth.reset-password')->name('password.reset');
});

/*
|--------------------------------------------------------------------------
| Signed-in users
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function () {
    Route::post('/logout', [SessionController::class, 'logout'])->name('logout');
    Route::livewire('/choose-library', 'pages::auth.choose-library')->name('library.choose');
});

/*
|--------------------------------------------------------------------------
| Platform (Super Admin). Each area also needs its own platform permission.
|--------------------------------------------------------------------------
*/
// Razorpay calls this by itself: no login, no CSRF token; it is trusted only with a valid signature (see the controller).
// Swagger page for the mobile API (developers): try every endpoint with a token from /api/v1/auth/login.
Route::get('/api/docs', [\App\Http\Controllers\Api\DocsController::class, 'ui'])->name('api.docs');
Route::get('/api/docs/openapi.json', [\App\Http\Controllers\Api\DocsController::class, 'spec'])->name('api.docs.spec');

// Attendance machines (no login): ZKTeco / eSSL / Realtime push protocol, and a JSON endpoint using a per-device token.
Route::match(['get', 'post'], '/iclock/cdata', [\App\Http\Controllers\BiometricController::class, 'cdata'])->middleware('throttle:600,1');
Route::get('/iclock/getrequest', [\App\Http\Controllers\BiometricController::class, 'getRequest'])->middleware('throttle:600,1');
Route::post('/iclock/devicecmd', [\App\Http\Controllers\BiometricController::class, 'deviceCommand'])->middleware('throttle:600,1');
Route::post('/api/biometric/punches', [\App\Http\Controllers\BiometricController::class, 'punches'])->middleware('throttle:600,1')->name('biometric.punches');
Route::post('/webhooks/razorpay', \App\Http\Controllers\RazorpayWebhookController::class)->middleware('throttle:240,1')->name('webhooks.razorpay');

Route::middleware(['auth', 'super-admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::livewire('/', 'pages::admin.dashboard')->name('dashboard');
    Route::livewire('/profile', 'pages::account.profile')->name('profile.edit');

    Route::middleware('platform:libraries.manage')->group(function () {
        Route::livewire('/leads', 'pages::admin.leads.index')->name('leads.index');
        Route::livewire('/libraries', 'pages::admin.libraries.index')->name('libraries.index');
        Route::livewire('/libraries/create', 'pages::admin.libraries.create')->name('libraries.create');
        Route::livewire('/libraries/{library}', 'pages::admin.libraries.show')->name('libraries.show');
        Route::livewire('/libraries/{library}/edit', 'pages::admin.libraries.edit')->name('libraries.edit');
    });

    Route::middleware('platform:plans.manage')->group(function () {
        Route::livewire('/plans', 'pages::admin.plans.index')->name('plans.index');
    });

    Route::middleware('platform:subscriptions.manage')->group(function () {
        Route::livewire('/subscriptions', 'pages::admin.subscriptions.index')->name('subscriptions.index');
        Route::livewire('/subscriptions/{subscription}', 'pages::admin.subscriptions.show')->name('subscriptions.show');
    });

    Route::middleware('platform:payments.approve')->group(function () {
        Route::livewire('/payment-approvals', 'pages::admin.payment-approvals.index')->name('payment-approvals.index');
        Route::livewire('/credits', 'pages::admin.credits.index')->name('credits.index');
        Route::livewire('/message-logs', 'pages::admin.message-logs.index')->name('message-logs.index');
        Route::livewire('/online-payments', 'pages::admin.online-payments.index')->name('online-payments.index');
    });

    // Proofs and invoices: open to anyone who can handle payments or subscriptions.
    Route::get('/subscription-proofs/{proof}', [SubscriptionProofController::class, 'platform'])->name('subscription-proofs.show');
    Route::get('/credit-proofs/{proof}', [\App\Http\Controllers\CreditProofController::class, 'platform'])->name('credit-proofs.show');
    Route::get('/subscription-invoices/{invoice}', [SubscriptionInvoiceController::class, 'platform'])->name('subscription-invoices.show');

    Route::middleware('platform:audit.view')->group(function () {
        Route::livewire('/audit-logs', 'pages::admin.audit-logs.index')->name('audit-logs.index');
    });

    Route::middleware('platform:reports.view')->group(function () {
        Route::livewire('/reports', 'pages::admin.reports.index')->name('reports.index');
        Route::get('/reports/{report}/export/{format}', PlatformReportExportController::class)->name('reports.export');
    });

    Route::middleware('platform:admins.manage')->group(function () {
        Route::livewire('/admins', 'pages::admin.admins.index')->name('admins.index');
    });

    Route::middleware('platform:settings.manage')->group(function () {
        Route::livewire('/settings', 'pages::admin.settings.index')->name('settings.index');
    });

    Route::middleware('platform:libraries.manage')->group(function () {
        Route::livewire('/support', 'pages::admin.support.index')->name('support.index');
    });
});

/*
|--------------------------------------------------------------------------
| Library (one tenant per URL; membership is verified by the `library` middleware,
| the subscription policy by `subscription`)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'library', 'subscription'])->prefix('app/{library}')->name('app.')->group(function () {
    Route::livewire('/', 'pages::app.dashboard')->name('dashboard');
    Route::livewire('/insights', 'pages::app.insights.index')->name('insights');

    // billing pages stay reachable even when the subscription is restricted
    Route::livewire('/subscription', 'pages::app.subscription.index')->name('subscription.index');
    Route::livewire('/subscription/plans', 'pages::app.subscription.plans')->name('subscription.plans');
    Route::livewire('/subscription/checkout', 'pages::app.subscription.checkout')->name('subscription.checkout');
    Route::livewire('/subscription/credits-checkout', 'pages::app.subscription.credits-checkout')->name('subscription.credits-checkout');
    Route::livewire('/profile', 'pages::account.profile')->name('profile.edit');
    Route::livewire('/refer', 'pages::app.refer.index')->name('refer.index');
    Route::livewire('/messages', 'pages::app.messages.index')->name('messages.index');
    Route::livewire('/templates', 'pages::app.templates.index')->name('templates.index');
    Route::livewire('/help', 'pages::app.help.index')->name('help.index');
    Route::livewire('/contact', 'pages::app.contact.index')->name('contact.index');
    Route::livewire('/qr-code', 'pages::app.qr.index')->name('qr.index');
    Route::get('/subscription/invoices/{invoice}', [SubscriptionInvoiceController::class, 'library'])->name('subscription.invoice');
    Route::get('/subscription/proofs/{proof}', [SubscriptionProofController::class, 'library'])->name('subscription.proof');
    Route::get('/subscription/credit-proofs/{proof}', [\App\Http\Controllers\CreditProofController::class, 'library'])->name('subscription.credit-proof');

    // student self-service (the student is always the signed-in login's own record)
    Route::livewire('/my', 'pages::app.my.index')->name('my.index');
    Route::get('/my/receipts/{payment}', [ReceiptController::class, 'mine'])->name('my.receipt');

    Route::get('/students/export/{format}', [ReportExportController::class, 'students'])->name('students.export');
    Route::livewire('/students', 'pages::app.students.index')->name('students.index');
    Route::livewire('/students/create', 'pages::app.students.form')->name('students.create');
    Route::livewire('/students/{student}', 'pages::app.students.show')->name('students.show');
    Route::livewire('/students/{student}/edit', 'pages::app.students.form')->name('students.edit');

    Route::livewire('/halls', 'pages::app.halls.index')->name('halls.index');
    Route::livewire('/seats', 'pages::app.seats.index')->name('seats.index');
    Route::livewire('/membership-plans', 'pages::app.plans.index')->name('plans.index');
    Route::livewire('/memberships', 'pages::app.memberships.index')->name('memberships.index');

    Route::livewire('/payments', 'pages::app.payments.index')->name('payments.index');
    Route::get('/payments/{payment}/receipt', [ReceiptController::class, 'show'])->name('payments.receipt');
    Route::livewire('/dues', 'pages::app.dues.index')->name('dues.index');
    Route::livewire('/payment-submissions', 'pages::app.payment-submissions.index')->name('payment-submissions.index');
    Route::get('/payment-submissions/{submission}/proof', [PaymentSubmissionProofController::class, 'show'])->name('payment-submissions.proof');

    // optional modules, switched on or off by the library's plan
    Route::middleware('module:attendance')->group(function () {
        Route::livewire('/attendance', 'pages::app.attendance.index')->name('attendance.index');
        Route::livewire('/biometric', 'pages::app.biometric.index')->name('biometric.index');
    });
    Route::middleware('module:expenses')->group(function () {
        Route::livewire('/expenses', 'pages::app.expenses.index')->name('expenses.index');
        Route::get('/expenses/{expense}/attachment', [ExpenseAttachmentController::class, 'show'])->name('expenses.attachment');
    });
    Route::middleware('module:reports')->group(function () {
        Route::livewire('/reports', 'pages::app.reports.index')->name('reports.index');
        Route::get('/reports/{report}/export/{format}', ReportExportController::class)->name('reports.export');
    });

    // lending: part of the Books module
    Route::middleware('module:books')->group(function () {
        Route::livewire('/circulation', 'pages::app.circulation.index')->name('circulation.index');
        Route::livewire('/circulation/loans', 'pages::app.circulation.loans')->name('circulation.loans');
        Route::livewire('/reservations', 'pages::app.circulation.reservations')->name('reservations.index');
        Route::livewire('/fines', 'pages::app.circulation.fines')->name('fines.index');
        Route::get('/circulation/receipts/issue/{loan}', [CirculationReceiptController::class, 'issue'])->name('circulation.receipt.issue')->whereNumber('loan');
        Route::get('/circulation/receipts/return/{receipt}', [CirculationReceiptController::class, 'return'])->name('circulation.receipt.return');
        Route::get('/circulation/receipts/fine/{fine}', [CirculationReceiptController::class, 'fine'])->name('circulation.receipt.fine')->whereNumber('fine');
    });

    // books & e-books: separate plan modules, switched on per library by the platform
    Route::middleware('module:books')->group(function () {
        Route::get('/books/export/{format}', [ReportExportController::class, 'catalogue'])->name('books.export');
        Route::livewire('/books/import', 'pages::app.books.import')->name('books.import');
        Route::livewire('/books/labels', 'pages::app.books.labels')->name('books.labels');
        Route::livewire('/books/audits', 'pages::app.books.audits')->name('books.audits');
        Route::livewire('/books/audits/{audit}', 'pages::app.books.audit')->name('books.audits.show')->whereNumber('audit');
        Route::get('/books/audits/{audit}/export', StockTakeExportController::class)->name('books.audits.export')->whereNumber('audit');
    });
    Route::middleware('module:books|ebooks')->group(function () {
        Route::livewire('/books', 'pages::app.books.index')->name('books.index');
        Route::livewire('/books/create', 'pages::app.books.form')->name('books.create');
        Route::livewire('/books/categories', 'pages::app.books.categories')->name('books.categories');
        Route::livewire('/books/{book}', 'pages::app.books.show')->name('books.show')->whereNumber('book');
        Route::livewire('/books/{book}/edit', 'pages::app.books.form')->name('books.edit')->whereNumber('book');
        Route::get('/books/{book}/cover', [BookCoverController::class, 'show'])->name('books.cover')->whereNumber('book');
    });
    Route::middleware('module:ebooks')->group(function () {
        Route::livewire('/ebooks', 'pages::app.ebooks.index')->name('ebooks.index');
        Route::get('/ebooks/{book}/{action?}', [EbookFileController::class, 'show'])->name('ebooks.open')->whereNumber('book')->where('action', 'view|download');
    });

    Route::livewire('/staff', 'pages::app.staff.index')->name('staff.index');
    Route::livewire('/roles', 'pages::app.roles.index')->name('roles.index');
    Route::livewire('/settings', 'pages::app.settings.index')->name('settings.index');
});

// Old bookmark /app → send people to their library.
Route::get('/app', fn () => redirect()->route('workspace'))->middleware('auth');

