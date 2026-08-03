<?php

use App\Enums\UserRole;
use App\Mail\Auth\AccountConfirmationMail;
use App\Mail\Auth\PasswordResetMail;
use App\Mail\Auth\WelcomeMail;
use App\Models\User;
use Illuminate\Mail\Markdown;

const LOGO_URL = 'https://cdn.example.com/logo.png';

beforeEach(function (): void {
    config([
        'app.logo_url' => LOGO_URL,
        'mail.from.name' => 'Legumex Transportes',
    ]);
});

function mailRecipient(array $overrides = []): User
{
    return User::factory()->make(array_merge([
        'name' => 'Juan Pérez',
        'email' => 'juan.perez@example.com',
        'role' => UserRole::Pilot,
    ], $overrides));
}

describe('plantilla de confirmación de cuenta', function (): void {
    it('renders the logo, the brand and every digit of the code', function (): void {
        $html = (new AccountConfirmationMail(mailRecipient(), '482913'))->render();

        expect($html)
            ->toContain('src="'.LOGO_URL.'"')
            ->toContain('alt="Legumex Transportes"')
            ->toContain('Activa tu cuenta')
            ->toContain('Juan Pérez')
            ->toContain('Código de confirmación')
            ->toContain('>482913</td>');
    });

    it('inlines the green palette so mail clients keep the styling', function (): void {
        $html = (new AccountConfirmationMail(mailRecipient(), '482913'))->render();

        expect($html)
            ->toContain('background-color: #153c24')
            ->toContain('#8cc63f')
            ->not->toContain('<link');
    });

    it('offers a plain text alternative carrying the code', function (): void {
        $text = (string) app(Markdown::class)->renderText('emails.auth.account-confirmation', [
            'user' => mailRecipient(),
            'code' => '482913',
        ]);

        expect($text)->toContain('Código de confirmación: 482913');
    });
});

describe('plantilla de restablecimiento de contraseña', function (): void {
    it('renders the reset code inside the code panel', function (): void {
        $html = (new PasswordResetMail(mailRecipient(), '739045'))->render();

        expect($html)
            ->toContain('src="'.LOGO_URL.'"')
            ->toContain('Restablece tu contraseña')
            ->toContain('Código de restablecimiento')
            ->toContain('>739045</td>');
    });
});

describe('plantilla de bienvenida', function (): void {
    it('renders the account record with the role label in Spanish', function (): void {
        $html = (new WelcomeMail(mailRecipient()))->render();

        expect($html)
            ->toContain('src="'.LOGO_URL.'"')
            ->toContain('Bienvenido a bordo, Juan Pérez')
            ->toContain('juan.perez@example.com')
            ->toContain('Piloto')
            ->toContain('Iniciar sesión');
    });

    it('labels every role in Spanish', function (UserRole $role, string $label): void {
        $html = (new WelcomeMail(mailRecipient(['role' => $role])))->render();

        expect($html)->toContain($label);
    })->with([
        [UserRole::Administrator, 'Administrador'],
        [UserRole::Carrier, 'Transportista'],
        [UserRole::Pilot, 'Piloto'],
        [UserRole::Manager, 'Encargado'],
    ]);
});

it('falls back to the brand name when no logo is configured', function (): void {
    config(['app.logo_url' => null]);

    $html = (new WelcomeMail(mailRecipient()))->render();

    expect($html)
        ->not->toContain('<img src=')
        ->toContain('Legumex Transportes');
});
