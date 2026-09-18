<?php

namespace App\Http\Controllers;

use App\Enums\SuppressionReason;
use App\Models\SuppressedEmailAddress;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SES bounce and complaint feedback, delivered by SNS.
 *
 * Two things authorise a call: the signature in the URL, because SNS posts to
 * the exact URL the topic was subscribed with, and the topic ARN in the body,
 * which has to be ours. Without the second check anyone who ever saw the signed
 * link could suppress an arbitrary address.
 *
 * Everything gets a 200, including the payloads we ignore. A non-2xx makes SNS
 * retry and eventually disable the subscription, which would cost us the
 * feedback entirely.
 */
class SesFeedbackController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $payload = $request->json()->all();
        $topicArn = (string) config('services.ses.topic_arn');

        // SNS posts `text/plain`, so the body is read through json() rather than
        // the usual input bag, which only decodes JSON content types.
        if ($topicArn === '' || ($payload['TopicArn'] ?? '') !== $topicArn) {
            abort(403);
        }

        match ($payload['Type'] ?? null) {
            'SubscriptionConfirmation' => $this->confirmSubscription((string) ($payload['SubscribeURL'] ?? '')),
            'Notification' => $this->suppressRecipients((array) json_decode((string) ($payload['Message'] ?? ''), true)),
            default => null,
        };

        return response('', 200);
    }

    /**
     * Visiting the URL SNS sends is what activates the subscription. The host is
     * checked first: the URL comes from the request body, and fetching whatever
     * it points at would turn this endpoint into an SSRF hole.
     */
    private function confirmSubscription(string $subscribeUrl): void
    {
        $parts = parse_url($subscribeUrl);
        $host = (string) ($parts['host'] ?? '');

        if (($parts['scheme'] ?? '') !== 'https' || preg_match('/^sns\.[a-z0-9-]+\.amazonaws\.com$/', $host) !== 1) {
            Log::warning('Refused to confirm an SNS subscription from an unexpected URL', ['host' => $host]);

            return;
        }

        Http::timeout(10)->get($subscribeUrl);
    }

    /**
     * Only permanent bounces and complaints suppress. Everything else — a
     * delivery, a transient bounce, an `Undetermined` one — is left alone: SES
     * retries the transient ones itself, and suppressing on a guess would
     * silence mailboxes that are working.
     *
     * @param  array<string, mixed>  $message
     */
    private function suppressRecipients(array $message): void
    {
        $type = $message['notificationType'] ?? $message['eventType'] ?? null;

        if ($type === 'Complaint') {
            $this->suppressAll(data_get($message, 'complaint.complainedRecipients'), SuppressionReason::Complaint);

            return;
        }

        if ($type === 'Bounce' && data_get($message, 'bounce.bounceType') === 'Permanent') {
            $this->suppressAll(data_get($message, 'bounce.bouncedRecipients'), SuppressionReason::Bounce);
        }
    }

    private function suppressAll(mixed $recipients, SuppressionReason $reason): void
    {
        foreach ((array) $recipients as $recipient) {
            SuppressedEmailAddress::suppress((string) data_get($recipient, 'emailAddress', ''), $reason);
        }
    }
}
