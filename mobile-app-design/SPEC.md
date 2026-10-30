# LMS mobile app: developer spec

One mobile app for **library owners/staff** and **students**. The user signs in once; the app shows the workspace that matches their role in the selected library. The clickable design is in `index.html` (open it in a browser, or use the shared link).

## 1. How to use this package

| File | What it is |
|---|---|
| `index.html` | Hub: every screen as a thumbnail, grouped by role. Click a phone to open it full size. |
| `common/`, `student/`, `owner/` | One HTML file per screen. Static, no build step. Tabs, rows and buttons link to the next screen. |
| `assets/app.css` | The whole design system (colors, type, spacing, components). Class names are the component names. |
| `design-tokens.json` | The same tokens as data, for Flutter/React Native/Swift/Kotlin theme files. |
| `SPEC.md` / `spec.html` | This document. |

Reference device: **390 × 844** (iPhone 14 / Pixel class). On a real phone the HTML fills the screen. Sheets open with a `#anchor` link (CSS only) to show the bottom-sheet design.

## 2. What exists today (honest status)

- The **web panel** (Laravel) is finished and has everything the screens need: students, memberships, seats, attendance, payments and dues, expenses, reports, books and lending, fines, reservations, e-books, notifications, student portal.
- **There is no mobile API yet.** The panel is server-rendered. Section 8 lists the REST endpoints the app needs; they must be built (Laravel Sanctum token auth) before the app can run on real data.
- **Fingerprint / face / card attendance is built** (30 Oct 2026). ZKTeco, eSSL and Realtime machines push punches to the server; staff can still check students in and out by hand. See section 7 for the two different things people call "biometric".

## 3. Roles: who sees what

The app does not decide permissions. The server returns the user's role and permission list for the chosen library and the app only shows what is allowed. Hiding is cosmetic; every API call is checked again on the server.

| Server role (per library) | App workspace | Tabs | Typical permissions |
|---|---|---|---|
| Student | Student | Home · Attendance · Payments · Books · Me | `self.view`, `ebooks.read` |
| Library Owner | Owner | Home · Students · Attendance · Money · Menu | everything in the library |
| Manager | Owner (fewer items) | same | students, memberships, attendance, books, circulation |
| Receptionist | Owner (desk) | same | students (view/create), attendance, record payments, issue/return |
| Accountant | Owner (money) | same | payments, refunds, expenses, financial reports |
| Floor supervisor | Owner (floor) | Attendance, Seats | attendance, seats |

A person can hold different roles in different libraries (owner in one, student in another). The *Choose library* screen appears only when there is more than one; the chosen library sets the workspace.

Library state that changes the UI (returned in the `/me` payload):
- `modules`: `books`, `ebooks`, `attendance`, `expenses`, `reports`, `exports`. Hide tabs and menu items for modules the library does not have.
- `read_only: true`: the subscription ended and the library chose read-only. Show the "Library is read-only" banner, disable every write action.
- `subscription_state`: `full | warn | restricted | readonly`. `restricted` shows only a "ask the owner to renew" screen.
- Student features switched on by the library: `student_payment_submissions`, `student_can_renew`, `reservations_enabled`.

## 4. Navigation

```
Splash → Login ──(biometric unlock)──┐
          │                          ▼
          └→ Choose library (if >1) → Student tabs   or   Owner tabs
Student tabs: Home · Attendance · Payments · Books · Me
Owner tabs:   Home · Students · Attendance · Money · Menu
Bell (top right) → Notifications on every main screen
Push notification → opens the matching screen
```

## 5. Screen inventory

Backend feature = what already exists in the web panel. API = proposed endpoint (section 8).

### Shared
| Screen | File | Backend feature | API |
|---|---|---|---|
| Splash | `common/splash.html` | session check | `GET /me` |
| Sign in | `common/login.html` | login, rate limit | `POST /auth/login` |
| Forgot password | `common/forgot.html` | password reset email | `POST /auth/forgot` |
| Choose library | `common/choose-library.html` | library switcher | `GET /libraries` |
| Notifications | `common/notifications.html` | in-app notifications | `GET /notifications`, `POST /notifications/read` |
| System states | `common/states.html` | offline, empty, error, read-only | n/a |

### Student
| Screen | File | Backend feature | API |
|---|---|---|---|
| Home | `student/home.html` | membership, seat, dues, books out, today's attendance | `GET /student/home` |
| My ID | `student/id.html` | student code; rotating QR | `GET /student/id-token` (60 s signed token) |
| Membership + renew | `student/membership.html` | memberships, renewal request | `GET /student/memberships`, `POST /student/renewal-requests` |
| Attendance | `student/attendance.html` | attendance history | `GET /student/attendance?month=` |
| Payments | `student/payments.html` | payments, dues, claims | `GET /student/payments` |
| Receipt | `student/payment-detail.html` | receipt PDF | `GET /student/payments/{id}/receipt` (pdf) |
| I have paid | `student/i-have-paid.html` | student payment submissions | `POST /student/payment-submissions` (multipart) |
| Books | `student/books.html` | loans, renew, reservations, fines | `GET /student/loans`, `POST /student/loans/{id}/renew`, `GET /student/reservations`, `DELETE /student/reservations/{id}` |
| Find a book | `student/book-search.html` | catalogue search, reserve | `GET /catalogue?q=`, `POST /student/reservations` |
| E-books | `student/ebooks.html` | e-book library | `GET /ebooks`, `GET /ebooks/{id}/file` |
| Me | `student/profile.html` | profile, settings | `GET /me`, `PATCH /me/settings` |

### Owner / staff
| Screen | File | Backend feature | API |
|---|---|---|---|
| Dashboard | `owner/home.html` | dashboard cards and chart | `GET /owner/dashboard?year=` |
| Students | `owner/students.html` | list, search, filters | `GET /students?q=&filter=` |
| Student detail | `owner/student-detail.html` | memberships, dues, seat, books | `GET /students/{id}` |
| Add student | `owner/student-add.html` | create (duplicate check) | `POST /students` |
| Attendance | `owner/attendance.html` | inside now, check-in/out | `GET /attendance/today`, `POST /attendance/check-in`, `POST /attendance/{id}/check-out` |
| Scanner | `owner/scan.html` | QR/barcode → student or book | `POST /scan/resolve` |
| Seats | `owner/seats.html` | halls, seats, allocation | `GET /halls/{id}/seats`, `POST /seats/{id}/allocate` |
| Money | `owner/payments.html` | payments list, collections | `GET /payments`, `GET /owner/collections` |
| Record payment | `owner/record-payment.html` | RecordPayment rules | `POST /payments` (Idempotency-Key header) |
| Dues | `owner/dues.html` | outstanding dues | `GET /dues` |
| Approvals | `owner/approvals.html` | student payment claims, renewal requests | `GET /approvals`, `POST /payment-submissions/{id}/confirm`, `.../reject` |
| Memberships | `owner/memberships.html` | expiring/active/expired | `GET /memberships?state=` |
| Expenses | `owner/expenses.html` | expenses with receipt photo | `GET/POST /expenses` |
| Reports | `owner/reports.html` | report engine, exports | `GET /reports/{key}`, `GET /reports/{key}/export?format=pdf|xlsx|csv` |
| Books | `owner/books.html` | catalogue lookup | `GET /books?q=` |
| Issue & return | `owner/issue-return.html` | IssueBooks / ReturnBooks | `POST /loans`, `POST /loans/return` |
| Loans | `owner/loans.html` | overdue, due soon | `GET /loans?status=` |
| Fines | `owner/fines.html` | collect (waive stays on web) | `GET /fines`, `POST /fines/{id}/collect` |
| Biometric devices | `owner/biometric-attendance.html` | **Built** | see section 7b |
| More / Settings | `owner/profile.html` | app lock, language | `GET /me` |

Not in the app on purpose (stay on the web panel): creating plans and halls, roles and permissions, library settings, book import and label printing, stock-taking, Super Admin features.

## 6. Design system

Same brand as the web panel, mobile-sized. Values are in `design-tokens.json` and `assets/app.css`.

**Color**
- Brand green: 50 `#effaf3`, 100 `#d8f3e2`, 500 `#34a96b`, **600 `#2f9e5f` (primary)**, 700 `#237a49`, 800 `#1f613c`.
- Ink `#10201a`, secondary `#3d4d45`, muted `#6b7a72`; lines `#e3e9e5`; page `#f5f8f6`; card `#fff`.
- Semantic: red `#dc2626` (overdue, due, error), amber `#f59e0b` (warning, pending, late), blue `#3b82f6` (info, upcoming), violet `#8b5cf6` (e-books, special). Semantic colors carry status only, never decoration. Never rely on color alone: badges always carry text.

**Type** Inter. Sizes: 11 caption · 12 small · 14 body · 15 button/title · 18–20 screen title · 22–34 numbers. Numbers use tabular figures. Titles 700, body 400–500.

**Space and shape** 4 px grid; screen padding 16; card padding 16; gaps 12. Radius: 8 / 12 (inputs, tiles) / 16 (cards) / 24 (sheets, hero). Touch targets ≥ 44 px. One level of shadow for cards, one for sheets.

**Components** (class names in the CSS): top bar `.appbar`, bottom tabs `.tabbar` (5 max), hero card `.hero`, stat tile `.stat`, quick actions `.quick`, list `.list/.item`, badge `.badge`, filter chip `.chip`, segmented control `.seg`, buttons `.btn` (primary, `.sec`, `.danger`, `.sm`), inputs `.input`, switch `.switch`, bottom sheet `.sheet`, floating action `.fab`, calendar `.cal`, seat grid `.seats`, scanner `.scanbox`, empty/error/loading `.empty` `.skel`, notices `.note`.

**States every list needs:** loading skeleton, empty (with a next step), error with retry, offline banner with cached data, read-only banner. See `common/states.html`.

**Motion** short (150–200 ms) and functional: sheet slide-up, tab change fade. Respect "reduce motion".

**Accessibility** contrast ≥ 4.5:1 for text; support Dynamic Type / font scale to 130 %; every icon button has a label; focus order follows reading order.

**Dark mode** is not drawn. Build the app from the tokens so a dark set can be added; do not hard-code hex values in screens.

**Languages** English first; Hindi next (strings in resource files, `₹` with Indian grouping: 4,90,200).

## 7. Biometrics: two different things

The panel has **neither** today. They solve different problems.

### 7a. App unlock (fingerprint / face to open the app) — easy, designed
- Uses the phone's own biometrics (iOS LocalAuthentication, Android BiometricPrompt). The app never sees or stores a fingerprint.
- Flow: first sign-in with password → ask "Unlock with fingerprint next time?" → store the session token in Keychain/Keystore, protected by biometrics → on next launch show the OS prompt; on failure fall back to password.
- Screens: `common/login.html` (button + sheet), `student/profile.html` and `owner/profile.html` (switch).
- Needs no backend change beyond the token API.

### 7b. Fingerprint / thumb attendance (door device) — **built on the server**
A wall device (ZKTeco, eSSL, Realtime, etc.) reads the finger. The library wants those punches to become attendance.

Recommended design:
1. Devices push punches to the server over the vendor's network protocol (ADMS/push) or the server pulls them with the vendor SDK. A small connector service per device type does this.
2. New tables: `biometric_devices` (library, name, serial, last_seen), `biometric_enrollments` (student ↔ device user id), `biometric_punches` (device, device user id, time, direction, raw).
3. A punch with a known enrollment calls the existing check-in/check-out rules (late flag, one open visit, duplicate window e.g. 2 minutes). Unknown ids go to an "Unmatched" list for staff to enroll.
4. Fingerprint templates stay **on the device**. Our database stores only the device user id, never the fingerprint. Get the student's consent (privacy law applies to biometric data).
5. The owner app gets the "Device attendance" screen (`owner/biometric-attendance.html`, marked *Planned*) and an "Enroll" action.
6. Fallback for libraries without a device: CSV import of the device's export, or QR check-in with the student's rotating ID.

Do **not** use the phone's fingerprint as proof of attendance (it proves the phone owner, not who is at the library, and can be shared).

## 8. Proposed API (to be built)

REST, JSON, base `/api/v1`. Auth with Laravel Sanctum personal access tokens (one per device).

- `POST /auth/login` (email or mobile + password + device name) → token, user, libraries. `POST /auth/logout`. `POST /auth/forgot`.
- `GET /me?library={id}` → user, role, **permissions[]**, **modules[]**, `read_only`, `subscription_state`, settings flags, library name/timezone/currency.
- Lists are paginated (`?page=`, 20 per page), filters in the query string, dates ISO-8601 in the library's time zone, money as strings with 2 decimals.
- Errors: `{ "message": "...", "errors": { "field": ["..."] } }` with 401 (sign in), 403 (not allowed or read-only), 404, 422 (validation), 429 (rate limit).
- Writes that move money or stock (`POST /payments`, `/loans`, `/loans/return`) take an `Idempotency-Key` header so a retry never double-posts.
- Files: proofs, expense receipts and e-books are private; return short-lived signed URLs or stream through the API.
- Push: FCM/APNs; store a device token per login; same event kinds as the web notifications (`reservation.ready`, `payment_submission.new`, `loan.overdue`, ...).
- Rate limits: login 5/min per IP+email; scan 60/min per user.

Everything reuses the existing Laravel actions (`RecordPayment`, `IssueBooks`, `ReturnBooks`, `CheckInStudent`...) so the mobile rules are identical to the web.

## 9. Security

- Role and permissions come from the server on every request; never trust the app.
- Tokens in Keychain/Keystore; optional biometric gate; revoke on logout and on password change; idle timeout configurable.
- The student ID QR contains a signed, 60-second token, not the student number. Block screenshots on that screen (Android `FLAG_SECURE`).
- HTTPS only; certificate pinning optional.
- No library data cached for a signed-out user; clear cache on logout and on switching library.
- Read-only libraries and missing modules are enforced by the API (403), the app only mirrors it.

## 10. Suggested build order

1. API foundation: auth, `/me`, notifications, push tokens.
2. **Student app** (smaller): home, ID QR, payments + "I have paid", attendance, books (renew/reserve), e-books.
3. **Owner app**: dashboard, students, check-in/scanner, record payment, approvals, dues.
4. Owner extras: books lending, reports export, expenses, seats.
5. Biometric app unlock (can ship with step 2).
6. Biometric device attendance (section 7b), after choosing the device brand.

Cross-platform framework (Flutter or React Native) is recommended: one codebase, two roles.

## 11. Questions for the product owner

1. Which fingerprint device brand do libraries use (decides the connector)?
2. Hindi at launch, or English only?
3. Should students see seat availability, or only their own seat?
4. Should staff be able to waive fines in the app (today: owner on the web only)?
5. Offline check-in for weak networks? (Not designed; adds sync complexity.)

## 12. Version 2 layout (reference-style)

The owner and student **Home** now follow a dashboard pattern: coloured header (menu button, library name, shortcut icons), a swipeable banner, a **Dashboard | Insights** switch, and a 3-column grid of count tiles (icon + coloured count circle). Each tile opens the matching list.

- **Dashboard tiles (owner):** live members, total members, expired memberships, expiring in 1-7 days, today's joinings and renewals, today's / this month's / last month's collection, check-ins, due amount, pending approvals, free seats, books issued, overdue books, fines due, reservations, expenses. Endpoint: `GET /owner/dashboard`.
- **Insights:** live rate, money trend, attendance, expiring soon, member health (active, inactive 30 days, renewal rate, at-risk), 7-month collection bars, books at a glance. Endpoint: `GET /owner/insights`.
- **Side menu** (open from the menu button or the Menu tab): profile card with Library ID, library switcher, then groups: Quick access (Master, Subscription, Support), Management (Seats, Member management, Attendance, Books and lending), Reports and finance (Payments, Expenses, Reports), Administration (Staff and roles, Settings, Notifications, Profile, Log out).
- Every admin-panel module has a screen, including halls, plans, staff and roles, settings, subscription, reservations, e-books, stock-taking and support. Menu items and tiles are hidden when the person's role lacks the permission or the library lacks the module.
- **Re-skinning:** change the `--brand-*` tokens in `assets/app.css`.


## 13. Version 3 (30 Oct 2026): what is new

Everything below now exists on the web panel. The app screens mirror it; the API endpoints for them still have to be added (section 8 lists the pattern).

| Area | Screen | What it shows | Web source |
|---|---|---|---|
| Themes | `owner/theme.html` | Default, Classic, Ocean, Sunset, Forest, Royal. Applies at once; the theme belongs to the library, so staff and students see the same colours. In the prototype the choice is kept in `localStorage` (`lmsTheme`) and every screen follows it. | `App\Support\Themes`, library setting `theme` |
| Dashboard | `owner/home.html` | Gradient tiles (live, total, expired, expiring 1–3 / 4–7 / 8–15 days, collections, dues, check-ins, seats, expenses, books, fines), collections by method, quick actions. SMS and WhatsApp credit counters in the header. | `pages/app/dashboard` |
| Insights | `owner/insights.html` | Gradient tiles and coloured-edge cards: member donut, collection bars, weekly attendance, seat occupancy, operational pressure. | `pages/app/insights` |
| Seats by shift | `owner/seats.html` | Tabs Morning, Afternoon, Evening, Night, Fullday. One seat can be shared by students in different shifts; Fullday blocks all. Seat drawings: available (desk), occupied (student at laptop, name and number), reserved (tag), maintenance (cross). | `seat_allocations.shift`, `AllocateSeat($seat, $student, $shift)` |
| Seat on registration | `owner/seat-select.html` | After saving a new student (or "Allot seat" on a student without one): shift, plan, start date, seat grid, Skip or Add. Add starts the plan and gives the seat in one transaction. | `pages/app/students/form` |
| Students | `owner/students.html`, `student-detail.html`, `student-add.html` | Total, VIP, Blocked, Left, Frozen filters; registration date range; seat with shift and full seat history; Biometric / card ID field. | students index / show / form |
| Biometric devices | `owner/biometric-attendance.html` | Devices with Online / Not responding, punch log (checked in, checked out, ignored, unknown ID, not accepted), add device, one-time access token. | `BiometricController`, `/iclock/cdata`, `POST /api/biometric/punches` |
| Plans and checkout | `owner/plans-pricing.html`, `checkout.html`, `payment-success.html`, `subscription.html` | Plan slider with Library, SMS and WhatsApp tabs; checkout with coins, Razorpay or cash (proof + note); success / waiting for approval. | Razorpay order + webhook, `StartOnlinePayment`, `FulfilOnlinePayment` |
| Credits and messages | `owner/credits.html`, `message-history.html`, `message-templates.html` | Credit balances and packs; every message with Sent / Not sent and the reason; automatic triggers and templates (SMS set by the platform, WhatsApp editable). | `Messaging`, `MessageTemplates`, `message_logs` |
| Support and more | `owner/refer-earn.html`, `help.html`, `library-qr.html`, `contact.html`, `profile.html` | Referral code and coins, FAQs in English and Hindi, library QR, contact, profile and password. | referral, `HelpContent`, profile page |
| Student | `student/membership.html`, `student/attendance.html`, `student/help.html` | Seat with shift, fingerprint visits, same FAQs. | — |

### API to add for version 3
- `GET /seats?shift=morning` returning each seat's state for that shift and the holder; `POST /seats/{seat}/allocate {student_id, shift}`; `POST /seat-allocations/{id}/release`.
- `GET /biometric/devices`, `POST /biometric/devices` (returns the token once), `GET /biometric/punches?result=`.
- `GET /plans?type=library|sms|whatsapp`, `POST /checkout` (creates the Razorpay order server-side; the app opens Razorpay Checkout with the order id and posts the signature back for verification), `POST /checkout/cash` (multipart: proof, note).
- `GET /credits`, `GET /messages?channel=&status=`, `GET|PUT /message-templates`, `GET /referrals`, `GET /help?lang=en|hi`, `PUT /me`, `PUT /me/password`, `PUT /library/theme`.