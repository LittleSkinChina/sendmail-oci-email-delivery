<?php

namespace LittleSkin\Sendmail\OCIEmailDelivery\Middlewares;

use Illuminate\Http\Request;

class BasicAuthenticate {
    public function handle(Request $request, \Closure $next) {
        $username = env('OCI_EMAIL_NOTIFICATION_AUTH_USERNAME');
        $password = env('OCI_EMAIL_NOTIFICATION_AUTH_PASSWORD');

        if ($request->getUser() !== $username || $request->getPassword() !== $password) {
            return response('', 401)->header('WWW-Authenticate', 'Basic');
        }

        return $next($request);
    }
}