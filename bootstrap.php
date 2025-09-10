<?php

use App\Services\Hook;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Route;

return function (Dispatcher $events) {
    config(['mail.mailers.oci' => [
        'transport' => 'oci',
        'endpoint' => env('OCI_EMAIL_ENDPOINT'),
        'tenancy_id' => env('OCI_EMAIL_TENANCY_ID'),
        'compartment_id' => env('OCI_EMAIL_COMPARTMENT_ID'),
        'user_id' => env('OCI_EMAIL_USER_ID'),
        'key_fingerprint' => env('OCI_EMAIL_KEY_FINGERPRINT'),
        'private_key_file' => env('OCI_EMAIL_PRIVATE_KEY_FILE', storage_path('oci-email-private-key.pem')),
    ]]);

    if(env('OCI_EMAIL_VERBOSE_LOG', false)) {
        config(['logging.channels.oci-email-delivery' => [
            'driver' => 'daily',
            'days' => 14,
            'path' => storage_path('logs/sendmail-oci.log'),
        ]]);
    } else {
        config(['logging.channels.oci-email-delivery' => [
            'driver' => 'null',
        ]]);
    }

    app('mail.manager')->extend('oci', function () {
        return new LittleSkin\Sendmail\OCIEmailDelivery\OCIEmailDeliveryTransport();
    });

    // LittleSkin proprietary

    Hook::addRoute(function ($routes) {
        Route::post('oci-email/notifications/webhook', 'LittleSkin\Sendmail\OCIEmailDelivery\Controllers\NotificationController@webhook')
            ->middleware(['api', 'LittleSkin\Sendmail\OCIEmailDelivery\Middlewares\BasicAuthenticate']);
    });

    $events->listen(
        'user.email.verification.sending',
        'LittleSkin\Sendmail\OCIEmailDelivery\Listeners\OnUserEmailVerificationSending@handle'
    );
};
