# Donation records foundation

> Historical implementation report. The manual donor submission flow below is now
> retired. See [the current donation architecture](donation-architecture.md) for
> opportunities, participation, proof, and the preserved trusted-record summary.

Implemented on Laravel 13.30.1 with the existing Sanctum bearer-token authentication.
On resumption, the migration, model, relationship, validator, controller, service,
and four routes were already present. They were inspected and retained. Remaining
work completed: tests, frontend integration, migration execution, and documentation.
No duplicate schema or endpoint implementations were created.

## API contract

All endpoints require `Authorization: Bearer <token>` and `Accept: application/json`.
POST also uses `Content-Type: application/json`.

| Method | Route | Result |
| --- | --- | --- |
| POST | `/api/donation-records` | 201 with message and `donation_record`, always pending |
| GET | `/api/donation-records` | Paginated private history, 20 rows per page |
| GET | `/api/donation-records/{id}` | Owned record or 404, used by existing Activity details |
| GET | `/api/donation-summary` | Completed-only total and calculated achievement |

Example submission:

```json
{
  "donation_date": "2026-09-07",
  "location": "Community Blood Center",
  "notes": "Optional donor notes"
}
```

Date must be a real `YYYY-MM-DD` date, no later than today. Location is required,
up to 255 characters. Notes may be omitted/null, with a maximum of 5000 characters.
Unknown fields are rejected with 422, including user_id, status, submitted_at,
verified_at, total_donations, achievement, and streak_count. Server assigns ownership
from the token, status pending, submitted_at now, and verified_at null.

History sorts by donation_date descending, then ID descending. Optional query
parameters: `page=2` and `status=pending|completed|rejected`. Filters apply on the
server before pagination. A client user_id never changes the query owner.
Guests receive 401, cross-owner details receive 404, and no donor update/verification
route exists. Unexpected submission failures return a generic error without internals.

Example summary:

```json
{
  "total_donations": 5,
  "achievement": { "key": "silver_donor", "label": "Silver Donor" }
}
```

Only status `completed` counts. Pending and rejected records do not count. No totals,
achievements, or streak values are stored on donor_profiles.

## Schema and future verification

Migration: `2026_09_07_100000_create_donation_records_table.php`, successfully applied
to `lifeflow_db` using ordinary `php artisan migrate`.

Columns: id, user_id, donation_date, location, nullable notes, status, submitted_at,
nullable verified_at, created_at, updated_at. Status is controlled to pending,
completed, or rejected, default pending. The user foreign key cascades on account
deletion; indexes support owner/date ordering and completed-only counts.
User hasMany DonationRecord; DonationRecord belongsTo User. Verification and owner
fields are excluded from mass assignment.

Future admin work must authenticate and authorize staff, lock the pending record,
verify it is still pending, and assign completed/rejected plus verified_at together.
This task exposes no admin or self-approval route and performs no fake verification.
Until that future workflow exists, real donor submissions remain pending.
The summary automatically reflects later trusted status changes without backfilling
profile totals or achievement labels.

## Achievement milestones

`DonorAchievementService::calculate()` selects the highest reached milestone:

| Completed count | Key | Label |
| --- | --- | --- |
| 0 | new_donor | New Donor |
| 1–2 | first_time_donor | First-Time Donor |
| 3–4 | bronze_donor | Bronze Donor |
| 5–9 | silver_donor | Silver Donor |
| 10+ | gold_donor | Gold Donor |

## Frontend integration

Inspected Activity history, Activity details, Profile, and Status. Only matching
donation UI was changed; eligibility rules and Status pre-screening remain intact.

- Activity loads real history with status filters, pagination, loading, empty/error,
  and retry states. Cards display pending verification, completed, or rejected.
- The existing `/activity/[id]` route shows private server record details. The new
  `id=new` mode uses the same layout for donation date, location, and notes entry.
  A button on Activity opens it. A synchronous lock blocks rapid duplicate submissions.
- Demo proof-upload/local verification/cancellation and reward amounts were removed
  from the donation detail/history views, since these are not real backend features.
- Profile refreshes `/donation-summary` on focus. Total Donations uses the server
  count, and the former streak card displays Donor Achievement. Existing card and
  medal styles remain; milestones now match the approved 0/1/3/5/10 scheme.
- Donation API functions use the existing private auth-context token. Expired
  credentials clear the session; stale responses cannot survive a session change.
- No streak/demo donation identifiers remain in frontend source. No manual redesign
  is required for the current two-card summary. Device layout should still be checked.

## Files

Created for this task, including the retained files from the interrupted session:

- `app/Models/DonationRecord.php`
- `app/Services/DonorAchievementService.php`
- `app/Http/Requests/StoreDonationRecordRequest.php`
- `app/Http/Controllers/Api/DonationRecordController.php`
- `database/migrations/2026_09_07_100000_create_donation_records_table.php`
- `tests/Unit/DonorAchievementServiceTest.php`
- `tests/Feature/DonationRecordTest.php`
- `tools/implement-donations-frontend.cjs` (one-time integration script)
- `tools/test-donations-frontend.cjs`
- `docs/donation-records-api.md`
- Frontend `src/services/donations.ts`

Modified:

- Backend `app/Models/User.php`, `routes/api.php`
- Frontend `src/contexts/auth-context.tsx`
- Frontend `src/app/(tabs)/activity.tsx`
- Frontend `src/app/activity/[id].tsx`
- Frontend `src/app/(tabs)/profile.tsx`

## Verification and device handoff

Backend: **108 tests passed, 782 assertions**. Includes pending submission, blocked
self-verification/ownership spoofing, dates/text validation, private history/detail,
pagination, no donor approval route, completed-only summary, all milestone boundaries,
and existing auth/profile/eligibility regression tests. Tests use isolated SQLite memory.

Frontend transport checks pass for answer selection, bearer headers, all statuses,
pagination, details, backend summary, validation messages, and session expiration.
Run using `node tools/test-donations-frontend.cjs` from the backend workspace.
Frontend `tsc --noEmit` and source ESLint (`src --max-warnings=0`) both passed.

The first migration-status check found MySQL unavailable; a later normal migration
succeeded after Laragon MySQL was running. The migration row, columns, database name,
and four Sanctum-protected routes were verified afterward.

Manual Expo Go checks (not performed by command-line tests):

1. Log in, open Activity, and submit a past/today donation with location and optional notes.
2. Confirm the saved detail and history say pending; profile totals must not increase.
3. Check filters, pagination, empty history, retry on network failure, and rapid taps.
4. Check Profile's Total Donations/Donor Achievement layout on a narrow device.
5. Verify existing auth/profile/eligibility navigation still works.

No live records were fabricated as completed. Completed/rejected UI contract states
were verified with test fixtures; actual staff verification awaits the future admin task.
