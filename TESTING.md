# Testing notes

For whoever reviews this driver next, with or without a real Mailtrap inbox.

## Running the suite

```bash
composer install
composer test            # = vendor/bin/phpunit -c phpunit.xml
```

No network and no Mailtrap credentials are needed. The suite swaps the SDK's PSR-18 client for an in-memory fake through `php-http/discovery` (see `SdkFake` and `FakeDiscovery` at the top of the test file) and fakes Laravel's `Http` client with `Http::preventStrayRequests()`, so a test that tried to reach the internet would fail loudly. The signing secret is `synthetic-qa-secret` and the API token is `synthetic-not-a-token`; neither is real.

Each test prints one evidence line to stdout in the form `QA <ID> {json}`. Run with `--filter <method>` to see one, or grep the output for `^QA`.

Fixtures: `tests/Fixtures/rich.eml` is a multipart message (text + HTML + inline PNG + PDF attachment) with sanitised headers and `example.test` addresses. `tests/Fixtures/events.json` is the five-event shape of a real Mailtrap delivery with synthetic ids.

## What each QA id proves

| ID | Test | Kind | What green means |
|---|---|---|---|
| F01 | `test_f01_rich_mime_routes_attachments_and_reply` | contract | A signed event fetches the message, downloads the raw MIME, routes it through `Mailbox::to()`, parses sender, recipient, UTF-8 subject, text, HTML, inline image (cid + disposition) and PDF (bytes + type), and `reply()` addresses the sender with `In-Reply-To` set. Reply goes through the array transport, not SMTP. |
| F02 | `test_f02_inbox_filter_is_strict` | contract | Null/empty filter accepts any inbox; a set filter accepts only that integer; `"0"` as a filter is a 503; an event with a non-integer `inbox_id` is skipped; `"4242oops"` does not match `4242`. |
| F03 | `test_f03_real_five_event_shape` | contract | The real five-event delivery shape produces exactly five SDK fetches and five routed messages. |
| F04 | `test_f04_signature_route_has_no_basic_auth_middleware` | contract | The route has no middleware; `mailbox.basic_auth` is not applied (by design, see README "Authentication is signature-only"). |
| E02 | `test_e02_sdk_401_and_404_escape_as_500` | contract | An SDK 401 or 404 aborts the batch with 500, nothing routed. A retryable signal; the coupling to E01 is the open defect. |
| E02 | `test_e02_raw_403_and_timeout_return_502` | contract | A raw download that returns 403 or times out aborts the batch with 502 and one `error` log line each. |
| E04 | `test_e04_unfixable_input_is_logged_and_skipped` | contract | Unknown event types and empty batches are ignored silently. Malformed JSON, a non-list `events`, a scalar event, a missing or array `message_id`, and an SDK response without `raw_message_url` are each logged and counted in `skipped`, the response stays 200, and a bad event does not stop the good ones in the same batch (`handled 1 skipped 1`). No SDK call is made for invalid events. |
| E04 | `test_e04_invalid_raw_mime_is_skipped_not_handled` | contract | A raw body that is not an email is logged and skipped: `handled 0 skipped 1`, nothing routed, no row stored. Before the fix this answered `handled 1`. |
| A01 | `test_a01_signatures_bind_exact_body` | contract | Empty, wrong-length and wrong-value signatures are 401 with zero SDK calls; a single trailing byte on the body invalidates a correct signature. |
| A02 | `test_a02_empty_and_null_secrets_fail_closed` | contract | With an empty or null configured secret, a request signed with the empty key is still 401. |
| A03 | `test_a03_non_https_or_credentialed_raw_url_is_refused` | contract | `http://`, `ftp://`, a URL with credentials and a non-URL from the SDK are each refused with 502 before any download. Redirect refusal (`allow_redirects => false`) is not exercised by the fake. |
| E01 | `test_e01_partial_batch_retry_duplicates_first_side_effect` | **characterisation** | Issue #1. Event 2 of 3 fails (500); the replay runs event 1's callback again: `m1, m1, m2, m3`, four rows. |
| E02 | `test_e02_callback_failure_repeats_prior_side_effect` | **characterisation** | Issue #1. A throwing callback yields 500 and no stored row, and the retry runs the callback again. |
| E05 | `test_e05_replays_are_not_deduplicated` | **characterisation** | Issue #1. The same message delivered three times routes three times and stores three rows. |
| E03 | `test_e03_twelve_events_are_processed_synchronously` | **characterisation** | Issue #2. Twelve events make 12 SDK calls and 12 downloads before the 200; with a 50 ms fake delay per call the request takes over 1.2 s. The number is fake timing, not a measurement of Mailtrap or of the SDK. |

Characterisation tests are green **while the defect exists**. They document the current behaviour so a fix is visible as a test change. When #1 or #2 is fixed, flip the assertions and move the test into the contract section.

## What changed in this branch, and which tests moved

| Before (`d5a92a0`) | Now | Test that changed |
|---|---|---|
| Malformed JSON, missing ids, scalar events, missing `raw_message_url`: 200 with no log line | 200, logged, counted in `skipped` | E04 |
| Unparseable MIME: `handled 1`, nothing routed | `handled 0 skipped 1`, logged | E04 |
| Array `message_id`: 500 before any SDK call | skipped with a warning, 200 | E04 |
| Filter `"0"` treated as a filter; `"4242oops"` coerced to 4242 | 503 for the bad setting; non-integer event ids skipped | F02 |
| Any `raw_message_url` downloaded, redirects followed | https only, no credentials, no redirects; 502 otherwise | A03 |
| Raw 403 / timeout: 500 via `->throw()` | 502 with an `error` log line | E02 |
| Response body `handled N` | `handled N skipped M` | all |

Not changed: the retry-duplicate behaviour (#1), synchronous processing (#2), signature-only authentication (F04, by decision).

## What still needs a human with a real inbox

None of this can be measured locally. Please record results in issues #1 and #2 or in a new issue.

1. **Live signed delivery.** After a tagged release, install from Packagist in a clean app exactly as the README says, configure the webhook, and confirm a real delivery is routed and a wrong or absent signature is rejected. The signing secret stays viewable in the webhook details; it is not show-once.
2. **Rich MIME and reply over SMTP.** Send a message with text, HTML, an inline image and an attachment to the inbox; compare the downloaded attachment bytes with the originals; send `$email->reply()` through a real mailer and confirm it arrives with `In-Reply-To`. F01 proves parsing and the generated headers only.
3. **Batches, other inboxes, redelivery cadence, sender timeout.** Send a burst so Mailtrap batches events; send to a second inbox with the filter set; make event 2 fail (revoke the token briefly) and capture the actual redeliveries and whether `event_id` changes; measure the time to 200 against Mailtrap's webhook timeout. The expected cadence is every five minutes for about three hours, observed once; the batch tick is about 30 seconds, also observed once.
4. **Raw-URL expiry and refresh.** Let a `raw_message_url` age past an hour and observe what the download returns (expected 403, which the driver turns into 502 and Mailtrap retries with a fresh lookup). The one-hour lifetime is from the API response, not measured here.
5. **Plan size cap.** Send messages near and above the account's encoded-message limit and record the SMTP response, whether a message is stored, and whether a webhook fires. The limit is plan-specific; check the account's value first.
