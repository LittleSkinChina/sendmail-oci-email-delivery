<?php

namespace LittleSkin\Sendmail\OCIEmailDelivery\Listeners;

use Blessing\Filter;
use Blessing\Rejection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class OnUserEmailVerificationSending {

    /** @var Filter */
    protected $filter;

    function __construct(Filter $filter)
    {
        $this->filter = $filter;
    }

    public function handle()
    {
	$this->filter->add('can_send_verification_email', function($can, $recipient) {
        if(Cache::get("oci-email-delivery-suppress-{$recipient}")) {
                Log::channel('oci-email-delivery')->info('Email suppressed for ' . $recipient);
                return new Rejection('你绑定的邮箱地址有误，请前往「个人资料」页面更改绑定邮箱后再尝试发送');
            }
            return $can;
        });
    }
}
