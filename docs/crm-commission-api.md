# CRM → HRIS commission slip API

What the HRIS needs the CRM to expose so the Commission Slip pages work.

The HRIS **never calculates a commission**. It asks for these numbers and prints
them. If a figure looks wrong on a slip, it is wrong in the CRM, and that is
where it gets fixed — there is no second copy of the maths to keep in step.

---

## 1. The endpoint

```
GET  {CRM_API_BASE_URL}/api/hris/commission-slip
```

### Query parameters

| Name | Example | Meaning |
|---|---|---|
| `agent` | `AG-101` or `maria.santos@creativision.net` | Who the slip is for — see §2 |
| `month` | `2026-08` | Calendar month, always `YYYY-MM` |

### Authentication

The HRIS sends the value of `CRM_HRIS_API_TOKEN` on every request, in one of two
forms. Set `CRM_HRIS_AUTH_HEADER` in the HRIS `.env` to whichever the CRM reads:

```
CRM_HRIS_AUTH_HEADER=bearer         →  Authorization: Bearer <token>
CRM_HRIS_AUTH_HEADER=x-hris-token   →  X-HRIS-Token: <token>
```

The token identifies the HRIS application, not a person. Treat it as a shared
secret: long, random, rotatable, and never in the URL.

### Expected status codes

| Status | HRIS behaviour |
|---|---|
| `200` | Renders the slip |
| `401` / `403` | "The CRM refused this app's token" |
| `404` | "The CRM has no commission record for this agent and month" |
| `5xx` | "The CRM had an error answering. Try again shortly." |

Anything non-200 shows a message and **no figures at all**. The HRIS never
renders zeros on failure, because a confident `0.00` reads to an agent as
"you earned nothing" rather than "we could not ask".

---

## 2. Identifying the agent

The `agent` value is always the **HRIS Employee ID** — `EMP-9372`. The same
value is also sent as `hris_employee_id`.

That is the only key. There is no matching on name, alias, phone name or email,
because all of those are wrong some of the time and the failure mode is one
agent's earnings on another agent's screen.

For this to resolve, the CRM user must carry that ID in its own
`hris_employee_id` column — populated either by the admin picking the employee
on the Create User form, or by editing an existing user. See
`docs/hris-employee-lookup-api.md`.

A CRM user without it should return `404`, which the HRIS shows as "the CRM has
no commission record for this agent and month". The HR Commission Slips screen
prints the exact ID it asked for, which is the first thing to check when a slip
comes back empty.

**Echo it back.** If the response includes `hris_employee_id`, the HRIS checks
it matches the employee it asked about and refuses to display anything if it
does not. That turns the one-way link into a two-way one and costs the CRM a
single field.

---

## 3. Response body

```jsonc
{
  "agent": {
    "id":        "AG-101",
    "name":      "Maria Santos",
    "team":      "Team Alpha",
    "work_type": "Onsite"
  },

  "month": "2026-08",

  "summary": {
    "mtd":                        12450.75,
    "target":                     20000.00,
    "mtd_percent":                62.25,

    "service_commission":         830.50,
    "markup_commission":          415.25,
    "usd_total":                  1245.75,

    "exchange_rate":              58.4210,
    "php_total":                  72778.40,

    "card_payment_hold_percent":  10,
    "card_payment_hold_amount":   3200.15,

    "net_commission":             69578.25
  },

  "transactions": [
    {
      "sold_date":          "2026-08-04",
      "brand":              "Ink House",
      "author":             "J. Cruz",
      "book_title":         "Tides of Manila",
      "service":            "Publishing",
      "payment_method":     "Card",

      "sale_amount":        2400.00,
      "service_amount":     1800.00,
      "markup_amount":      600.00,

      "service_commission": 180.00,
      "markup_commission":  60.00,

      "usd_total":          240.00,
      "php_total":          14021.04,

      "card_hold_amount":   1402.10,
      "net_commission":     12618.94
    }
  ]
}
```

### Field notes

- **Numbers may be JSON numbers or numeric strings.** `1245.75` and `"1,234.56"`
  are both accepted; commas and spaces are stripped.
- **Every field is optional.** A field the CRM omits renders as `—`, not `0.00`.
  Send the fields you have; add the rest later without breaking anything.
- **`net_commission` is the final payable figure** after the card hold. The HRIS
  prints it as the headline number and does not derive it.
- **`card_hold_amount` applies only to card payments.** The CRM decides which
  sales those are; the HRIS just shows a "held" tag on any row with a hold above
  zero. Send `0` for non-card rows.
- **`exchange_rate`** is the rate the CRM used for this slip. The HRIS displays
  it for transparency and never multiplies by it.
- **`mtd_percent`** is a percentage, so `62.25` means 62.25%. A `%` sign in a
  string value is stripped.

### Accepted alternative names

If the CRM already uses different names, these are read too — the first name in
each row is the documented one:

| Documented | Also accepted |
|---|---|
| `sold_date` | `soldDate`, `date` |
| `author` | `client`, `author_client`, `customer` |
| `book_title` | `bookTitle`, `title` |
| `sale_amount` | `saleAmount`, `amount` |
| `service_amount` | `service_mtd`, `serviceAmount` |
| `markup_amount` | `markup_mtd`, `markupAmount` |
| `usd_total` | `usdTotal`, `total_usd` |
| `php_total` | `phpTotal`, `total_php` |
| `card_hold_amount` | `cardHoldAmount`, `card_payment_hold_amount` |
| `card_payment_hold_percent` | `card_hold_percent`, `cardHoldPercent` |
| `exchange_rate` | `exchangeRate`, `fx_rate` |
| `target` | `quota` |

Every camelCase spelling of a snake_case name is accepted.

---

## 4. If the CRM cannot supply the statement rows yet

The summary and the statement are independent. The HRIS handles three distinct
cases and shows a different thing for each:

| CRM sends | HRIS shows |
|---|---|
| `"transactions": [ … ]` | The statement table |
| `"transactions": []` | "No commission records in August 2026" |
| no `transactions` key at all | "The CRM did not send a statement for this month" |

The last two are kept apart deliberately. An agent who sold nothing and a CRM
that has not built the endpoint yet are not the same thing, and telling an agent
"no records" when the truth is "not implemented" invites a complaint that costs
more to answer than this paragraph did to write.

**So: ship the summary first if that is easier.** Add `transactions` when ready
and the table appears with no HRIS change.

### Minimum to add for the statement

Per commission record, for the requested agent and month:

```
sold_date, brand, author, book_title, service, payment_method,
sale_amount, service_amount, markup_amount,
service_commission, markup_commission,
usd_total, php_total, card_hold_amount, net_commission
```

---

## 5. HRIS configuration

```dotenv
CRM_API_BASE_URL=https://crm.creativision.net
CRM_HRIS_API_TOKEN=<long random secret>
CRM_HRIS_AUTH_HEADER=bearer      # or x-hris-token
CRM_API_TIMEOUT=15               # seconds
CRM_API_CACHE_TTL=300            # seconds; commission figures move during the month
CRM_API_VERIFY_TLS=true          # only ever false against a local CRM with a self-signed cert
```

Leaving `CRM_API_BASE_URL` blank keeps the commission pages dormant — they say
the connection is not set up rather than erroring.

Responses are cached per agent and month for `CRM_API_CACHE_TTL` seconds, purely
so a page refresh does not hammer the CRM. Both screens have a **Refresh** button
that clears that entry and re-asks.

---

## 6. Where this lives in the HRIS

| Piece | File |
|---|---|
| HTTP wrapper, auth, timeouts | `app/Services/Crm/CrmClient.php` |
| Fetch + cache + agent key | `app/Services/Crm/CommissionSlipService.php` |
| Summary value object | `app/Services/Crm/CommissionSlip.php` |
| Statement row value object | `app/Services/Crm/CommissionTransaction.php` |
| Failure messages | `app/Services/Crm/CrmUnavailable.php` |
| Agent's own page | `resources/views/components/⚡my-commission.blade.php` |
| Commission runs | `resources/views/components/commissions/⚡runs.blade.php` |
| One run, with its slips | `resources/views/components/commissions/⚡run-show.blade.php` |
| The slip itself (shared) | `resources/views/components/commission-slip-detail.blade.php` |
| PDF | `app/Http/Controllers/CommissionSlipPdfController.php` |
