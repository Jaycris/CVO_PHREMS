# What PHREMS needs the CRM to add

Three small additions. Two are one field each; the third is a short endpoint.

Nothing here changes an existing field or breaks the current integration —
PHREMS already reads what the CRM sends today and will keep working unchanged
if none of this lands.

---

## Why

PHREMS never calculates a commission. The CRM works out every figure and PHREMS
prints it. But two things about an agent are currently recorded in **both**
systems, typed by hand into each, with nothing checking they agree:

| | CRM | PHREMS |
|---|---|---|
| Commission Scheme | `Default Tier`, set in the User Commission Profile, stored as the *service profile* | typed on the employee's Payroll Details tab |
| Agent Target | `10,000.00`, set per month in the same profile | a single standing figure on the employee record |

They have already drifted once. PHREMS held an agent target of `50,000` while
the CRM measured that agent against `10,000` — a slip showing 37.98% of target
next to a profile page claiming the target was something else entirely.

Changing the scheme in the CRM does not reach PHREMS at all, because nothing in
the payload mentions it.

---

## 1. Add the agent's scheme to the commission slip

`GET /api/hris/commission-slip` currently returns:

```jsonc
"agent": {
  "name": "Mia Santos",
  "team": null,
  "work_type": "Remote"
}
```

Please add the scheme:

```jsonc
"agent": {
  "name": "Mia Santos",
  "team": null,
  "work_type": "Remote",
  "commission_scheme": "Default Tier"     // ← new
}
```

Send whatever the CRM shows on screen for that agent in that month. PHREMS
already accepts any of `commission_scheme`, `scheme`, `service_profile` or
`serviceProfile`, so use whichever name fits your code — no need to rename
anything internally.

**What PHREMS does with it:** compares it against the scheme HR recorded and
flags a mismatch on the slip. If the field is absent it stays silent, exactly as
today.

---

## 2. Add the agent's target to the same object

`summary.target` is already sent and is correct — that is the figure
`mtd_percent` is calculated from, and PHREMS uses it on the slip.

What is missing is a way for PHREMS to check the standing figure on the employee
record still matches. Same object, same idea:

```jsonc
"agent": {
  ...
  "target": 10000.00,          // ← new; the agent's target for this month
  "target_currency": "USD"     // ← optional, but please send it if easy
}
```

The currency matters more than it sounds. PHREMS was displaying this as
**PHP 10,000** for a **USD 10,000** target — wrong by roughly fifty-six times, on
the agent's own profile page. That is fixed, but a stated currency stops it
being re-guessed later.

---

## 3. A list of the schemes

```
GET /api/hris/commission-schemes
```

Same authentication as the existing endpoint — PHREMS sends the token it
already uses.

```jsonc
{
  "data": [
    { "name": "Default Tier",  "key": "default_tier",  "is_active": true },
    { "name": "Senior Tier",   "key": "senior_tier",   "is_active": true },
    { "name": "Legacy Plan",   "key": "legacy_plan",   "is_active": false }
  ]
}
```

- `name` — what the CRM shows on screen. This is what HR will see in PHREMS.
- `key` — the CRM's internal identifier, if it differs from the name. Optional;
  PHREMS falls back to `name`.
- `is_active` — whether an agent can still be put on it. Optional, defaults true.

**What PHREMS does with it:** replaces the hand-typed list on its Commission
Schemes page, so the two can no longer drift. Until this exists, HR keeps them
matching by hand.

---

## Not a request, but worth knowing

A `404` from the CRM currently returns a full stack trace, including absolute
paths:

```
"file": "D:\\Project\\Inkspire Media\\CRM-3in1\\vendor\\laravel\\framework\\..."
```

That is `APP_DEBUG=true`. Fine locally, but on a public host it hands anyone who
hits a bad URL your directory layout, framework version and configuration. Worth
setting `APP_DEBUG=false` and `APP_ENV=production` before the CRM faces the
internet — the same change is on PHREMS's own go-live list.

---

## Current state, for reference

Verified against the running CRM on 2026-08-23:

- `GET /api/hris/commission-slip` — works, returns real figures for `EMP-9372`
- Every other `/api/hris/*` path tried returns `404`
- Two of the three PHREMS employees return
  *"No CRM user is linked to this HRIS employee ID"* — `EMP-5696` and
  `EMP-8918` have no `hris_employee_id` set on their CRM user yet
