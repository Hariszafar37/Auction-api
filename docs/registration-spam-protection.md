# Registration spam protection

## What was happening

Between roughly 08 and 11 August 2026 the live site accumulated 5–20 junk
registrations per day. Every one of them shared the same shape:

| Field | Observed |
|---|---|
| First / last name | Random strings — `hKhPmploaIgTZdDuvcXul`, `rbXOYBmDXngiwKbATIckP` |
| Email | **Real, harvested addresses** — `laura.turner@wfp.org`, `subpoenainquiries@valvesoftware.com`, three separate `@yoobi.nl` addresses |
| Email (Gmail) | Dot-variants of a handful of real inboxes — `li.sh.a.sipp@`, `wi.ll.o.ugh.bycry.s.li.a@`. Gmail ignores dots, so these resolve to the same few people while still passing `unique:users,email` |
| Status | Mostly `pending_email_verification`; a few reached `pending_password` |

Those few that reached `pending_password` are the important detail: it means the
verification link was actually **clicked**. The attacker does not own those
inboxes — corporate mail security (Defender Safe Links, Proofpoint) auto-fetches
URLs in delivered mail. In other words, the verification emails were reaching
real third parties.

**This was not spam signup. It was list-bombing, and we were the weapon.** The
attacker used our open registration endpoint as a free outbound mail relay:
they supply a victim's address, our SES-verified `noreply@colonialauctions.com`
mails that victim an account they never asked for.

The junk rows were the symptom. The cost was **our sender reputation**.
Recipients marking unsolicited mail as spam drives the SES complaint rate up;
AWS reviews accounts around 0.1% and can suspend sending near 0.5%. A suspension
means no user can register, verify, reset a password or receive an invoice.

## Root cause

Three compounding gaps:

1. **No rate limiting anywhere on the API.** Laravel's skeleton does not apply
   `throttle:api` unless `bootstrap/app.php` opts in, and it never did. Verified
   against production: 13 POSTs to `/api/v1/auth/register` in ~4 seconds all
   returned 422, with no `429` and no `X-RateLimit-*` headers.
2. **Mail sent to an unverified, caller-supplied address** with no cost and no
   proof of humanity, on the very first request.
3. **`unique:users,email` with no normalisation**, hence the Gmail dot-variants.

Two of the endpoints also leaked account existence (see "Enumeration" below).

## What changed

### Backend

| Area | Change |
|---|---|
| `config/bot_guard.php` | New. All tuning and the emergency off-switches |
| `app/Support/BotSignals.php` | Detects machine-generated names |
| `app/Rules/HumanName.php` | Validation rule wrapping the above |
| `app/Http/Requests/Auth/RegisterRequest.php` | Honeypot + fill-timing + name rules |
| `app/Providers/AppServiceProvider.php` | `configureRateLimiting()` — three named limiters |
| `routes/api.php` | Throttles applied to all six public auth routes |
| `AuthController::resendVerification()` | Enumeration oracle closed |
| `AuthController::forgotPassword()` | Enumeration oracle closed |
| `app/Console/Commands/PurgeSpamRegistrations.php` | Reviewable cleanup of existing rows |

### Frontend

| Area | Change |
|---|---|
| `src/modules/auth/components/RegisterForm.tsx` | Sends the hidden decoy + fill duration |
| `src/modules/auth/types.ts` | `website` and `form_elapsed_ms` on `RegisterPayload` |
| `src/lib/api/errors.ts` | Friendly copy for HTTP 429 |

## The three layers

### 1. Form signal (the one that stops this specific bot)

A conventional honeypot rejects a decoy that was *filled in*. That catches
nothing here — the bot POSTs the API host directly and never renders our form,
so it submits no decoy at all.

So the check is **presence**, not content. `website` and `form_elapsed_ms` must
both arrive. A client that never loaded the form cannot know they exist.

`form_elapsed_ms` is a **duration**, not a timestamp — measured entirely on the
client's own clock. A device with a badly wrong system time is therefore never
penalised.

### 2. Machine-generated names

`BotSignals` scores a name on four signals, the strongest being **interior
capitals** — capital letters inside a token rather than starting one. Real names
have at most one or two (`McDonald`, `MacArthur`); the attack payloads average
seven. Fully-uppercase tokens are excluded so `JEAN-PIERRE` scores nothing, and
tokens are split on spaces, hyphens and apostrophes so `Mary Jane` and
`O'Brien` are unaffected.

Calibrated against the 40 real attack payloads and a corpus of awkward genuine
names (hyphenated, apostrophed, all-caps, Slavic, accented, CJK, Cyrillic,
Arabic):

- Attack payloads score **6–12**
- Real names score **0–2**
- Threshold is **5**

The gap is wide and guarded by a test (`it separates attack payloads from real
names by a clear margin`) that fails if a future change narrows it.

Every hit is logged with the offending value and its score, whether it is
rejected or merely recorded, so a false positive can be diagnosed from the logs
alone.

### 3. Rate limiting

| Limiter | Routes | Limits |
|---|---|---|
| `auth-register` | register | 20/hr + 50/day per IP, **3/hr per email** |
| `auth-login` | login | 30/min per IP, 10/min per email |
| `auth-mail` | resend-verification, set-password, password/forgot, password/reset | 20/hr per IP, **5/hr per email** |

Limits are deliberately generous. They are the *floor* — the thing that stops a
volumetric attack — not the mechanism that stops the current slow-drip campaign.
Layers 1 and 2 do that. Login in particular stays forgiving so a bidder
mistyping a password mid-auction is never locked out.

Requests with no email key on IP only; they never share a single "empty email"
bucket, which would let a junk flood exhaust the limit for real users.

### Enumeration

`resendVerification` used to answer three different ways — 422 validation for an
unknown address, 422 `already_verified`, 200 for sent — which let anyone
enumerate which addresses had accounts here and how far through signup each was.
`forgotPassword` had the same defect. Both now return one identical 200 for every
outcome, and the real status is written to the log instead.

## Deployment

### ⚠️ Order matters

**Deploy the frontend first, then the backend.**

The frontend change is inert on its own — it just sends two extra fields the
current API ignores. Once it is live, every real browser is already sending
them, and the backend can begin requiring them with no window in which genuine
signups break.

If the two cannot be sequenced, ship the backend with
`BOT_GUARD_REQUIRE_FORM_SIGNAL=false`, deploy the frontend, then flip it to
`true`. In that mode the decoy degrades to a conventional honeypot (rejected
only if filled) and everything else stays active.

### ⚠️ `TRUSTED_PROXIES` must be set

The API sits behind an AWS ALB. With `TRUSTED_PROXIES` unset, `$request->ip()`
returns **the load balancer's address for every request**, so all clients
collapse into one shared rate-limit bucket.

```env
TRUSTED_PROXIES=*
```

The per-IP ceilings are generous enough that a misconfiguration degrades into
"no useful IP limiting" rather than "nobody can register" — but the limits are
only meaningful once this is set. It also makes `registration_ip_address`
correct, which is what makes the spam attributable at all.

### Environment variables

All optional; every default is the intended production value.

```env
BOT_GUARD_ENABLED=true                  # master off-switch
BOT_GUARD_REQUIRE_FORM_SIGNAL=true      # false during a split deploy
BOT_GUARD_NAME_HEURISTIC=reject         # reject | log | off
BOT_GUARD_MIN_FILL_SECONDS=3
BOT_GUARD_MAX_FORM_AGE_SECONDS=86400
```

### If a real user is ever turned away

1. `BOT_GUARD_NAME_HEURISTIC=log` — keeps the form signal and rate limits, stops
   rejecting on names. No deploy needed.
2. Grep the logs for `bot_guard:` to get the value and score that tripped it.
3. `BOT_GUARD_ENABLED=false` disables everything, as a last resort.

## Cleaning up the existing rows

`users:purge-spam` is a **dry run unless `--force` is passed**. Deleting
production users is irreversible, so the safety model is deny-by-default:

- Only considers ids above `--after-id` (default 40).
- Discovers every table with a foreign key to `users` **from the live schema**,
  and skips any user with a row in one of them. A table added by a future
  migration therefore blocks deletion automatically rather than being silently
  cascaded away.
- Never deletes an account holding the `admin` or `staff` role, whatever its id.
- Prompts for confirmation even with `--force`, unless `--no-interaction`.

```bash
# 1. Review. Changes nothing.
php artisan users:purge-spam --after-id=40

# 2. Same list as a spreadsheet, if you would rather review it outside the terminal.
php artisan users:purge-spam --after-id=40 --export=storage/app/purge-review.csv

# 3. Delete, after confirming at the prompt.
php artisan users:purge-spam --after-id=40 --force

# Spare specific accounts.
php artisan users:purge-spam --after-id=40 --keep=57 --keep=61 --force
```

Anything skipped is printed with the reason (`invoices(2)`, `bids(14)`,
`privileged role`), so the list is auditable before and after.

## Still worth doing (free, no code)

1. **Cloudflare free tier in front of `api.colonialauctions.com`** — Bot Fight
   Mode blocks non-browser clients outright and costs nothing. This is the
   single highest-value remaining lever, and it needs no deploy. Do not put a
   Managed Challenge on `/api/v1/*`: it breaks legitimate `fetch` calls from our
   own frontend.
2. **SES bounce and complaint SNS notifications**, plus the account-level
   suppression list. Without these the reputation damage is invisible until AWS
   sends a warning.
3. Check the current SES reputation dashboard for the complaint rate accrued
   during the campaign.

## Deliberately not done

- **Email normalisation** (folding Gmail dots and `+tags` into a unique column)
  is the correct long-term fix for the dot-variant trick, but it is a data
  migration with collision risk against existing accounts — not a quick fix.
  Layers 1 and 2 stop the current campaign without it.
- **CAPTCHA** is deferred to phase 2 by decision. When it happens,
  **Cloudflare Turnstile** is free, unlimited, and invisible to most users — it
  is not the checkbox puzzle the deferral was about.
