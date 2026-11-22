# PADEL BOOKING PLATFORM — MASTER SYSTEM SPECIFICATION

> **Purpose:** Single source of truth for AI coding agents and human developers.  
> **Document version:** 1.0.0  
> **Created:** 2026-11-22  
> **Status:** Baseline specification — implementation must not silently invent unresolved business rules.  
> **Primary language:** Indonesian for product-facing UI; English identifiers for code, database, API, and technical documentation.

---

# 0. READ THIS FIRST — RULES FOR AI CODING AGENTS

This document is the authoritative product and engineering specification for the Padel Booking Platform.

## 0.1 Source-of-truth hierarchy

When instructions conflict, use this order:

1. Explicit, latest instruction from the project owner.
2. This master specification, including any approved decision recorded in `DECISIONS` or `OPEN DECISIONS`.
3. Existing code and tests, but only when they do not conflict with items 1–2.
4. Framework defaults and common conventions.

Never treat assumptions, generated examples, comments, or AI suggestions as approved business requirements.

## 0.2 Anti-AI-slop rules — mandatory

The agent MUST:

- Read this entire file before proposing or changing architecture.
- Inspect the repository, current branch, package versions, existing conventions, and current implementation before editing.
- Reuse existing components, utilities, naming conventions, and patterns where appropriate. Do not rebuild working features without a reason.
- Make the smallest complete change that satisfies the requirement.
- Implement real functionality. Do not leave fake buttons, dead links, placeholder dashboards, hardcoded success states, mock payment confirmations, or non-functional CRUD.
- Never fabricate data, API responses, credentials, payment status, business rules, or completed work.
- Never silently choose a business rule where this document says `OPEN DECISION`. Ask the project owner or implement a clearly isolated configurable default only after approval.
- Never put business logic in Vue templates or controllers when it belongs in a domain service.
- Never trust frontend validation as a security or booking-integrity control. Validate again on the server and enforce critical invariants in PostgreSQL.
- Never mark a booking as paid because the browser returned from a payment page. Payment must be confirmed by a verified provider webhook or an authorized manual verification.
- Never claim that double booking is prevented unless database-level concurrency behavior is implemented and tested.
- Never use floating-point types for money.
- Never delete transaction records to "clean up" data. Use status transitions, retention policy, and audit history.
- Never expose secrets, payment credentials, private customer data, stack traces, or internal IDs unnecessarily.
- Never add dependencies without checking whether the project already has an equivalent dependency and explaining why the new dependency is needed.
- Never add a large abstraction, generic framework, microservice, or design pattern without a concrete requirement.
- Never change unrelated files or reformat the entire project for a narrow task.
- Never suppress errors to make a flow appear successful.
- Never write tests that merely repeat implementation details. Test observable behavior and invariants.
- Never say a task is complete unless implementation, relevant tests, and a final diff review have been performed.

The agent MUST NOT:

- Generate generic lorem ipsum content in production UI.
- Use random gradients, excessive glassmorphism, arbitrary animations, emoji as interface icons, or inconsistent spacing/color values.
- Add a dashboard chart that has no real data source.
- Add "Coming soon" controls unless explicitly requested.
- Duplicate business logic across frontend and backend.
- create a second source of truth for booking availability or payment status.
- invent environment variables, external services, routes, or database tables without documenting them.
- commit generated credentials, `.env`, production data, or uploaded customer documents.

## 0.3 Required workflow for every task

Before coding:

1. Summarize the requested outcome in one or two sentences.
2. Inspect relevant files and identify the actual implementation points.
3. List assumptions and identify any unresolved decision that blocks safe implementation.
4. State the files likely to change.
5. Define acceptance criteria and tests.

During coding:

1. Implement in small, coherent steps.
2. Keep frontend, backend, and database contracts aligned.
3. Add or update migrations and tests with the feature.
4. Handle loading, empty, error, success, and permission-denied states.
5. Preserve backward compatibility unless a breaking change is approved.

After coding:

1. Run relevant formatter, lint, static analysis, and tests available in the repository.
2. Inspect `git diff` and ensure no unrelated changes or secrets.
3. Report what changed, what tests ran, test results, known limitations, and any required manual setup.
4. If a command could not be run, say so explicitly. Never fabricate test results.

## 0.4 Definition of done

A feature is not done until:

- It satisfies the stated acceptance criteria.
- Authorization is enforced server-side.
- Inputs are validated server-side.
- Database constraints and transaction behavior are correct.
- Loading, empty, error, and success states are handled.
- Relevant automated tests pass.
- No fake functionality or placeholder behavior remains.
- Documentation and environment examples are updated where necessary.
- The final diff has been reviewed.

---

# 1. PRODUCT OVERVIEW

## 1.1 Product name

Working name: **Padel Booking Platform**.  
The final public brand name is an owner decision and must be configurable.

## 1.2 Product objective

A responsive web application that lets customers discover padel venues, check court availability, reserve a court, pay, and receive booking confirmation. Venue operators use an administration system to manage courts, schedules, prices, bookings, customers, payments, and reports.

## 1.3 Product principles

- Booking must be reliable before it is visually elaborate.
- Availability shown to users must be derived from the same backend rules used when confirming a booking.
- Payments must be auditable and reconciled.
- Venue operations must be configurable without code changes for normal schedule and price changes.
- Customer and staff workflows must be usable on mobile.
- The initial architecture must be simple to operate and capable of multi-venue growth.
- Do not build marketplace complexity until the business model requires it.

## 1.4 Initial business model

**Baseline architecture:** one operator can own multiple venues, and each venue can have multiple courts.

This is not automatically a public marketplace. A marketplace with independent venue owners, commissions, payouts, and onboarding is a separate product scope.

## 1.5 Scope boundaries

### MVP — required

- Public website.
- Venue and court listing/detail.
- Availability search.
- Booking creation and temporary hold.
- Customer contact verification or authenticated account.
- Price quotation.
- Payment gateway integration through a provider adapter.
- Manual transfer proof and staff verification.
- Booking status lifecycle.
- Admin dashboard.
- Venue, court, operating hour, pricing, schedule-block management.
- Customer records and booking history.
- Email notification integration.
- Basic revenue and utilization reports.
- Audit log for sensitive staff actions.

### Later releases — not MVP unless owner approves

- Membership wallet and prepaid hours.
- Loyalty points and referrals.
- Tournament management.
- POS for cafe and merchandise.
- Automated access gate integration.
- Native iOS/Android application.
- Marketplace for independent venue operators.
- Dynamic pricing based on demand.
- Complex accounting/general ledger.

---

# 2. OPEN DECISIONS — DO NOT GUESS

The following values have not yet been confirmed by the owner. Keep them configurable and do not hardcode them as permanent business policy.

| ID | Decision | Suggested starting point | Status |
|---|---|---|---|
| OD-001 | Number of venues/courts | Seed one demo venue; support many | Open |
| OD-002 | Operating hours | Configurable per venue/day | Open |
| OD-003 | Booking duration | 60 minutes initially, configurable | Open |
| OD-004 | Slot interval | 30 minutes initially, configurable | Open |
| OD-005 | Buffer between sessions | 0 minutes unless owner sets it | Open |
| OD-006 | Payment hold duration | 10 minutes, configurable | Open |
| OD-007 | Payment model | Full payment initially; support deposit later | Open |
| OD-008 | Cancellation cutoff and fee | Must be approved by owner | Open |
| OD-009 | Refund policy | Must be approved by owner/provider | Open |
| OD-010 | Reschedule policy | Must be approved by owner | Open |
| OD-011 | Customer login | Guest booking with verified contact or account | Open |
| OD-012 | Payment provider | Midtrans or Xendit; choose one for first integration | Open |
| OD-013 | WhatsApp provider | Official WhatsApp Business API provider | Open |
| OD-014 | Time zone | Per venue; likely `Asia/Makassar` for Makassar | Open |
| OD-015 | Public brand, logo, domain | Supplied by owner | Open |
| OD-016 | Tax/service charge | Configure only after accounting policy is confirmed | Open |
| OD-017 | Booking made by phone/walk-in | Staff-assisted booking supported | Open |
| OD-018 | Court assignment | Customer chooses a court or "any available" | Open |

**Rule:** If an open decision blocks a feature, stop and ask a concise question. Do not bury an arbitrary choice in code.

---

# 3. USERS, ROLES, AND PERMISSIONS

## 3.1 Roles

| Role | Scope | Description |
|---|---|---|
| `super_admin` | All organizations | Full system administration |
| `org_admin` | Assigned organization | Manages organization and all its venues |
| `venue_admin` | Assigned venue(s) | Manages venue operations |
| `booking_staff` | Assigned venue(s) | Creates and manages bookings |
| `finance_staff` | Assigned venue(s) | Verifies payments and handles refund workflow |
| `operations_staff` | Assigned venue(s) | Daily schedule and check-in |
| `customer` | Own account/data | Customer booking and profile |
| `guest` | Public | Browse and start guest checkout |

## 3.2 Authorization rules

- Every admin endpoint must enforce both permission and organization/venue scope.
- A venue-scoped staff member must never access another venue by changing an ID in the URL.
- Customers can only access their own bookings and payment records.
- Public booking lookup must use an unguessable token or verified contact flow; do not expose booking records by sequential IDs.
- Finance-only operations must not be granted to booking staff by default.
- Only authorized roles can change booking status, verify manual payment, or approve refunds.
- All privileged changes must be written to `audit_logs`.

## 3.3 Permission examples

- `venue.view`, `venue.manage`
- `court.view`, `court.manage`
- `schedule.view`, `schedule.manage`
- `booking.view`, `booking.create`, `booking.update`, `booking.cancel`
- `payment.view`, `payment.verify`, `payment.refund`
- `pricing.view`, `pricing.manage`
- `customer.view`, `customer.manage`
- `report.view`, `report.export`
- `user.manage`, `role.manage`
- `audit.view`, `settings.manage`

---

# 4. DOMAIN MODEL AND DATABASE CONTRACT

PostgreSQL is the authoritative store for transactional state.

## 4.1 Core entities

- Organization
- Venue
- Court
- User
- Role and permission
- Operating hours
- Special date / closure
- Pricing rule
- Court block
- Booking
- Booking item
- Payment
- Payment webhook event
- Refund
- Promotion and redemption
- Notification
- Booking status history
- Audit log

## 4.2 Database conventions

- Use UUID primary keys for business entities.
- Use `timestamptz` for instants in time.
- Store venue IANA timezone, e.g. `Asia/Makassar`.
- Use `numeric(14,2)` for IDR amounts. Never use `float`, `real`, or `double precision` for currency.
- Use `char(3)` or a validated `varchar(3)` currency code; default `IDR`.
- Use `created_at`, `updated_at`; add `deleted_at` only for entities where soft deletion is safe.
- Prefer deactivation over deletion for venues, courts, users, pricing rules, and roles already referenced by transactions.
- Add foreign keys and explicit delete behavior.
- Avoid polymorphic relations for core financial records unless there is a demonstrated need.
- Use immutable snapshots for booked price, customer contact, and booking time.
- Use database transactions for all multi-record state changes.
- Do not rely on application-level checks alone for uniqueness and overlap constraints.

## 4.3 Recommended tables

### Identity and tenancy

| Table | Important fields |
|---|---|
| `organizations` | `id`, `name`, `slug`, `status`, timestamps |
| `venues` | `id`, `organization_id`, `name`, `slug`, `address`, `city`, `timezone`, `phone`, `status` |
| `users` | `id`, `name`, `email`, `phone`, `password`, verification timestamps, `is_active` |
| `roles` | `id`, `name`, `guard_name` |
| `permissions` | `id`, `name`, `guard_name` |
| `role_permissions` | `role_id`, `permission_id` |
| `user_roles` | `user_id`, `role_id`, optional `organization_id` / `venue_id` scope |
| `user_venue_access` | `user_id`, `venue_id`, `role_id` |

### Venue operations

| Table | Important fields |
|---|---|
| `courts` | `id`, `venue_id`, `name`, `code`, `court_type`, `surface_type`, `status` |
| `court_images` | `id`, `court_id`, `storage_key`, `alt_text`, `sort_order` |
| `venue_images` | `id`, `venue_id`, `storage_key`, `alt_text`, `sort_order` |
| `operating_hours` | `id`, `venue_id`, `day_of_week`, `open_time`, `close_time`, `is_closed` |
| `special_dates` | `id`, `venue_id`, `date`, `is_closed`, optional open/close override, `note` |
| `court_blocks` | `id`, `court_id`, `start_at`, `end_at`, `reason`, `status`, `created_by` |
| `pricing_rules` | `id`, `venue_id`, optional `court_id`, day/time/date conditions, `price_per_hour`, `priority`, `is_active` |

### Booking and finance

| Table | Important fields |
|---|---|
| `bookings` | `id`, `booking_code`, `public_token_hash`, `user_id` nullable, `venue_id`, customer snapshots, `status`, `subtotal`, `discount_amount`, `tax_amount`, `total_amount`, `currency`, `hold_expires_at`, `created_by` |
| `booking_items` | `id`, `booking_id`, `court_id`, `start_at`, `end_at`, `duration_minutes`, `unit_price`, `total_price`, pricing snapshot |
| `booking_status_histories` | `id`, `booking_id`, `from_status`, `to_status`, `reason`, `actor_user_id`, `created_at` |
| `payments` | `id`, `booking_id`, `provider`, `provider_reference`, `payment_method`, `amount`, `status`, `paid_at`, `idempotency_key`, `verified_by` |
| `payment_webhook_events` | `id`, `provider`, `event_key`, `provider_reference`, `payload_json`, `signature_valid`, `processed_at`, `processing_status` |
| `refunds` | `id`, `payment_id`, `amount`, `reason`, `provider_reference`, `status`, `requested_by`, `approved_by`, `processed_at` |

### Promotion, communication, and audit

| Table | Important fields |
|---|---|
| `promotions` | `id`, `code`, `name`, `discount_type`, `discount_value`, `starts_at`, `ends_at`, `usage_limit`, `per_customer_limit`, `is_active` |
| `promotion_redemptions` | `id`, `promotion_id`, `booking_id`, `user_id`, `discount_amount` |
| `notifications` | `id`, `user_id` nullable, `booking_id` nullable, `channel`, `recipient`, `template_key`, `status`, `sent_at`, `error_message` |
| `audit_logs` | `id`, `actor_user_id`, `organization_id`, `venue_id`, `action`, `entity_type`, `entity_id`, `before_json`, `after_json`, `ip_address`, `created_at` |

## 4.4 Required database constraints and indexes

At minimum:

- Unique `organizations.slug`.
- Unique `(organization_id, venues.slug)` or globally unique venue slug — choose one convention.
- Unique `(venue_id, courts.code)`.
- Unique `bookings.booking_code`.
- Unique `payments.provider_reference` when provider reference is not null.
- Unique `payment_webhook_events(provider, event_key)`.
- Check `end_at > start_at`.
- Check monetary amounts are non-negative.
- Check duration is positive.
- Index `bookings(venue_id, status, created_at)`.
- Index `bookings(venue_id, start_at)`.
- Index `booking_items(court_id, start_at, end_at)`.
- Index `payments(booking_id, status)`.
- Index `court_blocks(court_id, start_at, end_at)`.

## 4.5 Preventing overlapping bookings — mandatory

Use PostgreSQL range types and an exclusion constraint if the final schema supports it. This is the preferred database-level guard.

Illustrative PostgreSQL pattern (adapt names and lifecycle rules to the actual migration):

```sql
CREATE EXTENSION IF NOT EXISTS btree_gist;

-- A generated range can be used in a dedicated reservation table,
-- or an equivalent expression/index can be implemented after validating
-- the final schema and status lifecycle.
ALTER TABLE booking_items
ADD CONSTRAINT booking_item_valid_time
CHECK (end_at > start_at);
```

A robust implementation can use a separate `court_reservations` table containing:
- `id`
- `court_id`
- `booking_item_id` nullable for blocks if using a unified reservation model
- `start_at`
- `end_at`
- `status`
- `hold_expires_at`

Then use a PostgreSQL exclusion constraint on `court_id` and `tstzrange(start_at, end_at, '[)')` for active reservations. Partial exclusion constraints must account for expired holds and active states correctly. PostgreSQL partial constraints cannot directly use `now()` in their predicate. Therefore, expiry must be materialized by a job/status transition, and the constraint predicate should depend on stable status values.

**Important implementation decision:** choose one authoritative conflict model:
1. `court_reservations` as the sole source for court occupancy, linked to booking items; or
2. `booking_items` plus `court_blocks` with a transactional lock and explicit conflict checks.

Do not implement two competing availability sources. Prefer option 1 when using PostgreSQL exclusion constraints.

Use half-open intervals `[start_at, end_at)` so a booking ending at 10:00 does not conflict with another starting at 10:00, unless a configured buffer is applied.

## 4.6 Booking and reservation lifecycle

Suggested booking statuses:

- `pending_payment`
- `confirmed`
- `checked_in`
- `completed`
- `cancelled`
- `expired`
- `no_show`
- `refund_pending`
- `refunded`

Suggested payment statuses:

- `unpaid`
- `pending`
- `paid`
- `failed`
- `expired`
- `refund_pending`
- `partially_refunded`
- `refunded`

Do not allow arbitrary status changes. Implement a transition map in the backend domain layer and test each permitted and forbidden transition.

## 4.7 Snapshot rules

At booking creation, persist:
- Customer name, phone, and email used for this booking.
- Court name/code snapshot if operational reporting requires historical display.
- Start/end timestamps and timezone context.
- Unit price and pricing rule description/identifier.
- Subtotal, discount, tax/service fee if applicable, total, currency.
- Promotion code and applied discount details.
- Cancellation policy version or snapshot.

Changing a customer profile or current price must not rewrite historical booking data.

---

# 5. BOOKING DOMAIN RULES

## 5.1 Availability calculation

Availability must be calculated by the backend from:

1. Venue operating hours.
2. Special-date overrides and closures.
3. Court active/maintenance status.
4. Active court reservations.
5. Court blocks.
6. Booking duration and slot interval.
7. Configured buffer.
8. Pricing rules.

The frontend may cache a displayed availability response briefly, but must never treat it as a guarantee that a slot can still be booked.

## 5.2 Conflict predicate

For two half-open intervals, a conflict exists when:

```sql
existing.start_at < requested.end_at
AND existing.end_at > requested.start_at
```

The final database reservation mechanism must enforce this under concurrent requests.

## 5.3 Hold workflow

1. Customer selects a slot.
2. Backend validates it.
3. Backend creates a pending booking and active reservation in one database transaction.
4. Hold expiry is set using the configured duration.
5. Payment instruction is created.
6. If payment succeeds before expiry, booking is confirmed and reservation remains active.
7. If payment expires/fails or hold expires, booking is transitioned to `expired` and reservation is released.
8. Expiry job must be idempotent and safe to retry.

Do not release a reservation merely because a browser session closes.

## 5.4 Race conditions

Handle at least:
- Two customers request the same court/time simultaneously.
- Staff creates a manual booking while online checkout is running.
- Court is blocked while a customer is checking out.
- Payment webhook arrives at the same time as hold expiry.
- Duplicate or out-of-order webhooks arrive.
- Payment succeeds after a hold has expired.

The late-payment case requires a defined business policy. Do not automatically confirm a now-conflicting booking. Flag for reconciliation/refund/manual resolution.

## 5.5 Price calculation

Backend owns the price calculation. Frontend may request a quote but may not submit a trusted total.

Price quote inputs:
- Venue.
- Court (if chosen).
- Start and end.
- Customer/member eligibility if applicable.
- Promotion code.

Price quote output:
- Line items.
- Applied pricing rule.
- Subtotal.
- Discount.
- Tax/service charge, if approved.
- Total.
- Currency.
- Quote expiry/version if necessary.

At booking creation, recalculate and snapshot the final amount in the same transaction that creates the booking/hold.

---

# 6. PAYMENT DOMAIN RULES

## 6.1 Provider abstraction

Create a backend interface such as:

```text
PaymentGateway
├── createPayment()
├── verifyWebhook()
├── queryPaymentStatus()
├── requestRefund()
└── normalizeProviderStatus()
```

Provider-specific SDK and payload handling belong in provider adapters, not in booking controllers.

## 6.2 Payment confirmation

A payment is considered paid only when:
- A verified webhook indicates a successful settled/captured payment according to provider semantics; or
- An authorized finance user verifies a manual transfer.

Browser redirect is informational only.

## 6.3 Idempotency

- Every payment creation request must use an idempotency key.
- Every webhook event must have a provider event key or a deterministic deduplication key.
- Processing the same event twice must not create a second payment or duplicate status history.
- Store webhook processing result and failure reason.
- Provide a reconciliation process to query provider status for unresolved transactions.

## 6.4 Refunds

- Refund policy must be approved by owner.
- Refund requests must be recorded separately from payments.
- Approval and execution permissions must be distinct where practical.
- Record requested amount, approved amount, provider reference, status, timestamps, and actors.
- Never delete or overwrite the original payment record.

---

# 7. API CONTRACT

Use REST API prefix `/api/v1`. Return consistent JSON and HTTP status codes. Use request validation objects and response resources/DTOs.

## 7.1 Public endpoints

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/venues` | Public venue listing |
| GET | `/venues/{slug}` | Venue details |
| GET | `/venues/{venue}/courts` | Court listing |
| GET | `/availability` | Availability query |
| POST | `/price-quotes` | Server-side price quote |
| POST | `/booking-holds` | Create temporary booking hold |
| POST | `/bookings` | Create booking if separate from hold workflow |
| GET | `/booking-status/{publicToken}` | Safe public booking status |
| POST | `/payments` | Create payment instruction |
| POST | `/payment-proofs` | Upload manual transfer proof |
| POST | `/promotions/validate` | Validate promo code |

Avoid duplicate endpoints that perform the same operation. The implementation should choose either a hold-first or booking-first flow and document it.

## 7.2 Customer endpoints

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/me` | Current customer profile |
| PATCH | `/me` | Update profile |
| GET | `/me/bookings` | Own booking history |
| GET | `/me/bookings/{id}` | Own booking detail |
| POST | `/me/bookings/{id}/cancel` | Request cancellation |
| POST | `/me/bookings/{id}/reschedule` | Request reschedule |
| GET | `/me/payments` | Own payment history |

## 7.3 Admin endpoints

| Method | Endpoint | Purpose |
|---|---|---|
| GET/POST | `/admin/venues` | List/create venue |
| GET/PATCH | `/admin/venues/{id}` | View/update venue |
| GET/POST | `/admin/courts` | List/create court |
| GET/PATCH | `/admin/courts/{id}` | View/update court |
| GET/POST | `/admin/operating-hours` | Manage hours |
| GET/POST | `/admin/pricing-rules` | Manage prices |
| GET/POST | `/admin/court-blocks` | Manage schedule blocks |
| GET/POST | `/admin/bookings` | List/create staff booking |
| GET | `/admin/bookings/{id}` | Booking details |
| POST | `/admin/bookings/{id}/cancel` | Staff cancellation |
| POST | `/admin/payments/{id}/verify` | Verify manual payment |
| POST | `/admin/refunds` | Create refund request |
| GET | `/admin/reports/revenue` | Revenue report |
| GET | `/admin/reports/utilization` | Court utilization |

## 7.4 API response conventions

Success:

```json
{
  "data": {},
  "meta": {}
}
```

Validation error:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "start_at": ["The selected time is not available."]
  }
}
```

Do not leak SQL, stack traces, secrets, internal exception details, or payment-provider credentials in API errors.

## 7.5 API requirements

- Validate all inputs server-side.
- Paginate list endpoints.
- Use consistent filtering and sorting.
- Apply authorization policies.
- Add rate limits to public endpoints.
- Use idempotency for booking/payment mutations where appropriate.
- Document request/response schemas and error codes.
- Use API versioning for breaking changes.

---

# 8. FRONTEND SYSTEM DESIGN

## 8.1 Recommended stack

- Vue 3.
- Vite.
- TypeScript.
- Tailwind CSS.
- Vue Router.
- Pinia only for shared client state that genuinely needs it.
- A typed API client layer.
- Form validation library only if the repository does not already have a suitable approach.

Do not add a state-management library for every local form. Do not store authoritative booking/payment state in local storage.

## 8.2 Frontend areas

```text
src/
├── app/
│   ├── router/
│   ├── providers/
│   └── config/
├── assets/
├── components/
│   ├── ui/
│   ├── booking/
│   ├── venue/
│   └── admin/
├── layouts/
│   ├── PublicLayout.vue
│   ├── CustomerLayout.vue
│   └── AdminLayout.vue
├── features/
│   ├── auth/
│   ├── venues/
│   ├── courts/
│   ├── availability/
│   ├── booking/
│   ├── payments/
│   ├── customers/
│   ├── pricing/
│   ├── reports/
│   └── settings/
├── services/
├── stores/
├── types/
├── utils/
├── App.vue
└── main.ts
```

Feature folders should contain their own components, API calls, types, and tests when that makes ownership clearer. Do not create empty folders merely to match this diagram.

## 8.3 Required pages

### Public

- Home.
- Venue listing.
- Venue detail.
- Court detail.
- Booking flow.
- Checkout/payment instructions.
- Booking result/status.
- FAQ and policy pages.

### Customer

- Profile.
- Booking history.
- Booking detail.
- Payment history.

### Admin

- Overview dashboard.
- Booking calendar/list/detail.
- Manual booking.
- Venue and court management.
- Operating hours and special dates.
- Court blocks and maintenance.
- Pricing rules.
- Payment verification and refunds.
- Customer listing.
- Reports.
- User/role management.
- Audit logs and settings.

## 8.4 UI states

Every data-driven page must handle:
- Initial loading.
- Refreshing/loading more.
- Empty state.
- Validation error.
- Network/server error.
- Permission denied.
- Success.
- Stale data where relevant.

No control may look interactive unless it performs a real action. Disable or hide actions the user is not authorized to perform.

## 8.5 Design system

Use design tokens, not ad-hoc values.

Suggested initial direction (owner can approve or replace):
- Visual character: sporty, modern, clean, premium.
- Primary surface: neutral/light.
- Primary action: one consistent brand color.
- Accent: limited to meaningful highlights.
- Typography: one readable sans-serif family with clear scale.
- Spacing: consistent 4px-based scale.
- Radius: consistent small/medium/large tokens.
- Shadows: restrained and functional.
- Icons: one consistent icon library.
- Motion: subtle, short, and never required to understand state.

Example token categories:

```text
color.brand
color.brand-foreground
color.background
color.surface
color.surface-muted
color.text
color.text-muted
color.border
color.success
color.warning
color.danger

space.1 ... space.12
radius.sm / md / lg / full
shadow.sm / md
font.body / heading
```

The exact color values, font, logo, and visual identity remain owner decisions. Do not invent a brand identity and present it as approved.

## 8.6 Booking UX rules

- Show venue timezone and local date/time clearly.
- Show the selected court, date, start, end, duration, and total price before payment.
- Clearly distinguish available, selected, temporarily held, booked, and unavailable slots.
- Refresh availability before creating a hold.
- Explain when a hold/payment timer is running.
- Do not display a success page until backend status confirms the result.
- If the slot becomes unavailable, preserve entered customer data where safe and guide the user to select another slot.
- Do not rely on color alone for status.
- Mobile booking must not require horizontal scrolling for the main workflow.

---

# 9. BACKEND ARCHITECTURE

## 9.1 Recommended approach

Use a **modular monolith** in Laravel. Do not start with microservices.

Suggested domain modules:
- Identity & Access.
- Organization & Venue.
- Court Operations.
- Availability.
- Pricing.
- Booking.
- Payment.
- Promotion.
- Customer.
- Notification.
- Reporting.
- Audit.

## 9.2 Layer responsibilities

- **Controller:** HTTP boundary only; parse request and return response.
- **Form Request:** validate and authorize request shape.
- **Service/Action:** execute use case and transaction orchestration.
- **Domain rule/policy:** enforce state transitions and business invariants.
- **Repository:** add only when it provides real value; do not create generic repositories around every Eloquent model.
- **Model:** relationships, casts, simple scopes, and persistence behavior.
- **Job:** asynchronous work.
- **Event:** meaningful domain event, not a replacement for direct control flow where ordering matters.
- **Resource/DTO:** explicit API response contract.

## 9.3 Suggested backend folders

```text
app/
├── Domain/
│   ├── Booking/
│   ├── Availability/
│   ├── Pricing/
│   ├── Payment/
│   ├── Venue/
│   └── Identity/
├── Http/
│   ├── Controllers/Api/V1/
│   ├── Middleware/
│   ├── Requests/
│   └── Resources/
├── Jobs/
├── Notifications/
├── Policies/
└── Support/
```

This is a suggested organization, not a reason to restructure an existing working Laravel application. Inspect the repository first.

## 9.4 Transaction boundary

Booking creation, reservation creation, final price snapshot, and initial payment intent records must be coordinated carefully. Use database transactions. External payment API calls should not be held inside a long-running database transaction. A safe pattern is:

1. Validate and create local pending booking/reservation atomically.
2. Commit.
3. Request payment instruction from provider.
4. Persist provider response.
5. If provider call fails, keep a recoverable local state and provide retry/expiry handling.
6. Process webhook idempotently.

---

# 10. NOTIFICATIONS AND SCHEDULED JOBS

## 10.1 Notification events

- Booking created.
- Payment instructions generated.
- Payment successful.
- Payment failed/expired.
- Booking confirmed.
- Booking cancelled.
- Refund status updated.
- Session reminder.
- Booking changed/rescheduled.

## 10.2 Channels

MVP:
- Email.

Optional after provider selection:
- WhatsApp Business API.

Do not use unofficial WhatsApp automation or personal-session scraping for a production business system.

## 10.3 Scheduled/background jobs

| Job | Responsibility |
|---|---|
| `ExpireBookingHolds` | Expire holds that passed `hold_expires_at` |
| `ReconcilePendingPayments` | Query provider for unresolved payments |
| `SendBookingNotifications` | Send queued booking messages |
| `SendSessionReminders` | Remind customers before sessions |
| `ProcessRefundUpdates` | Reconcile refund state |
| `GenerateDailyReports` | Produce scheduled report data |

Jobs must be idempotent and retry-safe. Log failure context without exposing sensitive data.

---

# 11. SECURITY, PRIVACY, AND COMPLIANCE

- HTTPS only.
- Password hashing through Laravel's supported secure hashing.
- Server-side authorization on every protected endpoint.
- Rate limiting on login, OTP, booking holds, promo validation, and public lookup.
- OTP expiry, retry limit, and resend cooldown.
- CSRF protection where cookie-based sessions are used.
- Secure, HttpOnly, SameSite cookies when applicable.
- Strict CORS allowlist.
- Validate file MIME type, extension, and size; use generated storage keys.
- Store uploads outside executable web roots or in object storage.
- Never trust client-provided price, status, role, or ownership.
- Verify payment webhook signatures.
- Keep secrets in environment/secret manager, never in source control.
- Use least-privilege database credentials.
- Record staff financial actions in audit logs.
- Mask personal data in logs and exports where practical.
- Define retention and deletion policy for personal data.
- Provide privacy notice and terms before collecting customer information.
- Do not store card data; let the payment provider handle sensitive payment details.

---

# 12. LOGGING, MONITORING, AND OPERATIONS

Minimum operational visibility:

- Application error logs.
- Booking creation and conflict metrics.
- Payment webhook received/verified/failed metrics.
- Pending payment age.
- Expired hold count.
- Queue depth and failed jobs.
- API latency and HTTP error rate.
- Database backup success/failure.
- Server disk, memory, CPU, and availability.

Use correlation/request IDs across API requests, booking actions, payment events, and background jobs. Do not log passwords, OTP values, full payment credentials, or unnecessary personal data.

---

# 13. TEST STRATEGY

## 13.1 Unit tests

- Price calculation.
- Pricing rule priority.
- Slot generation.
- Conflict predicate.
- Booking transition rules.
- Cancellation eligibility.
- Refund calculation.
- Promotion validation.

## 13.2 Feature/integration tests

- Public venue and court browsing.
- Availability endpoint.
- Booking hold creation.
- Payment instruction creation.
- Valid and invalid payment webhook.
- Duplicate webhook.
- Manual transfer verification.
- Booking cancellation.
- Reschedule.
- Role and venue scope enforcement.
- Report calculations.

## 13.3 Mandatory concurrency tests

Run simultaneous requests against a real PostgreSQL test database:

1. Two customers request the same court and interval.
2. Staff booking races with online booking.
3. Court block races with checkout.
4. Payment success webhook races with hold expiry.
5. Duplicate webhook arrives repeatedly.

Expected invariant: no two active reservations may overlap for the same court.

SQLite-only tests are insufficient to prove PostgreSQL exclusion constraint or concurrency behavior. Use PostgreSQL in CI for these tests.

## 13.4 Minimum acceptance criteria for MVP

- Customer can find a venue and court.
- Availability is calculated by backend.
- Booking hold is created atomically.
- Two concurrent requests cannot reserve the same interval.
- Price is calculated and persisted by backend.
- Payment success requires verified webhook or authorized manual verification.
- Duplicate webhook does not duplicate payment effects.
- Expired hold releases its reservation.
- Staff can create a manual booking through the same conflict validation.
- Venue-scoped staff cannot access other venues.
- Customer cannot access another customer's booking.
- Admin can manage court, schedule, price, and booking.
- Basic reports reconcile with transaction records.
- Errors are visible and actionable without exposing secrets.

---

# 14. DEPLOYMENT AND ENVIRONMENTS

## 14.1 Environments

- Local development.
- Automated test/CI.
- Staging/UAT.
- Production.

Use separate databases, payment credentials, and notification credentials for each environment.

## 14.2 Production components

- Nginx reverse proxy.
- Vue static frontend.
- Laravel application/PHP runtime.
- PostgreSQL.
- Redis for cache/queue/locks if enabled.
- Queue worker.
- Laravel scheduler.
- Object storage for uploaded media.
- External payment and notification providers.

## 14.3 Deployment requirements

- Production debug mode disabled.
- Environment secrets configured outside Git.
- Database migrations run through a controlled release process.
- Queue worker restarted/reloaded on deployment.
- Scheduler configured.
- Webhook URL configured in provider dashboard.
- HTTPS and correct CORS configured.
- Health check endpoint available without exposing internals.
- Database and upload backup configured.
- Restore procedure documented and tested.
- Rollback plan available for application release and database changes.

## 14.4 Backup baseline

Suggested starting policy, to be confirmed:
- Daily database backup.
- Separate backup of uploaded files.
- Off-server backup copy.
- Retention: 7 daily and 4 weekly copies.
- Periodic restore test.
- Record backup status and alert on failure.

---

# 15. REPOSITORY STRUCTURE

Recommended monorepo if the project is new:

```text
padel-booking/
├── AGENTS.md
├── README.md
├── docs/
│   ├── MASTER_SYSTEM_SPEC.md
│   ├── DATABASE_DESIGN.md
│   ├── API_SPEC.md
│   ├── UI_UX_SPEC.md
│   ├── BUSINESS_RULES.md
│   ├── SECURITY.md
│   └── DEPLOYMENT.md
├── apps/
│   ├── web/
│   └── api/
├── infrastructure/
│   ├── docker/
│   ├── nginx/
│   └── scripts/
└── .gitignore
```

If the project already exists in another structure, do not force this layout. Update the current structure incrementally and document the actual layout in `README.md`.

## 15.1 `AGENTS.md` minimum content

The repository should contain an `AGENTS.md` that tells coding agents:

1. Read `docs/MASTER_SYSTEM_SPEC.md` before implementing features.
2. Read the relevant domain document before editing that domain.
3. Follow anti-slop rules in section 0.
4. Inspect current code before changing it.
5. Run tests and report actual results.
6. Do not invent unresolved business decisions.
7. Do not modify unrelated files.

---

# 16. IMPLEMENTATION PLAN AND GATES

## Phase 0 — Discovery and decisions

Deliverables:
- Repository inspection.
- Confirm framework/runtime versions.
- Confirm business model and open decisions.
- Approved UI direction.
- Approved booking and payment policies.

**Gate:** no core booking/payment implementation until critical booking and payment policies are confirmed.

## Phase 1 — Foundation

- Repository setup or audit.
- Frontend/backend environment.
- Authentication and authorization.
- Organization and venue model.
- Database migration baseline.
- CI and test database.

**Gate:** auth, role, and venue-scope tests pass.

## Phase 2 — Venue operations

- Courts.
- Operating hours.
- Special dates.
- Court blocks.
- Pricing rules.
- Admin CRUD.

**Gate:** configuration tests and authorization tests pass.

## Phase 3 — Availability and booking

- Availability engine.
- Price quote.
- Reservation/hold.
- Booking status lifecycle.
- Expiry job.
- Staff-assisted booking.

**Gate:** PostgreSQL concurrency tests prove no overlapping active reservation.

## Phase 4 — Payments

- Payment provider adapter.
- Payment intent/instruction.
- Verified webhook.
- Manual transfer proof.
- Finance verification.
- Reconciliation and expiry.

**Gate:** sandbox tests, duplicate webhook tests, and failure recovery pass.

## Phase 5 — Customer experience and operations

- Public pages.
- Booking checkout.
- Customer booking history.
- Admin calendar and booking detail.
- Email notification.
- Basic reporting.

**Gate:** end-to-end booking scenario passes on desktop and mobile.

## Phase 6 — UAT and production

- Security review.
- Performance smoke test.
- Backup/restore test.
- UAT sign-off.
- Production deployment.
- Monitoring and runbook.

**Gate:** owner approves UAT and operational checklist.

---

# 17. DEFINITION OF DELIVERABLES

A coding agent must produce concrete artifacts, not just descriptions.

For each completed feature, include:

- Source code.
- Database migration(s), if schema changes.
- Seeder/factory only where useful.
- Automated tests.
- API documentation/update.
- UI states.
- Environment variable documentation if needed.
- Manual setup steps if unavoidable.
- Summary of files changed.
- Test commands and actual results.

Do not mark a feature complete when only the UI is implemented and the API is mocked.

---

# 18. TASK HANDOFF TEMPLATE FOR AI AGENTS

Use this template when assigning implementation tasks:

```text
TASK:
[One specific outcome]

SOURCE OF TRUTH:
- docs/MASTER_SYSTEM_SPEC.md
- [Relevant domain/API/UI document]

CURRENT STATE:
[What already exists; inspect repository to verify]

SCOPE:
- [Included]
- [Included]

OUT OF SCOPE:
- [Explicit exclusions]

ACCEPTANCE CRITERIA:
1. [Observable behavior]
2. [Authorization/security condition]
3. [Data integrity condition]
4. [Test condition]

CONSTRAINTS:
- Do not invent unresolved business rules.
- Preserve existing conventions.
- No fake/mock production behavior.
- Do not modify unrelated files.

REQUIRED REPORT:
- Files changed.
- Implementation summary.
- Tests run and actual results.
- Known limitations/open decisions.
```

---

# 19. FINAL ENGINEERING RULES

1. PostgreSQL is the source of truth for bookings, reservations, and payments.
2. Availability is a derived view, not a separately editable truth.
3. A booking is not confirmed until payment is validated or an explicitly approved free/manual-payment policy applies.
4. A payment is not successful because the frontend says so.
5. A reservation cannot overlap another active reservation for the same court.
6. Every money-related operation must be auditable.
7. Historical booking totals must not change when pricing rules change.
8. Venue access must be enforced server-side.
9. All time calculations must respect the venue timezone and daylight-saving rules where applicable.
10. All unresolved policy decisions remain explicit until the owner approves them.
11. Prefer a working, tested, maintainable implementation over a large volume of generated code.
12. Never trade data integrity for visual polish or implementation speed.

---

# 20. OWNER APPROVAL CHECKLIST

Before production implementation, owner approval is required for:

- [ ] Business model: single venue, multi-venue operator, or marketplace.
- [ ] Initial venue/court count.
- [ ] Operating hours and special-date rules.
- [ ] Slot interval, booking duration, and buffer.
- [ ] Hold duration and payment deadline.
- [ ] Full payment vs deposit.
- [ ] Payment gateway provider.
- [ ] Manual transfer verification process.
- [ ] Cancellation and refund policy.
- [ ] Reschedule policy.
- [ ] Guest checkout vs mandatory account.
- [ ] Email/WhatsApp notification provider.
- [ ] Tax/service fee policy.
- [ ] Brand identity, domain, and approved UI direction.
- [ ] Production hosting, backup, and recovery expectations.

**End of master specification.**
