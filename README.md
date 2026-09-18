# laravel-mailbox-mailtrap

Mailtrap Inbound Email driver for [beyondcode/laravel-mailbox](https://github.com/beyondcode/laravel-mailbox).

```bash
composer require veljkorailsware/laravel-mailbox-mailtrap
```

```env
MAILBOX_DRIVER=mailtrap
MAILTRAP_API_TOKEN=...
MAILTRAP_INBOUND_SIGNING_SECRET=...
```

Point your Mailtrap `inbound_receiving` webhook at `https://your-app/laravel-mailbox/mailtrap`, then route mail as usual:

```php
Mailbox::from('{user}@inbound-mailtrap.io', function (InboundEmail $email, $user) { ... });
```

How it works: Mailtrap's webhook is a batch of thin events (`events[].message_id`), signed with HMAC-SHA256 over the raw body in the `Mailtrap-Signature` header. The driver verifies the signature, fetches each message, downloads its raw MIME from `raw_message_url` and passes it to laravel-mailbox's `InboundEmail::fromMessage()`.

MIT.
