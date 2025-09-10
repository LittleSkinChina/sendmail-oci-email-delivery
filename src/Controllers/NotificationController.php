<?php

namespace LittleSkin\Sendmail\OCIEmailDelivery\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NotificationController extends Controller {

    public function webhook(Request $request) {

        if ($request->header('X-OCI-NS-MessageType') == 'SubscriptionConfirmation') {
            Http::get($request->input('ConfirmationURL'));
            return response()->noContent(200);
        }

        $action = $request->input('data.action');
        $recipient = $request->input('data.recipient');
        $messageId = $request->input('data.messageId');
        if($action == 'bounce') {
            Log::channel('oci-email-delivery')->info("[Notification] Mail sent to [{$recipient}] got bounced, messageId=<{$messageId}>");
            Cache::put("oci-email-delivery-suppress-{$recipient}", true, 86400);
        }

        return response()->noContent(200);
    }
}
