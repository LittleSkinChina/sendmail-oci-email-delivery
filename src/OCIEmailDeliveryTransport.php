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

class OCIEmailDeliveryTransport extends AbstractApiTransport
{
    private $tenancyId;
    private $compartmentId;
    private $userId;
    private $fingerprint;
    private $privateKeyFile;
    private $endpoint; //OCI Email Service Endpoint

    public function __construct()
    {
        parent::__construct();
        $this->endpoint = config('mail.mailers.oci.endpoint');
        $this->tenancyId = config('mail.mailers.oci.tenancy_id');
        $this->compartmentId = config('mail.mailers.oci.compartment_id');
        $this->userId = config('mail.mailers.oci.user_id');
        $this->fingerprint = config('mail.mailers.oci.key_fingerprint');
        $this->privateKeyFile = config('mail.mailers.oci.private_key_file');
    }

    public function __toString(): string
    {
        return 'oci';
    }

    protected function doSendApi(SentMessage $sentMessage, Email $email, Envelope $envelope): ResponseInterface
    {
        $path = '20220926/actions/submitEmail';
        $payload = $this->getPayload($email);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $payloadHash = base64_encode(hash('sha256', $payloadJson, true));

        $headers = [
            'Content-Length' => strlen($payloadJson),
            'Content-Type' => 'application/json',
            'Date' => gmdate('D, d M Y H:i:s T'),
            'Host' => $this->endpoint,
            'X-Content-SHA256' => $payloadHash,
        ];

        $response = $this->client->request('POST', "https://{$this->endpoint}/{$path}", [
            'headers' => [
                'Authorization' => $this->getAuthorization('POST', $path, $headers),
                ...$headers
            ],
            'body' => $payloadJson,
        ]);

        try {
            $opcRequestId = $response->getHeaders(false)['opc-request-id'][0];
            $result = $response->toArray();
            $messageId = $result['messageId'];
            $envelopeId = $result['envelopeId'];

            $sentMessage->setMessageId($result['messageId']);
            if (count($result['suppressedRecipients'])) {
                Cache::put('oci-email-delivery-suppress-' . $result['suppressedRecipients'][0]['email'], true, 86400);
                throw new HttpTransportException('你绑定的邮箱无法接收 LittleSkin 发送的邮件，请前往「个人资料」页面更改绑定邮箱后再尝试发送', $response);
            }
        } catch (DecodingExceptionInterface | HttpExceptionInterface) {
            $statusCode = $response->getStatusCode();
            foreach($payload['recipients']['to'] as $recipient) {
                Log::channel('oci-email-delivery')->error('Faild to send email to [' . $payload['recipients']['to'][0]['email'] . '], status code=' . $statusCode . ', body=' . $response->getContent(false) . ', opc-request-id=' . $opcRequestId);
            }
            if($statusCode == 429) { // Rate limit exceeded
                throw new HttpTransportException('邮件发送失败，请稍后再试，或联系站点管理员。', $response);
            }
            throw new HttpTransportException('邮件发送失败，请联系站点管理员。详细错误：'.$response->getContent(false).sprintf(' (code %d).', $statusCode), $response);
        } catch (TransportExceptionInterface $e) {
            foreach($payload['recipients']['to'] as $recipient) {
                Log::channel('oci-email-delivery')->error('Faild to send email to [' . $payload['recipients']['to'][0]['email'] . ']. Could not reach the OCI Email Delivery server.');
            }
            throw new HttpTransportException('无法连接邮件发送服务器，请稍后再试，或联系站点管理员。详细错误：Could not reach the OCI Email Delivery server.', $response, 0, $e);
        }

        foreach($payload['recipients']['to'] as $recipient) {
            Log::channel('oci-email-delivery')->info('Email sent to [' . $recipient['email'] . '], messageId='  . $messageId . ', envelopId=' . $envelopeId . ', opc-request-id=' . $opcRequestId);
        }

        return $response;
    }

    private function getPayload(Email $email): array
    {
        return [
            'sender' => [
                'compartmentId' => $this->compartmentId,
                'senderAddress' => [
                    'email' => config('mail.from.address'),
                    'name' => config('mail.from.name'),
                ]
            ],
            'recipients' => [
                'to' => self::getOCIEmailAddress($email->getTo()),
                'cc' => self::getOCIEmailAddress($email->getCc()),
                'bcc' => self::getOCIEmailAddress($email->getBcc()),
            ],
            'subject' => $email->getSubject(),
            'bodyHtml' => $email->getHtmlBody(),
        ];
    }

    /**
     * @param Address[] $addresses
     */
    static private function getOCIEmailAddress(array $addresses): array {
        $result = [];
        foreach ($addresses as $address) {
            $result[] = [
                'email' => $address->getAddress(),
                'name' => $address->getName(),
            ];
        }
        return $result;
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
