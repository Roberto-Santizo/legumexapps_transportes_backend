<?php

namespace App\Interfaces\Auth;

use App\Models\User;
use Illuminate\Http\UploadedFile;

interface AuthServiceInterface
{
    /**
     * Register a user without confirming it and store its confirmation code.
     *
     * `dpi` and `license` are the front photos of the pilot's DPI and driving licence.
     * The form request already guarantees they travel together and only makes them
     * mandatory when the role is `pilot`; two rules the type cannot express are on the
     * implementation:
     *
     * - They are only persisted for a `pilot`. Any other role that sends them has them
     *   silently discarded: nothing is uploaded and no documents row is created.
     * - The files are uploaded before the transaction opens, so a failed commit must
     *   delete whatever was already uploaded before propagating the original error.
     *   The bucket does not roll back on its own.
     *
     * @param  array{name: string, email: string, password: string, role: string, dpi?: UploadedFile|null, license?: UploadedFile|null}  $data
     */
    public function register(array $data): User;

    /**
     * Confirm the account matching the given email and code.
     *
     * @param  array{email: string, code: string}  $data
     */
    public function confirmAccount(array $data): void;

    /**
     * Authenticate a confirmed user and issue its access and refresh tokens.
     *
     * @param  array{email: string, password: string}  $data
     * @return array{user: User, token: string, refreshToken: string}
     */
    public function login(array $data): array;

    /**
     * Return the authenticated user along with a renewed pair of tokens.
     *
     * @return array{user: User, token: string, refreshToken: string}
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
