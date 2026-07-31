<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHandler;
use App\Http\Requests\Auth\ConfirmAccountRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Resources\Auth\UserResource;
use App\Interfaces\Auth\AuthServiceInterface;

class AuthController extends Controller
{
    public function register(RegisterRequest $request, AuthServiceInterface $authService)
    {
        try {
            $user = $authService->register($request->validated());

            return ResponseHandler::success(new UserResource($user), 'Usuario registrado correctamente', 201);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function confirmAccount(ConfirmAccountRequest $request, AuthServiceInterface $authService)
    {
        try {
            $authService->confirmAccount($request->validated());

            return ResponseHandler::success(null, 'La cuenta ha sido confirmada correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function login(LoginRequest $request, AuthServiceInterface $authService)
    {
        try {
            $result = $authService->login($request->validated());

            return ResponseHandler::success([
                'user' => new UserResource($result['user']),
                'token' => $result['token'],
            ], 'Sesión iniciada correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function checkStatus(AuthServiceInterface $authService)
    {
        try {
            $result = $authService->checkStatus();

            return ResponseHandler::success([
                'user' => new UserResource($result['user']),
                'token' => $result['token'],
            ], 'Sesión válida', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function forgotPassword(ForgotPasswordRequest $request, AuthServiceInterface $authService)
    {
        try {
            $authService->forgotPassword($request->validated());

            return ResponseHandler::success(null, 'Si el correo está registrado recibirás un código de recuperación', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function resetPassword(ResetPasswordRequest $request, AuthServiceInterface $authService)
    {
        try {
            $authService->resetPassword($request->validated());

            return ResponseHandler::success(null, 'La contraseña ha sido actualizada correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }
}
