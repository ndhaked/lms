<?php

use App\Http\Controllers\Api\V1;
use Illuminate\Support\Facades\Route;

/*
| Mobile API inside one library: /api/v1/libraries/{library}/...
| Loaded from routes/api.php behind auth:sanctum, api.user, library and api.subscription.
| Route names start with api.v1.library.
*/

// ---------- library, theme, dashboard ----------
Route::get('/', [V1\LibraryController::class, 'context'])->name('context');
Route::get('theme', [V1\LibraryController::class, 'theme'])->name('theme.show');
Route::put('theme', [V1\LibraryController::class, 'updateTheme'])->name('theme.update');
Route::get('dashboard', [V1\DashboardController::class, 'dashboard'])->name('dashboard');
Route::get('insights', [V1\DashboardController::class, 'insights'])->name('insights');

// ---------- students ----------
Route::get('students', [V1\StudentController::class, 'index'])->name('students.index');
Route::post('students', [V1\StudentController::class, 'store'])->middleware('throttle:api-uploads')->name('students.store');
Route::get('students/{student}', [V1\StudentController::class, 'show'])->whereNumber('student')->name('students.show');
Route::put('students/{student}', [V1\StudentController::class, 'update'])->whereNumber('student')->name('students.update');
Route::post('students/{student}/photo', [V1\StudentController::class, 'photo'])->whereNumber('student')->middleware('throttle:api-uploads')->name('students.photo');
Route::post('students/{student}/status', [V1\StudentController::class, 'status'])->whereNumber('student')->name('students.status');
Route::post('students/{student}/login', [V1\StudentController::class, 'createLogin'])->whereNumber('student')->name('students.login');
Route::post('students/{student}/seat', [V1\SeatController::class, 'seatStudent'])->whereNumber('student')->name('students.seat');
Route::delete('students/{student}/seat', [V1\SeatController::class, 'unseatStudent'])->whereNumber('student')->name('students.unseat');

// ---------- halls and seats ----------
Route::get('halls', [V1\SeatController::class, 'halls'])->name('halls.index');
Route::post('halls', [V1\SeatController::class, 'storeHall'])->name('halls.store');
Route::put('halls/{hall}', [V1\SeatController::class, 'updateHall'])->whereNumber('hall')->name('halls.update');
Route::get('seats', [V1\SeatController::class, 'index'])->name('seats.index');
Route::post('seats', [V1\SeatController::class, 'store'])->name('seats.store');
Route::post('seats/generate', [V1\SeatController::class, 'generate'])->name('seats.generate');
Route::get('seats/eligible-students', [V1\SeatController::class, 'eligible'])->name('seats.eligible');
Route::get('seats/{seat}', [V1\SeatController::class, 'show'])->whereNumber('seat')->name('seats.show');
Route::put('seats/{seat}', [V1\SeatController::class, 'update'])->whereNumber('seat')->name('seats.update');
Route::post('seats/{seat}/allocate', [V1\SeatController::class, 'allocate'])->whereNumber('seat')->name('seats.allocate');
Route::post('seats/{seat}/release', [V1\SeatController::class, 'release'])->whereNumber('seat')->name('seats.release');

// ---------- membership plans and memberships ----------
Route::get('plans', [V1\MembershipController::class, 'plans'])->name('plans.index');
Route::post('plans', [V1\MembershipController::class, 'storePlan'])->name('plans.store');
Route::put('plans/{plan}', [V1\MembershipController::class, 'updatePlan'])->whereNumber('plan')->name('plans.update');
Route::get('memberships', [V1\MembershipController::class, 'index'])->name('memberships.index');
Route::post('memberships', [V1\MembershipController::class, 'store'])->name('memberships.store');
Route::get('memberships/{membership}', [V1\MembershipController::class, 'show'])->whereNumber('membership')->name('memberships.show');
Route::put('memberships/{membership}', [V1\MembershipController::class, 'update'])->whereNumber('membership')->name('memberships.update');
Route::post('memberships/{membership}/renew', [V1\MembershipController::class, 'renew'])->whereNumber('membership')->name('memberships.renew');
Route::get('renewal-requests', [V1\MembershipController::class, 'requests'])->name('renewal-requests.index');
Route::post('renewal-requests/{request}/dismiss', [V1\MembershipController::class, 'dismissRequest'])->whereNumber('request')->name('renewal-requests.dismiss');

// ---------- payments, dues, student payment claims ----------
Route::get('payments', [V1\PaymentController::class, 'index'])->name('payments.index');
Route::post('payments', [V1\PaymentController::class, 'store'])->name('payments.store');
Route::get('payments/{payment}', [V1\PaymentController::class, 'show'])->whereNumber('payment')->name('payments.show');
Route::get('payments/{payment}/receipt', [V1\PaymentController::class, 'receipt'])->whereNumber('payment')->name('payments.receipt');
Route::get('payments/{payment}/refund-options', [V1\PaymentController::class, 'refundOptions'])->whereNumber('payment')->name('payments.refund-options');
Route::post('payments/{payment}/refund', [V1\PaymentController::class, 'refund'])->whereNumber('payment')->name('payments.refund');
Route::post('payments/{payment}/reverse', [V1\PaymentController::class, 'reverse'])->whereNumber('payment')->name('payments.reverse');
Route::get('students/{student}/owing', [V1\PaymentController::class, 'owing'])->whereNumber('student')->name('students.owing');
Route::get('dues', [V1\PaymentController::class, 'dues'])->name('dues.index');
Route::get('payment-submissions', [V1\PaymentController::class, 'submissions'])->name('submissions.index');
Route::post('payment-submissions/{submission}/approve', [V1\PaymentController::class, 'approve'])->whereNumber('submission')->name('submissions.approve');
Route::post('payment-submissions/{submission}/reject', [V1\PaymentController::class, 'reject'])->whereNumber('submission')->name('submissions.reject');
Route::get('payment-submissions/{submission}/proof', [V1\PaymentController::class, 'submissionProof'])->whereNumber('submission')->name('submissions.proof');

// ---------- attendance, scanner, biometric devices ----------
Route::get('attendance/today', [V1\AttendanceController::class, 'today'])->name('attendance.today');
Route::get('attendance/candidates', [V1\AttendanceController::class, 'candidates'])->name('attendance.candidates');
Route::get('attendance', [V1\AttendanceController::class, 'history'])->name('attendance.index');
Route::post('attendance/check-in', [V1\AttendanceController::class, 'checkIn'])->name('attendance.check-in');
Route::post('attendance/scan', [V1\AttendanceController::class, 'scan'])->name('attendance.scan');
Route::post('attendance/{attendance}/check-out', [V1\AttendanceController::class, 'checkOut'])->whereNumber('attendance')->name('attendance.check-out');
Route::post('attendance/{attendance}/correct', [V1\AttendanceController::class, 'correct'])->whereNumber('attendance')->name('attendance.correct');
Route::get('biometric/devices', [V1\BiometricDeviceController::class, 'index'])->name('biometric.index');
Route::post('biometric/devices', [V1\BiometricDeviceController::class, 'store'])->name('biometric.store');
Route::put('biometric/devices/{device}', [V1\BiometricDeviceController::class, 'update'])->whereNumber('device')->name('biometric.update');
Route::post('biometric/devices/{device}/token', [V1\BiometricDeviceController::class, 'token'])->whereNumber('device')->name('biometric.token');
Route::delete('biometric/devices/{device}', [V1\BiometricDeviceController::class, 'destroy'])->whereNumber('device')->name('biometric.destroy');
Route::get('biometric/punches', [V1\BiometricDeviceController::class, 'punches'])->name('biometric.punches');

// ---------- expenses and reports ----------
Route::get('expenses', [V1\ExpenseReportController::class, 'expenses'])->name('expenses.index');
Route::post('expenses', [V1\ExpenseReportController::class, 'storeExpense'])->middleware('throttle:api-uploads')->name('expenses.store');
Route::post('expenses/{expense}', [V1\ExpenseReportController::class, 'updateExpense'])->whereNumber('expense')->middleware('throttle:api-uploads')->name('expenses.update');
Route::get('expenses/{expense}/attachment', [V1\ExpenseReportController::class, 'attachment'])->whereNumber('expense')->name('expenses.attachment');
Route::post('expense-categories', [V1\ExpenseReportController::class, 'storeCategory'])->name('expenses.categories.store');
Route::get('reports', [V1\ExpenseReportController::class, 'reports'])->name('reports.index');
Route::get('reports/{report}', [V1\ExpenseReportController::class, 'report'])->name('reports.show');
Route::get('reports/{report}/export/{format}', [V1\ExpenseReportController::class, 'export'])->name('reports.export');

// ---------- subscription, checkout, credits ----------
Route::get('subscription', [V1\BillingController::class, 'subscription'])->name('subscription.show');
Route::get('subscription/plans', [V1\BillingController::class, 'plans'])->name('subscription.plans');
Route::post('subscription/quote', [V1\BillingController::class, 'quote'])->name('subscription.quote');
Route::post('subscription/checkout/online', [V1\BillingController::class, 'onlineSubscription'])->name('subscription.online');
Route::post('subscription/checkout/cash', [V1\BillingController::class, 'cashSubscription'])->middleware('throttle:api-uploads')->name('subscription.cash');
Route::post('subscription/credits/online', [V1\BillingController::class, 'onlineCredits'])->name('subscription.credits.online');
Route::post('subscription/credits/cash', [V1\BillingController::class, 'cashCredits'])->middleware('throttle:api-uploads')->name('subscription.credits.cash');
Route::post('subscription/checkout/verify', [V1\BillingController::class, 'verifyOnline'])->name('subscription.verify');
Route::get('subscription/invoices/{invoice}', [V1\BillingController::class, 'invoice'])->whereNumber('invoice')->name('subscription.invoice');

// ---------- support and more ----------
Route::get('refer', [V1\SupportController::class, 'refer'])->name('refer.show');
Route::get('help', [V1\SupportController::class, 'help'])->name('help.show');
Route::post('help/requests', [V1\SupportController::class, 'sendRequest'])->name('help.request');
Route::get('contact', [V1\SupportController::class, 'contact'])->name('contact');
Route::get('qr', [V1\SupportController::class, 'qr'])->name('qr');

// ---------- SMS and WhatsApp ----------
Route::get('messages/counters', [V1\MessageController::class, 'counters'])->name('messages.counters');
Route::get('messages', [V1\MessageController::class, 'history'])->name('messages.index');
Route::get('message-templates', [V1\MessageController::class, 'templates'])->name('templates.index');
Route::put('message-templates/automatic', [V1\MessageController::class, 'toggle'])->name('templates.toggle');
Route::post('message-templates/whatsapp', [V1\MessageController::class, 'storeWhatsapp'])->name('templates.whatsapp.store');
Route::put('message-templates/whatsapp/{template}', [V1\MessageController::class, 'updateWhatsapp'])->whereNumber('template')->name('templates.whatsapp.update');
Route::delete('message-templates/whatsapp/{template}', [V1\MessageController::class, 'destroyWhatsapp'])->whereNumber('template')->name('templates.whatsapp.destroy');

// ---------- books, lending, e-books, stock-taking (Books / E-books modules) ----------
Route::get('books', [V1\BookController::class, 'index'])->name('books.index');
Route::get('books/lookup', [V1\BookController::class, 'lookup'])->name('books.lookup');
Route::get('books/{book}', [V1\BookController::class, 'show'])->whereNumber('book')->name('books.show');
Route::get('books/{book}/cover', [V1\BookController::class, 'cover'])->whereNumber('book')->name('books.cover');
Route::get('ebooks/{book}/open', [V1\BookController::class, 'open'])->whereNumber('book')->name('ebooks.open');
Route::get('circulation/members/{student}', [V1\CirculationController::class, 'member'])->whereNumber('student')->name('circulation.member');
Route::post('circulation/issue', [V1\CirculationController::class, 'issue'])->name('circulation.issue');
Route::post('circulation/return', [V1\CirculationController::class, 'returnBooks'])->name('circulation.return');
Route::get('loans', [V1\CirculationController::class, 'loans'])->name('loans.index');
Route::post('loans/{item}/renew', [V1\CirculationController::class, 'renew'])->whereNumber('item')->name('loans.renew');
Route::get('reservations', [V1\CirculationController::class, 'reservations'])->name('reservations.index');
Route::post('reservations', [V1\CirculationController::class, 'reserve'])->name('reservations.store');
Route::post('reservations/{reservation}/cancel', [V1\CirculationController::class, 'cancelReservation'])->whereNumber('reservation')->name('reservations.cancel');
Route::get('fines', [V1\CirculationController::class, 'fines'])->name('fines.index');
Route::post('fines/{fine}/collect', [V1\CirculationController::class, 'collectFine'])->whereNumber('fine')->name('fines.collect');
Route::post('fines/{fine}/waive', [V1\CirculationController::class, 'waiveFine'])->whereNumber('fine')->name('fines.waive');
Route::get('stock-takes', [V1\StockTakeController::class, 'index'])->name('stock-takes.index');
Route::post('stock-takes', [V1\StockTakeController::class, 'store'])->name('stock-takes.store');
Route::get('stock-takes/{audit}', [V1\StockTakeController::class, 'show'])->whereNumber('audit')->name('stock-takes.show');
Route::post('stock-takes/{audit}/scan', [V1\StockTakeController::class, 'scan'])->whereNumber('audit')->name('stock-takes.scan');
Route::post('stock-takes/{audit}/complete', [V1\StockTakeController::class, 'complete'])->whereNumber('audit')->name('stock-takes.complete');
Route::post('stock-takes/{audit}/cancel', [V1\StockTakeController::class, 'cancel'])->whereNumber('audit')->name('stock-takes.cancel');

// ---------- staff and settings ----------
Route::get('staff', [V1\StaffSettingsController::class, 'staff'])->name('staff.index');
Route::post('staff', [V1\StaffSettingsController::class, 'storeStaff'])->name('staff.store');
Route::put('staff/{user}', [V1\StaffSettingsController::class, 'updateStaff'])->whereNumber('user')->name('staff.update');
Route::post('staff/{user}/access', [V1\StaffSettingsController::class, 'access'])->whereNumber('user')->name('staff.access');
Route::post('staff/{user}/reset-access', [V1\StaffSettingsController::class, 'resetAccess'])->whereNumber('user')->name('staff.reset');
Route::get('settings', [V1\StaffSettingsController::class, 'settings'])->name('settings.show');
Route::post('settings/profile', [V1\StaffSettingsController::class, 'updateProfile'])->middleware('throttle:api-uploads')->name('settings.profile');
Route::put('settings/general', [V1\StaffSettingsController::class, 'updateGeneral'])->name('settings.general');

// ---------- the student app (only the signed-in student's own data) ----------
Route::prefix('my')->name('my.')->group(function () {
    Route::get('/', [V1\StudentSelfController::class, 'home'])->name('home');
    Route::get('id', [V1\StudentSelfController::class, 'digitalId'])->name('id');
    Route::get('memberships', [V1\StudentSelfController::class, 'memberships'])->name('memberships');
    Route::post('renewal-request', [V1\StudentSelfController::class, 'requestRenewal'])->name('renewal');
    Route::get('attendance', [V1\StudentSelfController::class, 'attendance'])->name('attendance');
    Route::get('payments', [V1\StudentSelfController::class, 'payments'])->name('payments');
    Route::get('payments/{payment}/receipt', [V1\StudentSelfController::class, 'receipt'])->whereNumber('payment')->name('receipt');
    Route::post('payment-submissions', [V1\StudentSelfController::class, 'submitPayment'])->middleware('throttle:api-uploads')->name('submit');
    Route::get('payment-submissions/{submission}/proof', [V1\StudentSelfController::class, 'submissionProof'])->whereNumber('submission')->name('submission.proof');
    Route::get('books', [V1\StudentSelfController::class, 'books'])->name('books');
    Route::get('books/search', [V1\StudentSelfController::class, 'searchBooks'])->name('books.search');
    Route::post('reservations', [V1\StudentSelfController::class, 'reserve'])->name('reserve');
    Route::post('reservations/{reservation}/cancel', [V1\StudentSelfController::class, 'cancelReservation'])->whereNumber('reservation')->name('reservations.cancel');
    Route::post('loans/{item}/renew', [V1\StudentSelfController::class, 'renewBook'])->whereNumber('item')->name('loans.renew');
    Route::get('ebooks', [V1\StudentSelfController::class, 'ebooks'])->name('ebooks');
});
