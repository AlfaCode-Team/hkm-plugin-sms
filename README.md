# hkm-plugin-sms

> HKM Kernel plugin — provides **`sms.delivery`**.
> Part of the [HKM Kernel](https://github.com/AlfaCode-Team/hkm-kernel) Gated Demand Architecture framework.

![PHP](https://img.shields.io/badge/PHP-8.4%2B-777bb4)

Binds the kernel's `SmsPort`, which had no adapter. No vendor SDK: Twilio over
its REST API through the kernel's `HttpClientPort`.

```json
{ "requires": ["sms.delivery"] }
```

## Install

```bash
composer require alfacode-team/hkm-plugin-sms
# or, from a project:
hkm plugins add sms
```

## Which dependency to declare

| You need | Depend on |
| --- | --- |
| To send a message | `SmsPort` — `send(to, message): void`, and nothing else, so your code stays portable across any future adapter |
| The provider's message id, the cost, or the mock-number mapping | `SmsSenderContract` |

Prefer the port. The richer contract exists for the three things the port
deliberately cannot express.

## Configuration

| Variable | Default | Notes |
| --- | --- | --- |
| `SMS_TRANSPORT` | `log` | `twilio`, `log` or `mock`. An unknown name **fails** rather than falling back |
| `SMS_FROM` | — | Default sender for any transport |
| `SMS_MAX_SEGMENTS` | `0` (no ceiling) | Refuse a body costing more than this. `0` means unset, because an int-typed config var cannot be absent |
| `SMS_TIMEOUT` | `15` | Seconds |
| `SMS_MOCK_NUMBERS` | — | `+256700000000:123456,+14155550100:000000` — destinations that are **never sent to** |
| `SMS_TWILIO_ACCOUNT_SID` | — | |
| `SMS_TWILIO_AUTH_TOKEN` | — | |
| `SMS_TWILIO_FROM` | — | A purchased number or alphanumeric sender id |
| `SMS_TWILIO_MESSAGING_SERVICE_SID` | — | Takes precedence over `FROM`, matching Twilio's own order |

**The default is `log`, not a live provider.** Defaulting to Twilio would make a
missing credential a boot failure for an application that installed the plugin
before configuring it — and the first thing to break would be a request that
worked yesterday.

## An SMS is billed per segment, and nothing in a send path tells you how many

This is the part worth reading. `Segmentation` computes it before anything is
sent, and `sms:cost` prints it.

```bash
hkm cli sms:cost "Your code is 123456. Don't share it."
```

The rules are not intuitive:

- **One character outside the GSM 7-bit alphabet re-encodes the whole message**
  to UCS-2, dropping capacity from 160 characters to **70**. A single emoji, or
  a curly apostrophe (`’`, U+2019) pasted from a word processor, or an invisible
  non-breaking space from copied web text, turns a one-segment OTP into three.
- **Nine ordinary-looking characters cost two septets each** — `| ^ € { } [ ] ~ \`
  are in the GSM *extension* table. 80 euro signs fill a segment; 81 overflow it.
- **Concatenation is not free.** A 161-character GSM-7 body is not 160 + 1; it is
  two segments of 153, because each segment spends 6 octets on a header.

When a body exceeds `SMS_MAX_SEGMENTS` the refusal names the encoding, because
the cause is usually one character nobody can see. `sms:cost` goes further and
lists the offending characters with their code points — being told "this is
UCS-2" while staring at what looks like plain English is not actionable.

Verified against 3GPP TS 23.038: the boundaries at 160/161, 306/307, 80/81 euro
signs, 70/71 Cyrillic characters and 35/36 emoji are each asserted as exact
values, and the full 128-character basic alphabet is asserted to stay GSM-7 so a
transcription slip in the table cannot pass.

## Destinations are validated as E.164 before anything is billed

A malformed number does not fail loudly: a provider accepts the request, queues
it, **bills for it**, and reports the failure later through a channel this plugin
does not yet read. So the send path returns success and the SMS never arrives.

`PhoneNumber` normalises what humans and spreadsheets produce — `+256 700 000 000`,
`+1 (415) 555-0100`, a leading `00`, non-breaking spaces — and refuses what
cannot be sent: no country code, a trunk-prefix zero after the `+`, letters,
too long for E.164, too short to be international.

**There is no default country code, on purpose.** Guessing one sends a message
to the right digits in the wrong country, so a bare national number is refused
with a message that says the plugin will not guess.

Numbers are **masked** (`+**********00`) everywhere this plugin logs or prints.
A phone number is personal data and a log is where it leaks from.

## Mock destinations: what makes a phone-OTP flow testable

```dotenv
SMS_MOCK_NUMBERS=+256700000000:123456
```

A listed destination **never reaches a provider**, whatever `SMS_TRANSPORT` is
set to, and `mockCodeFor()` returns the fixed code so an OTP flow can use it
instead of generating one. That is what lets sign-in be driven end to end in a
test or a review environment without spending money — and the alternative, a
hand-rolled stub per test suite, drifts from the real transport until the tests
pass against behaviour no provider has.

Two safeguards, because this feature fails in the dangerous direction:

- a diverted send is logged at **warning**, not info — a real customer's number
  in this list produces one undiagnosable symptom, that one specific person
  never receives anything;
- `sms:status` reports the count and the masked numbers it actually parsed.

Mock numbers are normalised to E.164 **in the config constructor**, not in the
factories. That was a bug this plugin's own test suite caught: `SmsConfig::of()`
took the map verbatim, so a key written as `00256 700 000 000` could never match
the E.164 lookup, the destination was **not** diverted, and a message meant to be
swallowed would have gone to a real handset.

## Commands

| Command | Sends anything? |
| --- | --- |
| `sms:status` | No. Reports the transport, credentials present/missing, the sender, diversions |
| `sms:cost "body"` | No. Encoding, length, segments, and the characters responsible for an escalation |
| `sms:send --to +… --body "…"` | **Yes, and it is billed.** Confirms first and prints the segment count before asking |

`sms:status` does **not** verify a credential, and says so in its output. Unlike
FCM's `validate_only`, no provider here offers a validate-only call, so the only
proof a credential works is a real message to a real handset — which is what
`sms:send` is for.

## Retries are the caller's decision, never the transport's

The transport passes `retry: 0` to the HTTP client and never retries by itself.
Twilio bills on acceptance, and a timeout does not say whether the message was
accepted — so an automatic retry can deliver the same SMS twice and charge for
both, and an SMS cannot be recalled.

`SmsException` carries a `retryable` flag instead: `true` for 429 and 5xx, `false`
for every other 4xx (a bad number or an unverified sender is rejected identically
on every attempt), and **absent** when the transport cannot tell — a connection
that broke mid-request may or may not have been accepted. Callers treat absent as
"do not retry".

## Testing

```bash
HKM_KERNEL_PATH=/path/to/hkm-kernel vendor/bin/phpunit
```

The suite drives the transport through a scripted `HttpClientPort` and asserts
the **exact request** against Twilio's published shape — URL, Basic auth header,
form field names — because that is the half of the behaviour this plugin owns,
and a field named `Message` instead of `Body` produces a billed request that
delivers nothing.

The `network` group is **excluded by default** so a plain run cannot spend money.

## Verified / not verified

**Verified**: 71 tests, 161 assertions. `ground check --strict` reports clean
against the GDA rules. Segment arithmetic is asserted against 3GPP TS 23.038 at
every boundary, and four behaviours were confirmed load-bearing by reverting them
and watching tests fail — the extension table costing two septets (4 failures),
surrogate pairs counting as two code units (3), mock diversion (1), and
mock-number normalisation (1).

**Not verified:**

- **No request has ever reached Twilio.** Not one message has been sent, and no
  credential has been authenticated. The request shape comes from Twilio's
  published API, and a mistaken reading of it is exactly the class of error this
  suite cannot catch.
- **Twilio's error codes are surfaced, not interpreted.** Retryability is decided
  from the HTTP status alone. Specific codes — an unverified trial number, a
  recipient who replied STOP — are passed through with their message intact
  rather than mapped, because mapping them would mean asserting behaviour that
  has not been observed here.
- **Delivery is never confirmed.** `SendResult` means the provider *accepted* the
  message. There is no inbound webhook receiver, so a delivery report is not read
  and there is deliberately no `delivered` field for a caller to misread.
- **One provider.** Only Twilio. The MarzPay market coverage this is ultimately
  for will need Infobip or Msg91 as well, and neither is written.
- **No outbox.** A failed send is reported to the caller and not persisted, so
  nothing retries it later. The push plugin's outbox is the pattern to follow
  when that is needed.

## License

MIT — see [LICENSE](LICENSE).
