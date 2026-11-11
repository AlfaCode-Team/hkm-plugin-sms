# Changelog

All notable changes to `hkm-plugin-sms` are documented here. The format is based
on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **First release: an adapter for the kernel's `SmsPort`, which had none.**
  Twilio over its REST API through `HttpClientPort` — no vendor SDK, so a project
  installing this adds nothing to its own `composer.json`. Solves `sms.delivery`.

- **Segment costing before anything is sent.** An SMS is billed per segment and
  no send path reports how many were bought. `Segmentation` computes it, and
  `sms:cost` prints it along with the characters responsible when a body has
  escalated. The rules it implements are the ones that catch people out: a single
  non-GSM character re-encodes the whole message to UCS-2 and drops capacity from
  160 characters to 70; the nine extension-table characters `| ^ € { } [ ] ~ \`
  cost two septets each; and concatenation spends 6 octets per segment on a
  header, so a 161-character body is two segments of 153 rather than 160 + 1.
  Asserted against 3GPP TS 23.038 at every boundary, including 35 emoji filling
  exactly one UCS-2 segment because a surrogate pair is two code units.

- **E.164 validation with no default country code.** A malformed number is
  refused at the call site instead of being accepted, queued and billed by a
  provider that reports the failure through a channel this plugin does not read.
  Formatting that humans and spreadsheets produce is normalised away; a bare
  national number is refused, and the message says the plugin will not guess a
  country, because guessing sends the message to the right digits in the wrong
  one.

- **Mock destinations, which is what makes a phone-OTP flow testable.** A number
  in `SMS_MOCK_NUMBERS` never reaches a provider whatever the transport is, and
  carries a fixed code an OTP flow uses instead of generating one. Diverted sends
  log at **warning**, not info: a real customer's number in that list produces
  exactly one symptom — that one person never receives anything — and it is close
  to undiagnosable without the line.

- **An unknown `SMS_TRANSPORT` is refused rather than defaulted.** A typo
  silently falling back to `log` would mean every message in production going to
  a log file while every call reported success.

- **Numbers are masked everywhere this plugin logs or prints** (`+**********00`),
  and `describe()` reports diversions as a count rather than as numbers, because
  status output gets pasted into issues and chat.

- **No transport-level retries.** Twilio bills on acceptance and a timeout does
  not say whether the message was accepted, so an automatic retry can deliver the
  same SMS twice and charge for both. `SmsException` carries a `retryable` flag —
  true for 429/5xx, false for other 4xx, and **absent** when the transport cannot
  tell — and the caller decides.

### Fixed during development

- **A hand-wired mock destination would have been sent to for real.**
  `SmsConfig::of()` took its mock-number map verbatim while `isMock()` looked up
  the E.164 form, so a key written as `00256 700 000 000` could never match: the
  destination was not diverted and a message meant to be swallowed would have
  gone to a handset. Normalisation moved into the constructor, which is the one
  place every path goes through. Caught by this plugin's own test suite.

- **`SmsException` was in the Domain layer and imported a kernel class.** The
  Domain layer may import nothing outside `Domain/`. It is a gateway concern and
  now lives in `Infrastructure/Exceptions`, matching how the Push plugin splits
  the same problem. Caught by `ground check`, not by the tests or the linter.
