# HRIS → CRM employee lookup

What the CRM's **Create User** page calls when an admin uses the *HRIS Employee*
search field.

Two rules shape everything below:

1. **HRIS owns employee identity.** The bridge is the HRIS Employee ID, stored
   on the CRM user as `hris_employee_id`.
2. **The CRM must work without HRIS.** Every part of this is optional. If HRIS
   is down, unreachable, or simply not configured, CRM user creation carries on
   by hand exactly as it does today.

---

## 1. Endpoints

All live under `/api/crm` on the HRIS host. Read-only — nothing here writes.

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/api/crm/health` | Is HRIS up? Decides whether to offer the search field |
| `GET` | `/api/crm/employees?q=&limit=` | Type-ahead search |
| `GET` | `/api/crm/employees/{hrisEmployeeId}` | Re-read one employee when editing a CRM user |

### Authentication

Send the shared secret on every request, either way:

```
Authorization: Bearer <CRM_INBOUND_API_TOKEN>
X-HRIS-Token: <CRM_INBOUND_API_TOKEN>
```

This is a **different secret** from the one HRIS uses to call the CRM for
commission data. They travel in opposite directions; one leaking must not
surrender the other.

Rate limited to 60 requests a minute. Debounce the type-ahead by ~300 ms and you
will never come close.

### Status codes

| Status | Meaning | What the CRM should do |
|---|---|---|
| `200` | Results | Show them |
| `401` | Bad or missing token | Treat as unavailable — fall back to manual |
| `404` | No such employee (`show` only) | "That HRIS employee no longer exists" |
| `429` | Too many requests | Back off, then fall back to manual |
| `503` | HRIS has no token configured | Fall back to manual |
| timeout / network error | HRIS down | Fall back to manual |

---

## 2. Search

```
GET /api/crm/employees?q=santos&limit=15
```

`q` matches employee ID, phone name, first name, last name or company email.
Omit `q` to list everyone (still capped by `limit`, default 15, max 50).

```jsonc
{
  "data": [
    {
      "hris_employee_id":   "EMP-9372",
      "phone_name":         "Maria Bell",
      "first_name":         "Maria",       // split from phone_name
      "last_name":          "Bell",        // split from phone_name
      "email":              "maria.santos@creativision.net",
      "department":         "Sales",
      "work_type":          "Inbound",
      "position":           "Sales Agent", // Role *suggestion* only
      "employment_status":  "Regular",
      "is_active":          true
    }
  ],
  "count": 1,
  "query": "santos"
}
```

People who have left are still returned, sorted last, with `is_active: false` —
an admin fixing an old CRM user needs to find them. Do not offer them when
creating a *new* user.

---

## 3. Field mapping

| CRM field | Comes from | Notes |
|---|---|---|
| HRIS Employee ID | `hris_employee_id` | **The bridge.** Store this. |
| First Name | `first_name` | Split from Phone Name |
| Last Name | `last_name` | Split from Phone Name; may be `null` |
| Email Address | `email` | HRIS company email |
| Department | `department` | |
| Work Type | `work_type` | May be `null` — admin picks |
| Role | `position` | **Suggestion only.** CRM owns its own access model |
| Phone Number | — | **Stays manual.** This is the VOIP number, which only the CRM knows |
| Brand / Account | — | **Stays manual.** Exists only in the CRM |

### The Phone Name rule

In HRIS, **Phone Name** is the name the employee uses for CRM work — not their
legal name. CRM users should carry the phone name, so HRIS does the split and
sends both halves rather than leaving the CRM to guess:

| Phone Name | First Name | Last Name |
|---|---|---|
| `Maria Bell` | `Maria` | `Bell` |
| `Maria Bell Cruz` | `Maria` | `Bell Cruz` |
| `Mia` | `Mia` | `null` |
| *(blank)* | `null` | `null` |

Two or more words: the first word is the first name, **everything after it** is
the last name — so a middle word is kept rather than dropped.

One word: it is the first name and the last name comes back `null`. The CRM
should leave Last Name blank and let the admin finish it. HRIS will not invent a
surname.

---

## 4. Fields HRIS will never send

Not "filtered out on request" — they are not in the response shape at all, and
the code is an allow-list so a new HRIS column cannot leak in by accident:

`birthdate` · `civil_status` · `tin_number` · `sss_number` · `philhealth_number`
· `pagibig_number` · `basic_salary` · `allowance` · `personal_contact_number` ·
`emergency_contact_name` · `emergency_contact_number` · `address` ·
`personal_email` · bank details

The CRM has no use for any of it, and every copy is another place it can leak.
If the CRM ever appears to need one of these, that is a conversation, not a
config change.

---

## 5. What the CRM side needs to build

This half is CRM work — it is not in the HRIS codebase.

### Schema

```sql
ALTER TABLE users ADD COLUMN hris_employee_id VARCHAR(50) NULL;
CREATE INDEX idx_users_hris_employee_id ON users (hris_employee_id);
```

Nullable, and it must stay nullable. Existing CRM users without one are valid
and must keep working untouched.

### Create User form

Add an **optional** HRIS Employee search field above the existing fields.

**States to handle:**

| State | What to show |
|---|---|
| Idle | The search box, with the form fully usable without it |
| Typing (< 2 chars) | Nothing — do not call yet |
| Loading | A spinner in the field; the rest of the form stays enabled |
| Results | A list: phone name, employee ID, department, email |
| Empty | "No matching HRIS employee. You can continue creating this user manually." |
| Selected | A summary chip with the chosen employee and a **Clear** button |
| HRIS unavailable | **"HRIS is unavailable. You can continue creating this user manually"** |

That last message is the one you specified — use it verbatim for any failure:
timeout, 401, 503, network error, or `/health` not answering.

**On selecting an employee, auto-fill only:** First Name, Last Name, Email
Address, Department, Work Type, and `hris_employee_id`. Suggest Role from
`position` if you have a mapping; otherwise leave it.

**Never auto-fill or lock:** Phone Number and Brand/Account. Leave every filled
field editable — the admin may still need to correct something.

### Edit User form

Same search field, so an existing CRM user can be linked or re-linked later.
Show the current `hris_employee_id` with an **Unlink** option.

On opening a user that already has one, call
`GET /api/crm/employees/{hris_employee_id}` to confirm they still exist and
re-read their department, work type and email. A `404` means the HRIS record is
gone — warn, do not auto-clear.

### Fallback behaviour — the non-negotiable part

- Never block Save because HRIS could not be reached.
- Never require the search field to have been used.
- Never clear fields the admin already typed because a lookup failed.
- Consider calling `/api/crm/health` once when the form opens and hiding the
  search field entirely if it fails — the admin then sees the form they have
  always seen, rather than a broken widget.

---

## 6. Configuration

**On the HRIS side** (`.env`):

```dotenv
CRM_INBOUND_API_TOKEN=<long random secret>
```

Blank closes the lookup API completely — every request gets `503`. That is the
safe default: an empty secret must never mean "let everyone in".

**On the CRM side**, store the HRIS base URL and the same token.

---

## 7. How this changes commission lookups

Once the CRM stores `hris_employee_id`, it becomes the key in the other
direction too. HRIS now asks the commission API by employee id:

```
GET {CRM}/api/hris/commission-slip?agent=EMP-9372&month=2026-08&hris_employee_id=EMP-9372
```

If the CRM echoes `hris_employee_id` back in the response, HRIS checks it
matches the employee it asked about and shows nothing at all if it does not —
see `docs/crm-commission-api.md`. That turns the one-way link into a two-way
one, and it is the difference between "we think this is their commission" and
"both systems agree this is their commission".

---

## 8. Where this lives in HRIS

| Piece | File |
|---|---|
| Routes | `routes/api.php` |
| Token check | `app/Http/Middleware/AuthenticateCrmRequest.php` |
| Endpoints | `app/Http/Controllers/Api/CrmEmployeeLookupController.php` |
| The allow-list + name splitting | `app/Services/Crm/CrmSafeEmployee.php` |
