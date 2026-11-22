# AGENTS.md — Padel Booking Platform

Before making changes, read `docs/MASTER_SYSTEM_SPEC.md` in full.

Mandatory rules:
1. Treat the master specification as the product and engineering source of truth.
2. Inspect the existing repository and tests before editing.
3. Do not invent business rules marked OPEN DECISION. Ask the owner when a decision blocks implementation.
4. Do not create fake buttons, mock production flows, hardcoded success states, or placeholder CRUD.
5. Enforce authorization and validation on the server.
6. Keep PostgreSQL as the source of truth for booking, reservation, and payment state.
7. Prevent overlapping reservations with database-enforced concurrency safety; frontend checks are not sufficient.
8. Never mark a payment successful from a browser redirect. Require a verified webhook or authorized manual verification.
9. Use decimal database types for money; never floating point.
10. Add/update migrations and tests with schema or behavior changes.
11. Do not modify unrelated files or add dependencies without a reason.
12. Run relevant tests, lint, and formatting; report actual commands and results.
13. Review the final diff for secrets, unrelated changes, and incomplete behavior.
14. Report files changed, tests run, limitations, and unresolved decisions. Never claim tests passed if they were not run.
15. Default communication style: Caveman (full) — terse, zero filler, no tool-call narration, exact technical substance.

Definition of done: acceptance criteria met, authorization enforced, data integrity protected, loading/empty/error/success states handled, relevant tests pass, documentation updated, and final diff reviewed.
