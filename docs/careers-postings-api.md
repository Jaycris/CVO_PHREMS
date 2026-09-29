# PHREMS → website job adverts

What the company website's **Join Our Team** page reads. HR writes a role in
PHREMS (Recruitment) and publishes it; the website shows whatever is published.
Nobody maintains the same advert twice, and taking a role down is one click in
PHREMS rather than an email to whoever owns the site.

Two rules shape this:

1. **PHREMS owns the advert.** Titles, wording, closing dates — all of it is
   edited there.
2. **The website owns how it looks.** This endpoint sends text, not markup.

---

## 1. Endpoints

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/api/careers/openings` | Every role currently advertised |
| `GET` | `/api/careers/openings/{slug}` | One role, for its own page |

Base URL is the PHREMS host: `https://phrems.creativisionoutsourcing.com`.

**No token.** These are adverts meant for strangers, and a secret in a website's
JavaScript is not a secret. Read-only, rate limited to 120 requests a minute per
IP.

### Status codes

| Status | Meaning | What the website should do |
|---|---|---|
| `200` | Results | Render them |
| `404` | No such advert (`show` only) | Show "this role is no longer advertised" and link back to the list |
| `429` | Too many requests | Back off; cache the list rather than fetching per visitor |
| network error | PHREMS unreachable | Hide the section, or show a "get in touch" fallback. Never a stack trace |

An empty list is normal — it means nothing is being advertised today. Show a
"no roles at the moment" message rather than an empty grid.

---

## 2. Response

```jsonc
{
  "data": [
    {
      "slug":             "sales-agent-graveyard",   // the advert's own link
      "title":            "Sales Agent (Graveyard)",
      "summary":          "Sell publishing services to US authors.",  // one line, for the card
      "description":      "Full description...",     // plain text, may contain line breaks
      "responsibilities": "...",                     // may be null
      "qualifications":   "...",                     // may be null
      "department":       "Sales",                   // may be null
      "employment_type":  "Full-time",               // Full-time | Part-time | null
      "workplace_type":   "Remote",                  // Onsite | Hybrid | Remote | null
      "location":         "Cebu City",               // may be null
      "openings":         3,                         // how many people are wanted
      "salary_range":     null,                      // null unless HR ticked "show publicly"
      "apply_email":      "hr@creativisionoutsourcing.com",  // may be null
      "apply_url":        null,                      // may be null
      "posted_on":        "2026-09-29",              // ISO date
      "closes_on":        null                       // ISO date or null
    }
  ],
  "count": 1
}
```

`show` returns the same object under `data`, not wrapped in a list.

**Every field except `slug`, `title` and `openings` can be null.** Render each
one only when it has a value; a heading with nothing under it looks broken.

**Text is plain, not HTML.** Escape it, and turn newlines into paragraphs or
list items yourself. PHREMS will never send markup, so the site's styling can
never be broken by something HR typed.

**How to apply** is `apply_email`, `apply_url`, or both. With an email, link it
as `mailto:` with the role title as the subject. With a URL, link straight to
it. If both are null the role is still real — send people to the contact page.

---

## 3. What never appears here

The response is an allow-list, so a column added to PHREMS later cannot leak
into a public page by accident:

`salary_min` · `salary_max` (unless HR ticked *show publicly*, and then only as
the formatted `salary_range`) · who wrote the advert · the internal position and
department ids · draft and closed roles · anything about employees

A draft, a closed role and a role that never existed all answer `404`. A careers
page is no place to confirm that the company is quietly hiring for something.

---

## 4. Cross-origin

The website is a different origin from PHREMS, so the browser will send a
preflight. PHREMS answers it for the origins named in `CAREERS_ALLOWED_ORIGINS`
(`config/cors.php`), comma separated, scheme included:

```
CAREERS_ALLOWED_ORIGINS=https://creativisionoutsourcing.com,https://www.creativisionoutsourcing.com
```

Both `creativisionoutsourcing.com` and its `www.` form are allowed by default.
Add any other origin the page is served from — a staging domain, or
`http://localhost:5173` while building — and clear the config cache afterwards.

**Opening `index.html` straight off the disk will not work.** A `file://` page
has no origin the browser will send, so the fetch is refused before it reaches
PHREMS. Serve the site over `http://localhost` while developing.

---

## 5. Suggested page behaviour

- **Cache the list** for a few minutes rather than fetching per visitor. Adverts
  change a few times a month, not a few times a second.
- **Sort is already done** — newest first. Keep it.
- **Deep link each role** at `/careers/{slug}`, using the slug as sent. It never
  changes for a given advert, so a link shared on Facebook keeps working until
  the role closes.
- **Closed roles** vanish from the list. A visitor who follows an old link gets
  a `404`, which is the page to handle gracefully.
