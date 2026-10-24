# Private Study Library Management SaaS
## Functional Requirements & Technical Specification

**Version:** 1.0  
**Framework:** Laravel 12  
**Database:** MySQL  
**Hosting:** Hostinger Shared Web Hosting

---

## 1. Project Overview

The goal is to build a multi-tenant SaaS platform for private study libraries and study halls.

Each library can manage its own students, seats, memberships, attendance, fees, staff, expenses, and reports through a dedicated admin panel.

A single Super Admin manages all registered libraries and the platform's subscription plans.

The platform must support different library sizes, from a small study room operated by one owner to a large library with multiple floors, staff members, and hundreds of seats.

### Main objectives

- Manage multiple independent study libraries in one application.
- Prevent libraries from accessing each other's information.
- Manage students, seats, memberships, attendance, and payments.
- Provide a smooth interface without full-page reloads for normal actions.
- Manage the SaaS subscription fees charged to library owners.
- Support manual payments initially and allow online payments to be added later.
- Run on Hostinger Shared Web Hosting without requiring a dedicated WebSocket server or Redis.

## 2. Technology Stack

| Layer | Technology |
|---|---|
| Backend | Laravel 12 |
| Programming language | PHP 8.2+; confirm all dependencies support the selected PHP version |
| Database | MySQL |
| Admin panel | Filament, compatible with Laravel 12 |
| Interactive UI | Livewire 3 |
| Smooth navigation | Livewire `wire:navigate` |
| Frontend templates | Blade |
| Lightweight JavaScript | Alpine.js |
| Styling | Tailwind CSS |
| Authentication | Laravel authentication with secure sessions |
| Authorization | Spatie Laravel Permission or an equivalent permission system |
| Database access | Eloquent ORM |
| Assets | Vite |
| Hosting | Hostinger Shared Web Hosting |
| Version control | Git |
| Testing | PHPUnit or Pest |

### Technology restrictions

Do not require the following for the initial version:

- Laravel Reverb
- Laravel Echo
- Redis
- A dedicated WebSocket server
- Paid real-time messaging services
- Mandatory queue workers

Do not use AJAX polling for live dashboard updates.

**Important distinction:** Livewire uses background HTTP requests for user actions. `wire:navigate` prevents ordinary full-page reloads during supported navigation, but Livewire is not a real-time push service. Updates caused by another user's actions will not appear automatically unless a separate push mechanism is added.

## 3. Multi-Tenant Architecture

The application must support multiple independent libraries using one application and one MySQL database.

### 3.1 Super Admin

The Super Admin can:

- Register new libraries.
- Activate, suspend, or deactivate library accounts.
- Configure SaaS subscription plans.
- Review subscription payments.
- Configure payment methods.
- Access platform-wide reports.
- Manage platform settings and support requests.

### 3.2 Independent library accounts

Each library must have its own:

- Students
- Staff
- Study halls and floors
- Seats
- Membership plans
- Student memberships
- Attendance records
- Payments and receipts
- Expenses
- Reports
- Operational settings

### 3.3 Tenant isolation requirements

- Every tenant-owned record must be associated with a library.
- Resolve the active library from the authenticated user's verified library membership.
- Never trust a client-submitted `library_id` without authorization.
- Apply tenant scope to all queries, APIs, exports, reports, and background tasks.
- Validate that linked records belong to the same library.
- Protect tenant data using authorization policies and database constraints where appropriate.
- Log sensitive Super Admin actions and cross-library access.
- Test explicitly that users cannot access another library by changing an ID in a URL or request.

Use shared-database multi-tenancy for version 1. Separate databases per tenant can be considered in a future version.

## 4. User Roles and Permissions

The system should include these default roles.

### 4.1 Super Admin

- Manage all library accounts.
- Manage SaaS plans and subscriptions.
- Configure platform payment settings.
- Approve or reject subscription payment submissions.
- View platform-wide revenue and subscription reports.
- Manage global settings and audit logs.

### 4.2 Library Owner / Admin

- Manage their library profile.
- Configure halls, floors, and seats.
- Manage students and staff.
- Create membership plans.
- Manage attendance and seat allocation.
- Manage student payments, expenses, and dues.
- View financial and operational reports.
- Manage permissions within their library.

### 4.3 Library Manager

- Manage daily library operations.
- Register and update students.
- Allocate seats and manage attendance.
- Review memberships and renewals.
- View authorized reports.

### 4.4 Receptionist

- Register students.
- Search student records.
- Allocate or release seats.
- Record check-in and check-out.
- Record authorized fee payments.
- Print or download receipts.

### 4.5 Accountant

- Record and reconcile payments.
- Manage outstanding fees.
- Record expenses and refunds.
- Generate financial reports.
- Review receipts and transaction histories.

### 4.6 Floor Supervisor

- Monitor seat occupancy.
- Record attendance where permitted.
- Identify unavailable seats.
- Report maintenance issues.
- View assigned floor information.

### 4.7 Student

- View their own profile.
- View membership details.
- View seat assignment.
- View attendance history.
- View payment history and outstanding dues.
- Request renewal or submit payment information where enabled.

### Permission requirements

- Permissions must be configurable.
- A user may have multiple roles where authorized.
- A user may belong to multiple libraries, with a separately authorized role in each.
- A library user must never receive platform-wide permissions by default.
- Financial, refund, deletion, and subscription-approval permissions must be restricted appropriately.

## 5. Library Profile and Settings

Each library can manage:

- Library name and logo.
- Address, phone number, and email.
- Opening and closing hours.
- Working days and holidays.
- Time zone.
- Currency and receipt details.
- Seat numbering format.
- Attendance rules.
- Late arrival and grace-period rules.
- Membership renewal rules.
- Notification preferences.
- Tax and invoice settings where applicable.
- Staff access and role permissions.

## 6. Study Hall, Floor, and Seat Management

### 6.1 Study halls and floors

Allow each library to create multiple study halls, rooms, and floors.

Fields may include:

- Hall or floor name.
- Description.
- Capacity.
- Operating hours.
- Status.
- Assigned supervisor.

### 6.2 Seat management

Each seat should have:

- Seat number or code.
- Library and hall/floor association.
- Seat type.
- Status.
- Optional notes.
- Maintenance availability.

Seat statuses:

- Available
- Occupied
- Reserved
- Unavailable
- Maintenance

### 6.3 Seat allocation

- Assign a seat to a student.
- Allocate seats according to membership rules.
- Support fixed-seat and flexible-seat memberships.
- Prevent conflicting active allocations.
- Release seats when memberships end or students check out, according to library settings.
- Keep an allocation history.
- Allow authorized staff to change seat assignments.
- Provide a visual seat layout with status indicators.
- Support filtering by hall, floor, seat type, and availability.

Use database transactions and conflict checks to prevent two users from allocating the same seat simultaneously.

## 7. Student Management

### 7.1 Student registration

Capture configurable fields such as:

- Student ID, generated automatically.
- Full name.
- Phone number.
- Email address, if available.
- Address.
- Emergency contact, if needed.
- Registration date.
- Photograph, if required.
- Notes.
- Account status.

### 7.2 Student operations

- Create, edit, view, and search student records.
- Filter by membership status and outstanding balance.
- View current and previous memberships.
- View assigned seat.
- View attendance and payment history.
- Suspend or deactivate a student according to permissions.
- Export authorized student records.
- Detect potential duplicate registrations.

Student records with financial or attendance history should generally be deactivated rather than permanently deleted.

## 8. Membership Plan Management

Each library can define its own membership plans.

### Plan configuration

- Plan name and description.
- Duration: daily, weekly, monthly, quarterly, half-yearly, or yearly.
- Price.
- Registration fee, if applicable.
- Shift or time-slot restrictions.
- Fixed-seat or flexible-seat option.
- Access days and hours.
- Optional discount.
- Renewal rules.
- Grace period.
- Status.

### Membership operations

- Assign a plan to a student.
- Set start and end dates.
- Record discounts with appropriate authorization.
- Renew or extend memberships.
- Change plans using configured rules.
- Track expired, active, upcoming, and suspended memberships.
- View membership history.
- Prevent invalid dates and unauthorized extensions.

Do not automatically mark a membership as paid merely because a membership record has been created.

## 9. Attendance and Check-In / Check-Out

### Attendance functions

- Student check-in.
- Student check-out.
- Record date and time.
- Identify the staff member recording attendance.
- Prevent duplicate active check-ins.
- Show students currently inside the library.
- View daily and historical attendance.
- Search by student, date, hall, or seat.
- Configure attendance rules per library.

### Additional requirements

- Handle missed check-outs through an authorized correction process.
- Maintain a record of attendance corrections.
- Restrict manual changes to authorized roles.
- Display current attendance status on the dashboard.

Attendance and seat occupancy should be consistent, but they must remain separate concepts: a seat can be reserved without the student being physically present.

## 10. Student Fee and Payment Management

This module manages the money collected from students, separate from the SaaS subscription paid by library owners.

### Payment functions

- Record a payment against a student or membership.
- Support cash, bank transfer, UPI, and other enabled payment methods.
- Generate a unique receipt number.
- Record amount, date, reference, payment method, and staff member.
- Support partial payments.
- Calculate outstanding dues.
- View payment history.
- Print or download receipts.
- Record authorized refunds and payment corrections.
- Identify overdue memberships and balances.

### Financial safeguards

- Do not allow unauthorized payment deletion.
- Use reversal or refund records to preserve financial history.
- Keep original payment records and audit important changes.
- Use transactions to prevent inconsistent balances.
- Prevent duplicate submissions from charging or recording a payment twice.
- Restrict access to financial reports by role.

## 11. Expense Management

Library owners and authorized accountants can record:

- Expense category.
- Description.
- Amount.
- Expense date.
- Payment method.
- Vendor or recipient.
- Receipt or attachment.
- Recorded-by user.

Provide daily, monthly, and date-range expense reports.

Track student fee income separately from operating expenses and SaaS subscription revenue.

## 12. Dashboard and Reports

### Library dashboard

Display:

- Total registered students.
- Active memberships.
- Expiring memberships.
- Expired memberships.
- Total seats.
- Available and occupied seats.
- Students currently checked in.
- Today's attendance.
- Today's collections.
- Outstanding student dues.
- Monthly income and expenses.

### Reports

- Student registration report.
- Membership report.
- Membership expiry report.
- Seat occupancy report.
- Attendance report.
- Student payment report.
- Outstanding dues report.
- Refund report.
- Expense report.
- Income and expense summary.
- Staff activity report.

Allow date filters and authorized exports to CSV or Excel.

Every report and export must enforce tenant and role permissions.

## 13. SaaS Subscription Management

This module manages payments made by libraries to use the platform.

### 13.1 Subscription plans

The Super Admin can configure:

- Plan name.
- Monthly, quarterly, or annual pricing.
- Trial duration.
- Maximum seats.
- Maximum students.
- Maximum staff accounts.
- Enabled modules and features.
- Plan status.
- Grace period.
- Renewal policy.

### 13.2 Subscription lifecycle

Support statuses such as:

- Trial
- Pending
- Active
- Expired
- Suspended
- Cancelled

Keep subscription status separate from payment status.

### 13.3 Manual payment support

Initially support offline payments:

- Display configured payment instructions.
- Let the library submit payment reference details and proof where required.
- Mark payments as pending review.
- Allow Super Admin to approve or reject submissions.
- Activate or extend the subscription according to the configured policy.
- Generate a subscription invoice or receipt.
- Keep an auditable history of decisions.

### 13.4 Future online payments

Design the subscription module so online gateway integration can be added later without redesigning the entire system.

Future support may include:

- Payment gateway transactions.
- Signed webhook verification.
- Idempotent payment processing.
- Automatic renewals where supported.
- Refund and failed-payment handling.

### 13.5 Subscription restrictions

When a library's subscription expires or is suspended:

- Apply the configured grace period.
- Restrict access according to the platform's policy.
- Notify the library owner.
- Preserve existing data and financial records.
- Allow authorized billing access to resolve the subscription.
- Never silently delete tenant data.

The Super Admin must control available payment methods and subscription activation rules.

## 14. Notifications and Reminders

Provide in-application notifications for:

- Membership expiry.
- Upcoming renewals.
- Outstanding fees.
- Payment approval or rejection.
- Library subscription expiry.
- Subscription payment status.
- Important administrative announcements.

Email or SMS notifications can be added based on hosting capability, provider configuration, and budget.

Do not require a queue worker for basic in-app notifications. Scheduled reminders should use Hostinger's supported cron configuration if available.

## 15. Smooth UI and Navigation Requirements

Use Livewire and Alpine.js for responsive interactions.

### Required behavior

- Navigate between application pages without normal full-page reloads.
- Submit forms and update affected components without reloading the whole page.
- Open create/edit forms in modals where appropriate.
- Search and filter tables interactively.
- Display loading indicators during server requests.
- Prevent duplicate form submissions.
- Display validation errors beside the relevant fields.
- Show success and error notifications.
- Preserve relevant filters and pagination where practical.
- Use responsive layouts for desktop, tablet, and mobile.
- Handle expired sessions and server errors gracefully.

### Technical implementation

Use:

- `wire:navigate` for supported internal navigation.
- `wire:submit` for forms.
- `wire:click` for actions.
- `wire:model` for form fields.
- Alpine.js for local interface behavior.
- Filament actions and forms where suitable.

Avoid full-page navigation for routine operations where Livewire supports the interaction.

**Live-update limitation:** These features do not provide automatic push updates from one browser to another. Do not use polling in this version. If cross-browser live updates become mandatory, treat that as a separate future integration.

## 16. Suggested Database Structure

Use MySQL with InnoDB, foreign keys, appropriate indexes, and `utf8mb4`.

### Platform and tenancy

- `libraries`
- `users`
- `library_user`
- `roles`
- `permissions`
- `model_has_roles`
- `model_has_permissions`
- `role_has_permissions`

Use the standard table names required by the chosen permissions package.

### Library operations

- `library_settings`
- `study_halls`
- `seats`
- `students`
- `staff_profiles` (if additional staff-specific information is needed)
- `membership_plans`
- `student_memberships`
- `seat_allocations`
- `attendance`
- `payments`
- `payment_allocations`
- `refunds`
- `expense_categories`
- `expenses`
- `notifications`
- `audit_logs`

### SaaS billing

- `subscription_plans`
- `subscriptions`
- `subscription_payments`
- `subscription_payment_proofs`
- `subscription_invoices`
- `subscription_events`
- `payment_gateway_transactions` (for future gateway integration)
- `subscription_settings`

### Data model rules

- Tenant-owned tables must have `library_id` where appropriate.
- Add foreign keys and indexes for frequently searched columns.
- Use unique constraints for library-specific student IDs, seat numbers, and receipt numbers.
- Store money using fixed-precision decimal fields, not floating-point types.
- Store timestamps consistently and display them in the library's configured time zone.
- Use database transactions for related financial, membership, and seat-allocation operations.
- Use soft deletion selectively; never use it as a replacement for proper financial audit history.

## 17. Security Requirements

- Use secure authentication and password hashing.
- Require HTTPS in production.
- Apply authorization on the server for every protected action.
- Validate all submitted data.
- Protect against CSRF, XSS, SQL injection, and mass-assignment vulnerabilities.
- Rate-limit login and sensitive endpoints.
- Validate uploaded file types and sizes.
- Store uploaded files using safe storage rules.
- Never expose `.env`, database credentials, or application secrets.
- Keep production debugging disabled.
- Log sensitive administrative and financial actions.
- Enforce tenant isolation in all reads, writes, reports, and exports.
- Restrict account suspension, refunds, permission changes, and payment approval.
- Test concurrency for seat allocations and payments.

## 18. Performance and Hosting Requirements

Target Hostinger Shared Web Hosting.

- Confirm the hosting plan's PHP version and required PHP extensions.
- Verify that the plan supports the selected Laravel and Filament versions.
- Build frontend assets locally or in a suitable build environment and deploy compiled assets.
- Use database indexes and pagination.
- Avoid loading all students, seats, or transactions into memory.
- Cache safe, frequently accessed configuration where appropriate.
- Keep logs under control.
- Configure cron only for tasks that genuinely require scheduling.
- Use secure backups and test restoration.
- Monitor PHP errors and database performance.
- Avoid dependencies on persistent server processes that shared hosting cannot reliably run.

## 19. Testing Requirements

Before production, test:

1. A new library can be registered and activated.
2. Each library sees only its own records.
3. Users cannot access another library by changing record IDs.
4. Role permissions restrict unauthorized actions.
5. Seat allocation prevents conflicting active assignments.
6. Membership dates, renewals, and expiry work correctly.
7. Attendance handles duplicate check-ins and missed check-outs.
8. Partial payments and outstanding balances calculate correctly.
9. Refunds preserve financial history.
10. Manual subscription payments require the correct approval.
11. Expired subscriptions follow the configured policy.
12. Reports and exports enforce tenant boundaries.
13. Forms, modals, navigation, and actions work without routine full-page reloads.
14. The application behaves correctly on mobile devices.
15. Backups can be restored successfully.

## 20. Development Phases

### Phase 1 — Foundation

- Laravel 12 setup.
- MySQL configuration.
- Authentication.
- Multi-tenant architecture.
- Role and permission system.
- Shared UI layout.

### Phase 2 — Library operations

- Library settings.
- Halls, floors, and seats.
- Student registration.
- Membership plans and memberships.
- Seat allocation.

### Phase 3 — Attendance and finance

- Check-in/check-out.
- Student payments and receipts.
- Outstanding dues.
- Refunds and expenses.

### Phase 4 — Dashboard and reporting

- Library dashboard.
- Occupancy and attendance reports.
- Financial reports.
- CSV/Excel exports.

### Phase 5 — SaaS administration

- Super Admin panel.
- Library registration and activation.
- SaaS subscription plans.
- Manual subscription payments.
- Approval workflow and billing history.

### Phase 6 — Quality and deployment

- Tenant-isolation testing.
- Security review.
- Performance optimization.
- Mobile responsiveness.
- Hosting configuration.
- Backup and restore testing.
- Production deployment.

## 21. Future Enhancements

Keep these outside the initial release unless explicitly prioritized:

- Online gateway payments.
- WhatsApp or SMS integration.
- Automatic renewal reminders through external providers.
- QR-code check-in.
- Biometric attendance integration.
- Multiple branches under one library organization.
- Advanced occupancy analytics.
- Student self-service website or PWA.
- Automated cross-browser real-time push updates.

## 22. Acceptance Criteria

The first production-ready release is complete when:

- Multiple libraries can operate independently.
- Super Admin can control library registration and SaaS subscriptions.
- Library owners can manage their students, seats, memberships, attendance, payments, and expenses.
- Configurable roles and permissions are enforced.
- Tenant data cannot leak across libraries.
- Routine navigation and actions work without full-page reloads.
- Manual subscription payments and approvals work.
- Reports and exports are accurate and permission-controlled.
- The application deploys and runs within the supported limits of Hostinger Shared Web Hosting.
- Security, financial consistency, and backup restoration tests pass.

---

**Final technology decision:** Laravel 12 + MySQL + Filament + Livewire 3 + Blade + Alpine.js + Tailwind CSS, with shared-database multi-tenancy and manual SaaS subscription payments initially.

**Important implementation rule:** Do not claim the application has cross-user real-time push updates in this release. Smooth navigation and background form actions are included; automatic updates on another user's screen are not, because this specification excludes polling and external real-time services.