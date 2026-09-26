# LifeFlow pre-screening

These deterministic project rules were approved in the task on September 7, 2026.
They are self-assessment rules, not medical clearance. Final eligibility is confirmed
by the blood donation facility. No AI participates in the decision.

## Questionnaire and rules

The existing evaluation form retains its question order and design.

| Field | Question/input | Rule |
| --- | --- | --- |
| weight | Weight in kg | Below 50: temporarily_ineligible |
| sleepHours | Hours of sleep last night | Below 5: temporarily_ineligible |
| currentSymptoms | Do you currently have fever, cough, colds, sore throat, or feel unwell? | YES: temporarily_ineligible |
| medication | Are you currently taking medication? | YES: needs_further_screening |
| donatedWithinThreeMonths | Have you donated blood within the last 3 months? | YES: temporarily_ineligible |
| feelsWell | Do you feel well today? | NO: temporarily_ineligible, consistent with the approved unwell rule |

Exactly 50 kg and 5 hours pass those checks. Medication alone never marks a donor
temporarily ineligible. Medication advice explains that the medicine and reason for
taking it must be reviewed by blood-donation staff. If both types of rule trigger,
temporary ineligibility takes precedence and all reasons, including medication advice,
are retained. Otherwise the result is eligible. The service is
`app/Services/EligibilityEvaluator.php`; tests cover every rule and combined reasons.

The old ambiguous keys recentIllness/recentDonation are replaced, so snapshots have
the approved question meaning. The questionnaire's three-month answer is self-reported;
no donation dates or unsupported deferral calculations are invented.

## Protected API

Use `Authorization: Bearer <token>`, `Accept: application/json`, and JSON content type.

`POST /api/eligibility-assessments`:

```json
{
  "answers": {
    "weight": 50,
    "sleepHours": 5,
    "currentSymptoms": "NO",
    "medication": "NO",
    "donatedWithinThreeMonths": "NO",
    "feelsWell": "YES"
  }
}
```

All six answers are required. Weight and sleep must be JSON numbers, weight positive,
and sleep between 0 and 24 (input validity). Choices must be uppercase YES/NO strings.
Unknown keys, client results, and user_id are rejected with 422. Laravel assigns
ownership, result, reasons, and assessed_at. Successful creation returns 201 with
`message` and `assessment`, containing the saved snapshot and all reasons.

`GET /api/eligibility-assessments/latest` returns 200 with `assessment` or null and
a clear message when no assessment exists. `GET /api/eligibility-assessments?page=1`
returns Laravel pagination with 20 rows per page, newest assessed_at and then ID first.
Queries always use the token owner; a query-string user_id cannot select another donor.
Guests receive 401. Unexpected save failures return a generic 500 message.

History is append-only through this API. Storage columns are id, user_id, result,
reasons (JSON), answers (JSON), assessed_at, created_at, updated_at. Result has three
controlled values. User hasMany EligibilityAssessment; each belongsTo User.
No eligibility fields are added to donor_profiles.

## Frontend and verification

The existing Evaluation Form submits answers using the private auth-context token.
A synchronous lock and disabled controls prevent concurrent duplicate submissions.
The form renders the returned result, all reasons, and the facility disclaimer;
errors preserve answers for correction/retry. The existing Status screen fetches
latest on focus, shows the assessment date, and never substitutes a mock eligible result.
No assessment history screen exists, so history remains API-only.

Checks: `php artisan test`; frontend `tsc --noEmit`, ESLint; and
`node tools/test-eligibility-frontend.cjs` for real API-module transport tests with
mocked HTTP. Device rendering and interaction still need the following Expo Go check:

1. Sign in and open the existing Evaluation Form through the current navigation.
2. Submit 50 kg, 5 hours, no symptoms/medication/recent donation, feels well: eligible pre-screening.
3. Change medication to Yes: further screening with the staff-review message.
4. Use 49 kg plus medication: temporary ineligibility with both reasons.
5. Return to Status: saved result/date/reasons should match. Reopen the app to verify persistence.
6. Test rapid taps, missing inputs, an offline request, and an expired session.

No physical Expo Go session is exercised by command-line tests. No new medical rules,
Flowie AI, donation history, rewards, notifications, or admin features are implemented.

## Implementation report

- Backend suite: 75 tests passed, 629 assertions, including auth/profile regressions.
- Frontend TypeScript: passed. ESLint over `src` with zero warnings: passed.
- Transport tests: all three result states, answer-only payload, bearer authentication,
  empty latest, validation errors, and expired-session errors passed.
- A repository-wide ESLint attempt included existing generated `.expo` JavaScript
  bundles and reported generated-code errors. Source-only lint passes; generated
  artifacts were not changed.
- Migration `2026_09_07_090000_create_eligibility_assessments_table.php` ran successfully
  on `lifeflow_db`, and the expected columns and three routes were verified.
- No rule remains blocked for review. These remain project-approved pre-screening
  rules, with final facility confirmation required. Device checks above remain manual.

Created backend files:

- `app/Services/EligibilityEvaluator.php`
- `app/Models/EligibilityAssessment.php`
- `app/Http/Requests/StoreEligibilityAssessmentRequest.php`
- `app/Http/Controllers/Api/EligibilityAssessmentController.php`
- `database/migrations/2026_09_07_090000_create_eligibility_assessments_table.php`
- `tests/Unit/EligibilityEvaluatorTest.php`
- `tests/Feature/EligibilityAssessmentTest.php`
- `docs/eligibility-api.md`
- `tools/implement-eligibility-frontend.cjs` (one-time integration patch)
- `tools/test-eligibility-frontend.cjs`

Modified backend files: `app/Models/User.php`, `routes/api.php`.

Created frontend file: `src/services/eligibility.ts`.
Modified frontend files: `src/contexts/auth-context.tsx`, `src/app/evaluation.tsx`,
`src/app/(tabs)/status.tsx`.
