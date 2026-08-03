<x-mail::message>
# Confirma tu cuenta

Hola {{ $user->name }},

Tu cuenta ya está creada. Usa este código para confirmarla:

<x-mail::panel>
{{ $code }}
</x-mail::panel>

El código caduca dentro de **1 hora**. Si no solicitaste esta cuenta, ignora este correo.

Gracias,<br>
{{ config('mail.from.name') }}
</x-mail::message>
