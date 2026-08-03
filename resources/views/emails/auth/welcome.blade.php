<x-mail::message>
# Bienvenido a Legumex Transportes

Hola {{ $user->name }},

Tu cuenta ya está confirmada. A partir de ahora puedes iniciar sesión con tu correo y tu contraseña.

Si no reconoces esta cuenta, avisa al administrador.

Gracias,<br>
{{ config('mail.from.name') }}
</x-mail::message>
