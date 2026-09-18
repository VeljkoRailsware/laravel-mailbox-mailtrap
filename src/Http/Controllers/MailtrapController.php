<?php

namespace VeljkoRailsware\LaravelMailboxMailtrap\Http\Controllers;

use BeyondCode\Mailbox\Facades\Mailbox;
use BeyondCode\Mailbox\InboundEmail;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;
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
 */
class MailtrapController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $raw = (string) $request->getContent();            // raw body, never re-encoded
        $signature = (string) $request->header('Mailtrap-Signature', '');
        $secret = (string) config('mailbox-mailtrap.signing_secret', '');

        if (! WebhookSignature::verify($raw, $signature, $secret)) {
            return response('Invalid signature', 401);
        }

        $payload = json_decode($raw, true);
        $events = is_array($payload['events'] ?? null) ? $payload['events'] : [];
        $onlyInbox = config('mailbox-mailtrap.inbox_id');
        $client = new MailtrapInboundClient(new Config((string) config('mailbox-mailtrap.api_token')));

        $handled = 0;
        foreach ($events as $event) {
            if (($event['event'] ?? '') !== 'inbound.message_received') {
                continue;
            }
            $inboxId = (int) ($event['inbox_id'] ?? 0);
            $messageId = (string) ($event['message_id'] ?? '');
            if ($messageId === '' || ($onlyInbox !== null && $onlyInbox !== '' && (int) $onlyInbox !== $inboxId)) {
                continue;
            }

            $message = ResponseHelper::toArray($client->messages($inboxId)->getById($messageId));
            $rawUrl = (string) ($message['raw_message_url'] ?? '');
            if ($rawUrl === '') {
                continue;
            }
            $mime = Http::timeout(20)->get($rawUrl)->throw()->body();  // signed URL, expires in 3600 s

            /** @var class-string<InboundEmail> $modelClass */
            $modelClass = config('mailbox.model');
            Mailbox::callMailboxes($modelClass::fromMessage($mime));
            $handled++;
        }

        return response("handled {$handled}", 200);
    }
}
