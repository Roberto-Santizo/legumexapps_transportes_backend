<?php

use App\Mail\Transport\MicrosoftGraphTransport;
use Http\Promise\FulfilledPromise;
use Http\Promise\RejectedPromise;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\Mail;
use Microsoft\Graph\GraphServiceClient;
use Microsoft\Kiota\Abstractions\RequestAdapter;
use Microsoft\Kiota\Abstractions\RequestInformation;
use Microsoft\Kiota\Authentication\Oauth\ClientCredentialContext;
use Microsoft\Kiota\Serialization\Json\JsonSerializationWriterFactory;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/*
|--------------------------------------------------------------------------
| MicrosoftGraphTransport
|--------------------------------------------------------------------------
|
| El proveedor de correo. El RequestAdapter de kiota se mockea: la suite no tiene
| credenciales de Azure y no sale a la red. Se comprueba el cuerpo JSON que
| recibiría POST /users/{from}/sendMail y que un fallo de Graph lanza.
|
*/

/**
 * Build a transport whose Graph client records every request instead of sending it.
 *
 * @param  list<RequestInformation>  $requests
 */
function graphTransport(array &$requests, bool $failing = false): MicrosoftGraphTransport
{
    $adapter = Mockery::mock(RequestAdapter::class);
    $adapter->shouldReceive('getSerializationWriterFactory')->andReturn(new JsonSerializationWriterFactory);
    $adapter->shouldReceive('enableBackingStore', 'setBaseUrl');
    $adapter->shouldReceive('getBaseUrl')->andReturn('https://graph.microsoft.com/v1.0');
    $adapter->shouldReceive('sendNoContentAsync')->andReturnUsing(
        function (RequestInformation $request) use (&$requests, $failing) {
            $requests[] = $request;

            return $failing ? new RejectedPromise(new RuntimeException('ErrorAccessDenied')) : new FulfilledPromise(null);
        },
    );

    $context = new ClientCredentialContext('tenant', 'client', 'secret');

    return new MicrosoftGraphTransport(new GraphServiceClient($context, [], 'https://graph.microsoft.com', $adapter));
}

/**
 * @return array<string, mixed>
 */
function graphPayload(RequestInformation $request): array
{
    return json_decode((string) $request->content, true);
}

it('sends the message as the from mailbox through sendMail', function (): void {
    $requests = [];

    graphTransport($requests)->send(
        (new Email)
            ->from(new Address('noreply@legumex.com', 'Legumex Transportes'))
            ->to(new Address('pilot@example.com', 'Juan'))
            ->cc('cc@example.com')
            ->bcc('bcc@example.com')
            ->replyTo('reply@example.com')
            ->subject('Confirma tu cuenta')
            ->html('<p>Código 123456</p>')
            ->text('Código 123456')
            ->attach('contenido', 'nota.txt', 'text/plain'),
    );

    expect($requests)->toHaveCount(1)
        ->and($requests[0]->getUri())->toBe('https://graph.microsoft.com/v1.0/users/noreply%40legumex.com/sendMail');

    $payload = graphPayload($requests[0]);
    $message = $payload['Message'];

    expect($payload['SaveToSentItems'])->toBeFalse()
        ->and($message['subject'])->toBe('Confirma tu cuenta')
        ->and($message['body'])->toEqual(['contentType' => 'html', 'content' => '<p>Código 123456</p>'])
        ->and($message['from']['emailAddress'])->toBe(['address' => 'noreply@legumex.com', 'name' => 'Legumex Transportes'])
        ->and($message['toRecipients'])->toBe([['emailAddress' => ['address' => 'pilot@example.com', 'name' => 'Juan']]])
        ->and($message['ccRecipients'])->toBe([['emailAddress' => ['address' => 'cc@example.com']]])
        ->and($message['bccRecipients'])->toBe([['emailAddress' => ['address' => 'bcc@example.com']]])
        ->and($message['replyTo'])->toBe([['emailAddress' => ['address' => 'reply@example.com']]])
        ->and($message['attachments'])->toHaveCount(1)
        ->and($message['attachments'][0]['@odata.type'])->toBe('#microsoft.graph.fileAttachment')
        ->and($message['attachments'][0]['name'])->toBe('nota.txt')
        ->and($message['attachments'][0]['contentType'])->toBe('text/plain')
        ->and(base64_decode($message['attachments'][0]['contentBytes']))->toBe('contenido');
});

it('falls back to a text body when there is no html', function (): void {
    $requests = [];

    graphTransport($requests)->send(
        (new Email)->from('noreply@legumex.com')->to('pilot@example.com')->subject('Hola')->text('Solo texto'),
    );

    expect(graphPayload($requests[0])['Message']['body'])->toEqual(['contentType' => 'text', 'content' => 'Solo texto']);
});

it('throws a transport exception when graph rejects the message', function (): void {
    $requests = [];

    graphTransport($requests, failing: true)->send(
        (new Email)->from('noreply@legumex.com')->to('pilot@example.com')->subject('Hola')->text('Hola'),
    );
})->throws(TransportException::class, 'ErrorAccessDenied');

it('registers the microsoft-graph mailer', function (): void {
    config([
        'mail.mailers.microsoft-graph' => ['transport' => 'microsoft-graph'],
        'services.microsoft_graph' => ['tenant_id' => 'tenant', 'client_id' => 'client', 'client_secret' => 'secret'],
    ]);

    /** Mail::fake() wraps the real manager; the transport is registered on that one. */
    $mailer = Mail::getFacadeRoot()->manager->mailer('microsoft-graph');

    expect($mailer)->toBeInstanceOf(Mailer::class)
        ->and($mailer->getSymfonyTransport())->toBeInstanceOf(MicrosoftGraphTransport::class);
});
