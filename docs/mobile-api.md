# LMS mobile app API (v1)

The REST API behind the iOS and Android app. One API for library owners, staff and students: what each person
can do follows their role in each library, exactly as on the web panel.

- **Base URL:** `https://<your-domain>/api/v1`
- **Swagger (try every endpoint):** `https://<your-domain>/api/docs` — spec at `/api/docs/openapi.json`, generated
  from the code. The Super Admin turns it on or off in **Platform settings → Mobile app** (Automatic = on except on the live site).
- **Format:** JSON in and out (file uploads use `multipart/form-data`). Every response is JSON, even errors.
- **Dates:** `YYYY-MM-DD`. **Times:** ISO 8601 in the library's time zone. **Money:** a number in rupees (`1300.5`).
- **Postman:** import `docs/postman/LMS-mobile-api.postman_collection.json` and `…postman_environment.json`, set
  `base_url`, run **Login** (it saves the token and library slug). Regenerate after API changes with
  `php artisan api:postman --base-url=https://your-server`.
- **Code:** controllers in `app/Http/Controllers/Api/V1`, routes in `routes/api.php` and `routes/api-library.php`,
  response shapes in `app/Http/Resources/Api/V1/Present.php`, tests in `tests/Feature/Api`.

## 1. Signing in

```http
POST /auth/login
{ "email": "owner@example.com", "password": "…", "device_name": "Pixel 8",
  "push_token": "ExponentPushToken[…]", "push_provider": "expo", "platform": "android" }   // push fields optional
```

Response:

```json
{ "token": "12|abc…", "token_type": "Bearer", "expires_at": "2026-11-29T10:00:00+05:30",
  "user": { "id": 2, "name": "Demo Owner", "email": "owner@example.com", "phone": null },
  "libraries": [ { "id": 1, "slug": "demo-library", "name": "Vision Library", "code": "LIB-0001",
                   "roles": ["Library Owner"], "workspace": "staff", "theme": "default" } ] }
```

- Send `Authorization: Bearer <token>` on every other request. Keep the token in the Keychain / Keystore
  (expo-secure-store), never in plain storage.
- `workspace` tells the app which screens to open for that library: `staff` (owner, manager, receptionist…) or `student`.
- One library: open it directly. Several: show the Choose library screen.
- A token lasts **30 days** (`SANCTUM_EXPIRATION`, minutes). After that every call returns `401`; send the person to sign in.
- A token stops working at once when the person signs out, changes or resets their password, or is deactivated.
- Platform (Super Admin) accounts cannot use the app.

| Endpoint | What it does |
|---|---|
| `POST /auth/login` | Sign in (5 tries a minute per email, 10 per IP). |
| `POST /auth/forgot-password` `{email}` | Sends the reset email. Same answer whether or not the email exists. |
| `GET /auth/me` | The person and their libraries. |
| `POST /auth/logout` | Sign out this phone (also stops its push notifications). |
| `POST /auth/logout-all` | Sign out every phone. |
| `GET /auth/sessions` · `DELETE /auth/sessions/{id}` | Phones signed in; sign one out. |
| `PUT /me` `{name, phone}` | Profile. The email cannot change (it is the sign-in name). |
| `PUT /me/password` `{current_password, password, password_confirmation}` | Signs every other phone out. |
| `GET /app/info` | Public: app name, support phone / email / WhatsApp link. No token needed. |

## 2. App updates (force update)

The Super Admin sets, per platform, the **latest** and **minimum** version and the store link in
**Platform settings → Mobile app**.

- On start-up (and when the app returns to the foreground) call `GET /app/version?platform=android&version=1.3.0`
  (no token needed). `update`: `required` → show a blocking "Update now" screen that opens `store_url`;
  `optional` → a prompt the person can dismiss; `none` → carry on. `message` is the text to show.
- Send `X-App-Platform: android|ios` and `X-App-Version: 1.3.0` on **every** request. Below the minimum version the
  API answers `426` with `code: "update_required"` and the same fields, so an old app cannot keep working.
  Requests without these headers (Swagger, Postman) are not affected.
- `GET /app/info` also lists each platform's latest / minimum version and store link.

## 3. Push notifications

Every notification the web shows in the bell is also pushed to the person's phones.

1. After sign-in (or when the push token changes) call `POST /devices {token, provider, platform, device_name?, app_version?}`.
   - `provider: "expo"` with an Expo push token (`ExponentPushToken[…]`) — works with no server keys.
   - `provider: "fcm"` with a Firebase registration token — the Super Admin enters the Firebase project id and
     service-account key in **Platform settings → Mobile app** (stored encrypted).
2. `DELETE /devices {token}` turns notifications off on this phone. Signing out does it automatically.
3. The push `data` carries `notification_id`, `kind` (e.g. `membership.expiring`, `payment_submission.approved`,
   `reservation.ready`), `library_id` and `url`, so tapping can open the right screen.
4. Phones the push service reports as gone are removed automatically.

Notifications screen: `GET /notifications?library_id=&unread=1`, `GET /notifications/unread-count`,
`POST /notifications/{id}/read`, `POST /notifications/read-all`.

## 4. Inside a library

All other endpoints are under **`/libraries/{slug}/…`** (the `slug` from the login response). The server checks the
person belongs to that library and the library is active; another library's slug or record ids are `404`.

Start with `GET /libraries/{slug}`: library details, `me.permissions` (show or hide buttons with it), plan modules,
subscription state, SMS / WhatsApp credits and theme. Hiding a button is only cosmetic: **every endpoint checks the
permission again** and answers `403` if it is missing.

## 5. Errors, pages and safety

| Status | Meaning | Body |
|---|---|---|
| `401` | Not signed in, token expired or revoked | `{ "message": "Unauthenticated." }` |
| `402` | Subscription suspended / expired: only subscription, help, contact, referral, theme and the library summary work | `{ "message", "code": "subscription_restricted" }` |
| `403` | No permission, module not in plan, or library read-only | `{ "message" }` |
| `404` | Not found (or another library's record) | `{ "message": "Not found." }` |
| `409` | Possible duplicate student | `{ "message", "code": "possible_duplicate", "duplicates": [...] }` |
| `426` | App too old: update required (`code: "update_required"`, `store_url`) | `{ "message", "code", "update", "store_url", … }` |
| `422` | Form error or business rule | `{ "message", "errors": { "field": ["…"] } }` (rule errors have only `message`) |
| `429` | Too many requests | Wait and retry |

- **Lists** return `{ data: [...], meta: { page, per_page, total, last_page } }`. Use `?page=2&per_page=20` (max 50).
- **Limits:** 120 requests a minute per person; uploads 20 a minute.
- **Recording a payment:** send a unique `idempotency_key` per attempt; a retry on a bad connection returns the first
  payment instead of recording it twice.
- **Files** (receipts, invoices, reports, proofs, e-books, covers) come back as the file itself with the right
  content type; download them with the bearer token.

## 6. Endpoints by screen

`perm` is the permission needed (any one of `a|b`). Paths are relative to `/libraries/{slug}`.

### Dashboard, insights, theme
| Method & path | perm | Notes |
|---|---|---|
| `GET /` | any member | Library, my roles and permissions, modules, subscription, credits, theme |
| `GET dashboard?year=2025` | staff | Gradient tiles (each has `key, label, value, display, tone, screen`), setup checklist, expiring, new students, recent payments, collections by method, seat occupancy, overdue books, activity |
| `GET insights` | students / memberships / payments / attendance view | Member health, money, attendance week, seats, with the plain-language sentences |
| `GET theme` · `PUT theme {theme}` | change: `settings.manage` | Default, Classic, Ocean, Sunset, Forest, Royal; with each theme's colour scale |

### Students
| Method & path | perm | Notes |
|---|---|---|
| `GET students?q&status&view=vip|frozen&membership=active|none|expiring&from&until` | `students.view` | Also `counts` for Total / VIP / Blocked / Left / Frozen |
| `POST students` (multipart) | `students.create` | name, phone, email, address, emergency contact, member_type, is_vip, biometric_id, photo, `confirm_duplicate` |
| `GET students/{id}` | `students.view` | Details, current membership, seat with shift, seat history, attendance, payments, allowed actions |
| `PUT students/{id}` | `students.update` | Same fields as create |
| `POST students/{id}/photo` | `students.update` | `photo` image |
| `POST students/{id}/status {status}` | `students.deactivate` | active / suspended (blocked) / inactive (left — frees the seat) |
| `POST students/{id}/login` | `students.update` | Sends the student a setup email |
| `POST students/{id}/seat {seat_id, shift, plan_id?, starts_on?}` | `seats.allocate` | Seat selection after registering / Allot seat. Plan needed when no running membership |
| `DELETE students/{id}/seat` | `seats.allocate` | Take the seat back |
| `GET students/{id}/owing` | `payments.record|payments.view` | Memberships with a balance |

### Halls and seats (by shift)
| Method & path | perm | Notes |
|---|---|---|
| `GET halls` · `POST halls` · `PUT halls/{id}` | view `seats.view`, edit `halls.manage` | |
| `GET seats?shift=morning|afternoon|evening|night|fullday&hall_id&status&q` | `seats.view` | Each seat's `state` in that shift and its `holder`; summary All / Allotted / Available |
| `GET seats/{id}?shift=` | `seats.view` | Plus allocation history |
| `POST seats` · `PUT seats/{id}` · `POST seats/generate` | `seats.manage` | |
| `GET seats/eligible-students?q` | `seats.allocate` | |
| `POST seats/{id}/allocate {student_id, shift}` | `seats.allocate` | Same shift twice or Fullday over a shift → `422` |
| `POST seats/{id}/release {shift}` | `seats.allocate` | Frees only that shift |

### Plans and memberships
| Method & path | perm |
|---|---|
| `GET plans?active_only=1` · `POST plans` · `PUT plans/{id}` | view `memberships.view`, edit `plans.manage` |
| `GET memberships?q&state=active|upcoming|expired|expiring|suspended|cancelled&plan_id&student_id` | `memberships.view` |
| `POST memberships {student_id, plan_id, starts_on, discount?, notes?, renewal_request_id?}` | `memberships.manage` (discount needs `memberships.discount`) |
| `POST memberships/{id}/renew` · `PUT memberships/{id} {status, notes}` | `memberships.manage` |
| `GET renewal-requests` · `POST renewal-requests/{id}/dismiss` | `memberships.manage` |

### Money
| Method & path | perm |
|---|---|
| `GET payments?q&method&status&from&until&student_id` (with today / month / dues summary) | `payments.view` |
| `POST payments {student_id, membership_id?, amount, method, paid_on, reference?, notes?, idempotency_key}` | `payments.record` |
| `GET payments/{id}` · `GET payments/{id}/receipt` (PDF) | `payments.view` · `receipts.print` |
| `GET payments/{id}/refund-options` · `POST payments/{id}/refund` · `POST payments/{id}/reverse {reason}` | `payments.refund` |
| `GET dues?q&filter=overdue` | `payments.view` |
| `GET payment-submissions?status` · `POST …/{id}/approve` · `POST …/{id}/reject {reason}` · `GET …/{id}/proof` | `payments.record` |
| `GET expenses` · `POST expenses` · `POST expenses/{id}` (multipart, `attachment`) · `GET expenses/{id}/attachment` · `POST expense-categories` | `expenses.view` / `expenses.manage` (Expenses module) |
| `GET reports` · `GET reports/{key}?from&until&status` · `GET reports/{key}/export/{csv|xlsx|pdf}` | report permission; export also needs the export permission and the Data exports module |

### Attendance and biometric
| Method & path | perm |
|---|---|
| `GET attendance/today` · `GET attendance?date&q&student_id&source` | `attendance.view` |
| `GET attendance/candidates?q` · `POST attendance/check-in {student_id}` · `POST attendance/{id}/check-out` | `attendance.record` |
| `POST attendance/{id}/correct {checked_in_at, checked_out_at?, reason}` | `attendance.correct` |
| `POST attendance/scan {token, mode=auto|lookup}` | Scanner: the student's digital ID QR. `auto` checks in, or out if inside |
| `GET biometric/devices` · `GET biometric/punches?result&device_id` | `attendance.view` |
| `POST biometric/devices` · `PUT …/{id}` · `POST …/{id}/token` · `DELETE …/{id}` | `attendance.correct` (the access token is returned once) |

### Books (Books / E-books modules)
| Method & path | perm |
|---|---|
| `GET books?q&type&category_id` · `GET books/{id}` · `GET books/{id}/cover` | `books.view|ebooks.view` |
| `GET books/lookup?code=` (scanned barcode) | `books.view|circulation.view` |
| `GET circulation/members/{student}` | can they borrow, open loans, ready reservations, unpaid fines |
| `POST circulation/issue {student_id, barcodes[]}` | `circulation.issue` |
| `POST circulation/return {returns:[{barcode|item, condition, fee?}]}` | `circulation.return` (lost / damaged: `circulation.manage`) |
| `GET loans?status=open|overdue|returned` · `POST loans/{id}/renew` | `circulation.view` · `circulation.renew` |
| `GET reservations` · `POST reservations` · `POST reservations/{id}/cancel` | `circulation.view` · `circulation.issue` |
| `GET fines?status` · `POST fines/{id}/collect {method}` · `POST fines/{id}/waive {reason, amount?}` | `fines.view` · `fines.collect` · `fines.waive` |
| `GET ebooks/{id}/open?action=view|download` | staff `ebooks.view`; students `ebooks.read` |
| `GET stock-takes` · `POST stock-takes` · `POST …/{id}/scan {code}` · `…/complete` · `…/cancel` | `books.audit` |

### Subscription, credits, support
| Method & path | perm |
|---|---|
| `GET subscription` | `billing.view` — plan, usage, modules, credits, coins, payments, invoices, pay options |
| `GET subscription/plans?type=library|sms|whatsapp` · `POST subscription/quote {plan_id, cycle, use_coins}` | `billing.view` |
| `POST subscription/checkout/online {plan_id, cycle, use_coins}` → Razorpay options | `billing.manage` |
| `POST subscription/credits/online {channel, credits}` → Razorpay options | `billing.manage` |
| `POST subscription/checkout/verify {razorpay_payment_id, razorpay_order_id, razorpay_signature}` | `billing.manage` |
| `POST subscription/checkout/cash` · `POST subscription/credits/cash` (multipart: method, reference, paid_on, note, `proofs[]`) | `billing.manage` |
| `GET subscription/invoices/{id}` (PDF) | `billing.view` |
| `GET messages/counters` · `GET messages?channel&status` | `billing.view` |
| `GET message-templates` · `PUT message-templates/automatic {event, channel, enabled}` · WhatsApp template CRUD | view `billing.view`, edit `settings.manage` |
| `GET refer` · `GET help?lang=en|hi` · `POST help/requests {subject, body}` · `GET contact` · `GET qr?source=login|website|referral|custom&text` | |
| `GET staff` · `POST staff` · `PUT staff/{id}` · `POST staff/{id}/access {active}` · `POST staff/{id}/reset-access` | `staff.manage` |
| `GET settings` · `POST settings/profile` (multipart, `logo`) · `PUT settings/general` | `settings.manage` |

**Razorpay in the app:** call the `…/online` endpoint, open Razorpay Checkout (`react-native-razorpay`) with the returned
`razorpay` options, then send Razorpay's success answer to `checkout/verify`. `paid` = done; `processing` (`202`) =
show "we will activate it shortly" (the webhook finishes it); `failed` / `unverified` = show the message. Never mark
anything paid on the phone.

### The student app (`/libraries/{slug}/my/…`, permission `self.view`)
Always the signed-in student's own records; no endpoint takes a student id.

| Method & path | Notes |
|---|---|
| `GET my` | Home: membership, seat with shift, dues, today's visit, books, enabled features |
| `GET my/id` | Digital ID: `token` valid 60 s (show it as a QR, refresh before `expires_at`) |
| `GET my/memberships` · `POST my/renewal-request {plan_id?, message?}` | One waiting request at a time |
| `GET my/attendance?month=2026-10` | Visits, days present, fingerprint visits |
| `GET my/payments` · `GET my/payments/{id}/receipt` (PDF) | |
| `POST my/payment-submissions` (multipart: membership_id, amount, method, reference, paid_on, note, proof) | "I have paid" — only when the library enabled it; not over the amount due; max 3 waiting; a reference once |
| `GET my/books` · `GET my/books/search?q` · `POST my/reservations {book_id}` · `POST my/reservations/{id}/cancel` · `POST my/loans/{id}/renew` | |
| `GET my/ebooks?q` → open with `GET ebooks/{id}/open` | |

## 7. Security summary

- Bearer tokens (Laravel Sanctum), stored hashed on the server, 30-day expiry, deleted on sign-out, password change,
  password reset and deactivation; expired ones are pruned daily.
- Cookie-based (stateful) API access is off; CSRF does not apply to token requests.
- Sign-in, password reset and uploads are rate-limited; all other calls 120 a minute per person.
- Every library endpoint is scoped to the library in the URL and the person's membership of it; every action checks
  its permission and the plan's modules; read-only (expired) libraries refuse changes.
- Uploads are type- and size-checked; proofs, receipts and expense files live on the private disk.
- Digital ID QR codes are HMAC-signed with the app key, bound to one library and expire after 60 seconds.
- Razorpay orders are created server-side and verified (signature + payment fetched from Razorpay) before anything is granted.
- Push keys and the Razorpay secrets are stored encrypted. Responses send `Cache-Control: no-store`.
