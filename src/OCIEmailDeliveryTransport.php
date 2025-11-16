<?php

namespace LittleSkin\Sendmail\OCIEmailDelivery;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractApiTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Component\HttpClient\RetryableHttpClient;

class OCIEmailDeliveryTransport extends AbstractApiTransport
{
    private $retryableHttpClient;
    private $tenancyId;
    private $compartmentId;
    private $userId;
    private $fingerprint;
    private $privateKeyFile;
    private $endpoint; //OCI Email Delivery API Endpoint

    public function __construct()
    {
        parent::__construct();
        $this->endpoint = config('mail.mailers.oci.endpoint');
        $this->tenancyId = config('mail.mailers.oci.tenancy_id');
        $this->compartmentId = config('mail.mailers.oci.compartment_id');
        $this->userId = config('mail.mailers.oci.user_id');
        $this->fingerprint = config('mail.mailers.oci.key_fingerprint');
        $this->privateKeyFile = config('mail.mailers.oci.private_key_file');
        $this->retryableHttpClient = new RetryableHttpClient($this->client);
    }

    public function __toString(): string
    {
        return 'oci';
    }

    protected function doSendApi(SentMessage $sentMessage, Email $email, Envelope $envelope): ResponseInterface
    {
        $path = '20220926/actions/submitRawEmail';
        $payloadRaw = $email->toString();
        $payloadHash = base64_encode(hash('sha256', $payloadRaw, true));

        $to = array_map(fn(Address $a) => $a->toString(), $email->getTo());
        $cc = array_map(fn(Address $a) => $a->toString(), $email->getCc());
        $bcc = array_map(fn(Address $a) => $a->toString(), $email->getBcc());
        $recipientsHeader = implode(',', [...$to, ...$cc, ...$bcc]);

        $headers = [
            // OCI authentication required headers
            // https://docs.oracle.com/en-us/iaas/Content/API/Concepts/signingrequests.htm
            'Content-Length' => strlen($payloadRaw),
            'Content-Type' => 'message/rfc822',
            'Date' => gmdate('D, d M Y H:i:s T'),
            'Host' => $this->endpoint,
            'X-Content-SHA256' => $payloadHash,
            // OCI Email Delivery SubmitRawEmail required headers
            // https://docs.oracle.com/en-us/iaas/api/#/en/emaildeliverysubmission/20220926/EmailRawSubmittedResponse/SubmitRawEmail
            'compartment-id' => $this->compartmentId,
            'sender' => $email->getFrom()[0]->getAddress(),
            'recipients' => $recipientsHeader,
        ];

        $response = $this->retryableHttpClient->request('POST', "https://{$this->endpoint}/{$path}", [
            'headers' => [
                'Authorization' => $this->getAuthorization('POST', $path, $headers),
                ...$headers
            ],
            'body' => $payloadRaw,
        ]);

        try {
            $opcRequestId = $response->getHeaders(false)['opc-request-id'][0];
            $result = $response->toArray();
            $messageId = $result['messageId'];
            $envelopeId = $result['envelopeId'];

            if (count($result['suppressedRecipients'])) {
                // Blessing Skin 现在没有同时向多个收件人发送的邮件，所以 suppressedRecipients 里最多只有一个
                Cache::put('oci-email-delivery-suppress-' . $result['suppressedRecipients'][0], true, 86400);
                Log::channel('oci-email-delivery')->info('Email sent to [' . $result['suppressedRecipients'][0] . '] was suppressed by OCI server, messageId='  . $messageId . ', envelopId=' . $envelopeId . ', opc-request-id=' . $opcRequestId);
                throw new HttpTransportException('你绑定的邮箱地址有误，请前往「个人资料」页面更改绑定邮箱后再尝试发送', $response);
            }
        } catch (DecodingExceptionInterface | HttpExceptionInterface) {
            $statusCode = $response->getStatusCode();
            Log::channel('oci-email-delivery')->error('Faild to send email to [' . $recipientsHeader . '], status code=' . $statusCode . ', body=' . $response->getContent(false) . ', opc-request-id=' . $opcRequestId);
            if($statusCode == 429) { // Rate limit exceeded
                throw new HttpTransportException('邮件发送失败，请稍后再试，或联系站点管理员。', $response);
            }
            throw new HttpTransportException('邮件发送失败，请联系站点管理员。详细错误：'.$response->getContent(false).sprintf(' (code %d).', $statusCode), $response);
        } catch (TransportExceptionInterface $e) {
            Log::channel('oci-email-delivery')->error('Faild to send email to [' . $recipientsHeader . ']. Could not reach the OCI Email Delivery server.');
            throw new HttpTransportException('无法连接邮件发送服务器，请稍后再试，或联系站点管理员。详细错误：Could not reach the OCI Email Delivery server.', $response, 0, $e);
        }

        Log::channel('oci-email-delivery')->info('Email sent to [' . $recipientsHeader . '], messageId='  . $messageId . ', envelopId=' . $envelopeId . ', opc-request-id=' . $opcRequestId);

        return $response;
    }

    private function getAuthorization(string $method, string $path, array $headers): string {
        ksort($headers);
        $headers = ['(request-target)' => strtolower($method) . ' /' . $path] + $headers;
        $headerKeys = array_keys($headers);

        $sigString = implode("\n", [
            ...array_map(fn(string $k, string $v) => strtolower($k) . ": {$v}", $headerKeys, array_values($headers)),
        ]);

        $key = openssl_get_privatekey(file_get_contents($this->privateKeyFile));
        openssl_sign($sigString, $signature, $key, OPENSSL_ALGO_SHA256);
        $sigBase64 = base64_encode($signature);

        $a = sprintf(
            'Signature version="1",keyId="%s",algorithm="rsa-sha256",headers="%s",signature="%s"',
            "{$this->tenancyId}/{$this->userId}/{$this->fingerprint}",
            implode(' ', $headerKeys),
            $sigBase64
        );
        return $a;
    }
}
