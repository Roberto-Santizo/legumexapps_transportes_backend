<?php

namespace App\Mail\Transport;

use GuzzleHttp\Psr7\Utils;
use Microsoft\Graph\Generated\Models\BodyType;
use Microsoft\Graph\Generated\Models\EmailAddress;
use Microsoft\Graph\Generated\Models\FileAttachment;
use Microsoft\Graph\Generated\Models\ItemBody;
use Microsoft\Graph\Generated\Models\Message;
use Microsoft\Graph\Generated\Models\Recipient;
use Microsoft\Graph\Generated\Users\Item\SendMail\SendMailPostRequestBody;
use Microsoft\Graph\GraphServiceClient;
use Override;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\MessageConverter;
use Symfony\Component\Mime\Part\DataPart;
use Throwable;

/**
 * Symfony mailer transport that delivers through Microsoft Graph `sendMail`.
 *
 * The envelope sender (the `from` address) is the mailbox the message is sent
 * as: the app registration needs the `Mail.Send` application permission on it.
 * Any Graph failure is rethrown as a TransportException, like any other
 * Laravel mailer, so callers keep their own error handling.
 */
class MicrosoftGraphTransport extends AbstractTransport
{
    public function __construct(private readonly GraphServiceClient $client)
    {
        parent::__construct();
    }

    #[Override]
    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $envelope = $message->getEnvelope();

        $graphMessage = new Message;
        $graphMessage->setSubject($email->getSubject());
        $graphMessage->setBody($this->body($email->getHtmlBody(), $email->getTextBody()));
        $graphMessage->setFrom($this->recipient($envelope->getSender()));
        $graphMessage->setToRecipients($this->recipients($email->getTo()));
        $graphMessage->setCcRecipients($this->recipients($email->getCc()));
        $graphMessage->setBccRecipients($this->recipients($email->getBcc()));
        $graphMessage->setReplyTo($this->recipients($email->getReplyTo()));
        $graphMessage->setAttachments(array_map($this->attachment(...), $email->getAttachments()));

        $body = new SendMailPostRequestBody;
        $body->setMessage($graphMessage);
        $body->setSaveToSentItems(false);

        try {
            $this->client->users()
                ->byUserId($envelope->getSender()->getAddress())
                ->sendMail()
                ->post($body)
                ->wait();
        } catch (Throwable $exception) {
            throw new TransportException('Microsoft Graph rechazó el envío del correo: '.$exception->getMessage(), 0, $exception);
        }
    }

    #[Override]
    public function __toString(): string
    {
        return 'microsoft-graph';
    }

    private function body(mixed $html, mixed $text): ItemBody
    {
        $body = new ItemBody;

        if ($html !== null) {
            $body->setContentType(new BodyType(BodyType::HTML));
            $body->setContent(is_resource($html) ? (string) stream_get_contents($html) : (string) $html);
        } else {
            $body->setContentType(new BodyType(BodyType::TEXT));
            $body->setContent(is_resource($text) ? (string) stream_get_contents($text) : (string) $text);
        }

        return $body;
    }

    /**
     * @param  list<Address>  $addresses
     * @return list<Recipient>
     */
    private function recipients(array $addresses): array
    {
        return array_map($this->recipient(...), $addresses);
    }

    private function recipient(Address $address): Recipient
    {
        $emailAddress = new EmailAddress;
        $emailAddress->setAddress($address->getAddress());

        if ($address->getName() !== '') {
            $emailAddress->setName($address->getName());
        }

        $recipient = new Recipient;
        $recipient->setEmailAddress($emailAddress);

        return $recipient;
    }

    private function attachment(DataPart $part): FileAttachment
    {
        $attachment = new FileAttachment;
        $attachment->setName($part->getFilename() ?? 'attachment');
        $attachment->setContentType($part->getMediaType().'/'.$part->getMediaSubtype());
        $attachment->setContentBytes(Utils::streamFor(base64_encode($part->getBody())));
        $attachment->setIsInline($part->getDisposition() === 'inline');

        if ($part->getDisposition() === 'inline') {
            $attachment->setContentId($part->getContentId());
        }

        return $attachment;
    }
}
