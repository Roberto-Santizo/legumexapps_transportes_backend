<?php

namespace App\Mail\Services;

use App\Interfaces\Auth\AuthEmailsInterface;
use App\Mail\Auth\AccountConfirmationMail;
use App\Mail\Auth\PasswordResetMail;
use App\Mail\Auth\WelcomeMail;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Override;
use Throwable;

class AuthEmails implements AuthEmailsInterface
{
    #[Override]
    public function sendAccountConfirmation(User $user, string $code): void
    {
        $this->send($user, new AccountConfirmationMail($user, $code));
    }

    #[Override]
    public function sendPasswordReset(User $user, string $code): void
    {
        $this->send($user, new PasswordResetMail($user, $code));
    }

    #[Override]
    public function sendWelcome(User $user): void
    {
        $this->send($user, new WelcomeMail($user));
    }

    /**
     * Deliver the mailable, swallowing any provider failure.
     *
     * A failed email never breaks the flow that triggered it: the code is
     * already persisted and the caller has nothing to roll back.
     */
    private function send(User $user, Mailable $mailable): void
    {
        try {
            Mail::to($user->email)->send($mailable);
        } catch (Throwable $exception) {
            Log::error('No se pudo enviar el correo de autenticación', [
                'mailable' => $mailable::class,
                'email' => $user->email,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
