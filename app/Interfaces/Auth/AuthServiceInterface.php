<?php

namespace App\Interfaces\Auth;

use App\Models\User;

interface AuthServiceInterface
{
    /**
     * Register a user without confirming it and store its confirmation code.
     *
     * @param  array{name: string, email: string, password: string, role: string}  $data
     */
    public function register(array $data): User;

    /**
     * Confirm the account matching the given email and code.
     *
     * @param  array{email: string, code: string}  $data
     */
    public function confirmAccount(array $data): void;

    /**
     * Authenticate a confirmed user and issue its token.
     *
     * @param  array{email: string, password: string}  $data
     * @return array{user: User, token: string}
     */
    public function login(array $data): array;

    /**
     * Return the authenticated user along with a renewed token.
     *
     * @return array{user: User, token: string}
     */
    public function checkStatus(): array;

    /**
     * Store a password reset code for the given email, if it belongs to a user.
     *
     * @param  array{email: string}  $data
     */
    public function forgotPassword(array $data): void;

    /**
     * Replace the password of the user matching the given email and code.
     *
     * @param  array{email: string, code: string, password: string}  $data
     */
    public function resetPassword(array $data): void;
}
