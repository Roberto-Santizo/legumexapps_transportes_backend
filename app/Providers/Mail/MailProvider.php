<?php

namespace App\Providers\Mail;

use App\Mail\Transport\MicrosoftGraphTransport;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use Microsoft\Graph\GraphServiceClient;
use Microsoft\Kiota\Authentication\Oauth\ClientCredentialContext;

class MailProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    /**
     * Register the `microsoft-graph` mail transport.
     *
     * The Graph client is built lazily, only when the mailer is first resolved,
     * so missing credentials never break a request that sends no email.
     */
    public function boot(): void
    {
        Mail::extend('microsoft-graph', function (array $config): MicrosoftGraphTransport {
            $context = new ClientCredentialContext(
                (string) ($config['tenant_id'] ?? config('services.microsoft_graph.tenant_id')),
                (string) ($config['client_id'] ?? config('services.microsoft_graph.client_id')),
                (string) ($config['client_secret'] ?? config('services.microsoft_graph.client_secret')),
            );

            return new MicrosoftGraphTransport(new GraphServiceClient($context));
        });
    }
}
