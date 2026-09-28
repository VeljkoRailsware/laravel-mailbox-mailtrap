<?php

namespace VeljkoRailsware\LaravelMailboxMailtrap\Http\Controllers;

use BeyondCode\Mailbox\Facades\Mailbox;
use BeyondCode\Mailbox\InboundEmail;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mailtrap\Config;
use Mailtrap\Helper\ResponseHelper;
use Mailtrap\Helper\WebhookSignature;
use Mailtrap\MailtrapInboundClient;

/**
 * Receives Mailtrap "inbound_receiving" webhooks.
 *
 * Mailtrap's webhook is a thin notification: a batch of events, each carrying a
 * message_id and inbox_id, no bodies. This controller verifies the HMAC over the
 * raw body, then for every event fetches the parsed message, downloads the raw
 * MIME from its signed raw_message_url and hands it to laravel-mailbox.
 *
 * Response contract. Mailtrap re-delivers a batch on any non-2xx response, every
 * five minutes for about three hours. Input that a retry cannot fix (malformed
 * JSON, an event without usable identifiers, an unparseable message) is logged
 * and skipped, and the batch still returns 200, so one bad event cannot hold the
 * rest of its batch in the retry loop. 5xx is reserved for conditions a retry or
 * an operator can fix: a bad inbox_id setting (503) and a raw message that could
 * not be downloaded (502). Anything unexpected from the SDK still surfaces as 500.
 * Retries repeat the callbacks of events that were handled before the failure;
 * see README "Known limitations".
 */
class MailtrapController extends Controller
{
    private const INBOUND_EVENT = 'inbound.message_received';

    public function __invoke(Request $request): Response
    {
        $raw = (string) $request->getContent();            // raw body, never re-encoded
        $signature = (string) $request->header('Mailtrap-Signature', '');
        $secret = (string) config('mailbox-mailtrap.signing_secret', '');

        if (! WebhookSignature::verify($raw, $signature, $secret)) {
            return response('Invalid signature', 401);
        }

        $onlyInbox = config('mailbox-mailtrap.inbox_id');
        if ($onlyInbox !== null && $onlyInbox !== '' && ! self::isPositiveId($onlyInbox)) {
            Log::error('mailbox-mailtrap.inbox_id must be a positive integer; refusing the delivery');
            return response('Invalid inbox configuration', 503);
        }
        $onlyInbox = ($onlyInbox === null || $onlyInbox === '') ? null : (int) $onlyInbox;

        $handled = 0;
        $skipped = 0;

        try {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            Log::warning('Mailtrap webhook body is not valid JSON; skipped');
            return $this->ok($handled, ++$skipped);
        }
        $events = $payload['events'] ?? null;
        if (! is_array($payload) || ! is_array($events) || ! array_is_list($events)) {
            Log::warning('Mailtrap webhook has no events list; skipped');
            return $this->ok($handled, ++$skipped);
        }

        // Validate the whole batch before any side effect, so a bad event is
        // never discovered halfway through a batch of callbacks.
        $accepted = [];
        foreach ($events as $event) {
            if (! is_array($event) || ! is_string($event['event'] ?? null)) {
                Log::warning('Mailtrap webhook event is not an object with an event type; skipped');
                $skipped++;
                continue;
            }
            if ($event['event'] !== self::INBOUND_EVENT) {
                continue;                                  // other webhook types are not ours
            }
            $inboxId = $event['inbox_id'] ?? null;
            $messageId = $event['message_id'] ?? null;
            if (! self::isPositiveId($inboxId) || ! is_string($messageId) || trim($messageId) === '') {
                Log::warning('Mailtrap inbound event lacks a usable inbox_id or message_id; skipped');
                $skipped++;
                continue;
            }
            if ($onlyInbox !== null && (int) $inboxId !== $onlyInbox) {
                continue;                                  // another inbox on the same account
            }
            $accepted[] = [(int) $inboxId, $messageId];
        }

        if ($accepted === []) {
            return $this->ok($handled, $skipped);
        }

        $client = new MailtrapInboundClient(new Config((string) config('mailbox-mailtrap.api_token')));
        /** @var class-string<InboundEmail> $modelClass */
        $modelClass = config('mailbox.model');

        foreach ($accepted as [$inboxId, $messageId]) {
            $message = ResponseHelper::toArray($client->messages($inboxId)->getById($messageId));
            $rawUrl = $message['raw_message_url'] ?? null;
            if (! is_string($rawUrl) || $rawUrl === '') {
                Log::error('Mailtrap message response has no raw_message_url; skipped', ['message_id' => $messageId]);
                $skipped++;
                continue;
            }

            // The URL comes from the authenticated SDK response, not from the webhook body.
            // HTTPS, no credentials and no redirects are a first boundary, not a host allowlist.
            $url = parse_url($rawUrl);
            if ($url === false || ($url['scheme'] ?? '') !== 'https'
                || empty($url['host']) || isset($url['user']) || isset($url['pass'])) {
                Log::error('Mailtrap raw_message_url is not a plain https URL; refusing the delivery', ['message_id' => $messageId]);
                return response('Unsupported raw message URL', 502);
            }
            try {
                $download = Http::timeout(20)->withOptions(['allow_redirects' => false])->get($rawUrl);  // signed URL, expires in 3600 s
            } catch (ConnectionException $e) {
                Log::error('Mailtrap raw message download failed: '.$e->getMessage(), ['message_id' => $messageId]);
                return response('Raw message download failed', 502);
            }
            if (! $download->successful()) {
                Log::error('Mailtrap raw message download returned HTTP '.$download->status(), ['message_id' => $messageId]);
                return response('Raw message download failed', 502);
            }

            $email = $modelClass::fromMessage($download->body());
            if (! $email->isValid()) {
                Log::error('Mailtrap raw message is not a parseable email; skipped', ['message_id' => $messageId]);
                $skipped++;
                continue;
            }
            Mailbox::callMailboxes($email);
            $handled++;
        }

        return $this->ok($handled, $skipped);
    }

    private function ok(int $handled, int $skipped): Response
    {
        return response("handled {$handled} skipped {$skipped}", 200);
    }

    /** A positive integer id, given as int or as a string of digits without sign, spaces or suffix. */
    private static function isPositiveId(mixed $value): bool
    {
        return (is_int($value) || is_string($value))
            && preg_match('/^[1-9][0-9]*$/D', (string) $value) === 1
            && filter_var($value, FILTER_VALIDATE_INT) !== false;
    }
}
