<?php

namespace App\Interfaces\Auth;

use App\Models\User;

interface AuthEmailsInterface
{
    /**
     * Send the account confirmation code to the given user.
     *
     * @param  string  $code  Plain six digit code, never persisted nor logged.
     */
    public function sendAccountConfirmation(User $user, string $code): void;

    /**
     * Send the password reset code to the given user.
     *
     * @param  string  $code  Plain six digit code, never persisted nor logged.
     */
    public function sendPasswordReset(User $user, string $code): void;

    /**
     * Send the welcome email to a user that just confirmed its account.
     */
    public function sendWelcome(User $user): void;
}
