# Donor profile API

The local Expo registration and profile screens were inspected as the reference.
Frontend files are unchanged; API integration remains a later task.

## Registration

Send JSON to `POST /api/register` with `Accept: application/json` and
`Content-Type: application/json`:

```json
{
  "first_name": "Juan",
  "middle_name": "Santos",
  "last_name": "Dela Cruz",
  "email": "juan@example.com",
  "mobile_number": "09171234567",
  "password": "password123",
  "password_confirmation": "password123",
  "birth_date": "2004-09-17",
  "gender": "Male",
  "blood_type": "O+"
}
```

All fields except `middle_name` are required. Middle name may be omitted, null,
or empty. Personal names accept up to 80 characters each. Email accepts up to
255 characters and must be unique. Password requires at least 8 characters and
matching confirmation. The previous `name`-only registration payload is replaced
by these fields; `users.name` combines first, optional middle, and last names.

Mobile numbers accept `09XXXXXXXXX` or `+639XXXXXXXXX`, optionally containing
spaces, parentheses, or hyphens. They are normalized to `+639XXXXXXXXX` before
uniqueness validation and storage, so equivalent formats cannot create duplicates.
Numbers must be JSON strings. Birth dates must be real calendar dates in
`YYYY-MM-DD` format, strictly before today. There is no eligibility or age assessment.
Gender accepts `Male` or `Female`; blood type accepts `A+`, `A-`, `B+`, `B-`,
`AB+`, `AB-`, `O+`, or `O-`.

Success is HTTP 201 with `message`, `user`, `donor_profile`, and `token`.
The user, profile, and Sanctum token are created in a single transaction; any
write failure rolls back all three. Password hashing and hidden account fields
are preserved. Validation errors use HTTP 422 with `message` and field `errors`.
Unexpected write failures return HTTP 500 with a generic message, without internal details.

## Profile routes

Both routes require `Authorization: Bearer <token>`:

| Method | Path | Behavior |
| --- | --- | --- |
| GET | `/api/donor-profile` | Returns `user` and `donor_profile` for the token owner. |
| PUT | `/api/donor-profile` | Updates supplied profile/account fields and returns `message`, `user`, and `donor_profile`. |

PUT accepts any subset of `first_name`, `middle_name`, `last_name`, `email`,
`mobile_number`, `birth_date`, `gender`, and `blood_type`. Omitted fields are
preserved. Only middle name may be null. The same validation rules apply, but
unchanged email/mobile values remain valid. Clearing middle name uses null.
Email and profile changes are transactional; name changes synchronize `users.name`.
Changing email clears any previous email verification timestamp.

`user_id` in a write request is prohibited (422). Query-string user IDs never
select another profile. Password, account name, and other unrecognized fields
are not editable through PUT. Responses exclude password hashes, remember tokens,
and access-token internals. Birth dates are returned as `YYYY-MM-DD`.

Missing or invalid bearer tokens return 401. Older accounts without a donor
profile receive 404 with `{"message":"Donor profile not found."}` from GET/PUT.
No profile data is fabricated or backfilled, and there is no POST profile endpoint.
Existing accounts retain login/current-user/logout access; completing their profile
would need a separately authorized onboarding or data migration task.

Existing routes remain: `POST /api/login`, `GET /api/user`, `POST /api/logout`.
Logout revokes only the current token.

## Frontend mapping

| Expo registration field | Backend JSON field |
| --- | --- |
| `firstName` | `first_name` |
| `middleName` | `middle_name` |
| `lastName` | `last_name` |
| `email` | `email` |
| `mobileNumber` | `mobile_number` |
| `password` | `password` |
| `confirmPassword` | `password_confirmation` |
| `birthDate` (`MM/DD/YYYY` UI) | `birth_date` (`YYYY-MM-DD` API) |
| `gender` | `gender` |
| `bloodType` | `blood_type` |

Convert `09/17/2004` to `2004-09-17` by calendar components, without timezone
conversion. The current profile screen calls its display property `mobile`;
populate it from `donor_profile.mobile_number` during later integration.

Total donations, streak counts, and achievements are not stored in donor_profiles.
They will come from verified donation records and backend calculations in future work.

## Verification

`php artisan test` uses the configured SQLite in-memory database and ordinary
migrations. Profile tests check that this isolation is active before migrating.
They cover registration, normalization, validation, safe updates, ownership,
rollback, legacy missing profiles, and authentication regression behavior.
The development MySQL database receives only the additive donor_profiles migration.
