# laravel-mailbox-mailtrap

Mailtrap Inbound Email driver for [beyondcode/laravel-mailbox](https://github.com/beyondcode/laravel-mailbox).
Point a Mailtrap `inbound_receiving` webhook at your Laravel app and route the mail with the `Mailbox` facade you already use.

Requires PHP 8.2+, Laravel 10 to 13, `beyondcode/laravel-mailbox` ^6.0 and `railsware/mailtrap-php` ^3.15.

## Install

```bash
composer require beyondcode/laravel-mailbox veljkorailsware/laravel-mailbox-mailtrap
```

The package needs a tagged release on Packagist for that command to resolve. Until the first tag is published, add the repository to your `composer.json` and require the development branch:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/VeljkoRailsware/laravel-mailbox-mailtrap" }
]
```

```bash
composer require beyondcode/laravel-mailbox veljkorailsware/laravel-mailbox-mailtrap:dev-main
```

## Setup

Publish the two config files and the inbound-email migration, then migrate:

```bash
php artisan vendor:publish --provider="BeyondCode\Mailbox\MailboxServiceProvider" --tag=config
php artisan vendor:publish --provider="BeyondCode\Mailbox\MailboxServiceProvider" --tag=migrations
php artisan vendor:publish --provider="VeljkoRailsware\LaravelMailboxMailtrap\MailtrapMailboxServiceProvider" --tag=config
php artisan migrate
```

Configure the driver in `.env`:

```env
MAILBOX_DRIVER=mailtrap
MAILTRAP_API_TOKEN=...                 # account API token with inbound message read access
MAILTRAP_INBOUND_SIGNING_SECRET=...    # shown in the webhook's details in Mailtrap, copyable at any time
MAILTRAP_INBOUND_INBOX_ID=             # optional, see "Restricting to one inbox"
```

In Mailtrap, create an `inbound_receiving` webhook for your inbox and point it at:

```
https://your-app.example/laravel-mailbox/mailtrap
```

The first path segment is `mailbox.path` from `config/mailbox.php`; the second is `route` in `config/mailbox-mailtrap.php`. Then route mail as usual:

```php
use BeyondCode\Mailbox\Facades\Mailbox;
use BeyondCode\Mailbox\InboundEmail;

Mailbox::to('{user}@inbound-mailtrap.io', function (InboundEmail $email, string $user) {
    // $email->from(), ->subject(), ->text(), ->html(), ->attachments(), ->reply(...)
});
```

### Restricting to one inbox

`MAILTRAP_INBOUND_INBOX_ID` accepts one positive integer. When set, events from any other inbox on the account are ignored. Null or empty accepts every inbox. Any other value, including `0`, is a configuration error: the webhook answers `503` until it is fixed, so Mailtrap keeps retrying and no message is lost.

## How it works

Mailtrap's webhook is a batch of thin events (`events[].message_id`, `events[].inbox_id`), signed with HMAC-SHA256 over the raw request body in the `Mailtrap-Signature` header. The driver verifies the signature, validates every event in the batch, then for each accepted event fetches the message through the Mailtrap SDK, downloads its raw MIME from the signed `raw_message_url` and hands it to laravel-mailbox's `InboundEmail::fromMessage()`.

### Response contract

Mailtrap re-delivers a batch on any non-2xx response, every five minutes for about three hours. The driver therefore only answers non-2xx when a retry can help.

| Situation | Response | Retried by Mailtrap |
|---|---|---|
| Signature missing, wrong, or secret not configured | `401` | yes, and every retry fails until the secret matches |
| Batch processed; body is `handled N skipped M` | `200` | no |
| Body is not JSON, `events` is not a list, an event has no usable `inbox_id`/`message_id`, the SDK response has no `raw_message_url`, or the raw MIME does not parse | `200`, the item is logged (`warning` for bad webhook input, `error` for bad upstream data) and counted in `skipped` | no. A retry could not fix the input, and a 4xx would hold every other event in the batch in the retry loop |
| `inbox_id` setting is not a positive integer | `503` | yes, until the setting is fixed |
| `raw_message_url` is not a plain `https://` URL, or the download fails or times out | `502` | yes |
| Unexpected SDK failure (bad token, message not found) | `500` | yes |

Events with a type other than `inbound.message_received`, and events from an inbox other than the configured one, are ignored silently and count in neither number.

### Authentication is signature-only

The route is registered outside the `web` middleware group (no CSRF, no session) and is protected by the body signature alone. The `mailbox.basic_auth` credentials from `config/mailbox.php` are deliberately **not** applied to this route: laravel-mailbox added Basic auth for Postmark, which has no body signature. Mailtrap signs the body, so a second, weaker credential on the same request buys nothing. An empty or missing signing secret fails closed: every request is rejected with `401`.

## Known limitations

1. **Retries repeat completed callbacks.** There is no delivery deduplication. If event 3 of a batch fails after events 1 and 2 ran their callbacks, the retry runs events 1 and 2 again and stores their rows again. laravel-mailbox also runs the callbacks *before* it stores the row, and its `message_id` column has no unique index. Until this is fixed, **your mailbox handlers must tolerate repeated deliveries** of the same message (key any external side effect on `$email->id()`, the MIME `Message-ID`). Tracked in [#1](https://github.com/VeljkoRailsware/laravel-mailbox-mailtrap/issues/1).
2. **Everything runs inside the webhook request.** Each accepted event costs one SDK call and one download before the 200 is sent; there is no queue option. Large batches and slow upstreams push the response time toward Mailtrap's delivery timeout. Tracked in [#2](https://github.com/VeljkoRailsware/laravel-mailbox-mailtrap/issues/2).
3. **The raw URL check is a boundary, not an allowlist.** The driver requires `https://`, refuses credentials in the URL and does not follow redirects. It does not pin the host: the URL comes from the authenticated SDK response, and Mailtrap's storage hosts are not a documented contract.

## Tested versions

The feature suite (`composer test`, see [TESTING.md](TESTING.md)) last ran green with PHP 8.5.10, Laravel 13.33.0, Orchestra Testbench 11.3.0, PHPUnit 12.5.36, `beyondcode/laravel-mailbox` 6.0.0 and `railsware/mailtrap-php` 3.15.0. Install and boot were also checked in a fresh Laravel 12.69.2 app. The other declared combinations (PHP 8.2 to 8.4, Laravel 10 and 11) resolve but have not been exercised. Behaviour against a live inbox is listed as open in TESTING.md.

## License

MIT.
