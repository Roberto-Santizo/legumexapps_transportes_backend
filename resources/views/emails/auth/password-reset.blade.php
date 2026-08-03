<x-mail::message>
# Restablece tu contraseña

Hola {{ $user->name }},

Recibimos una solicitud para restablecer la contraseña de tu cuenta. Usa este código para continuar:

<x-mail::panel>
{{ $code }}
</x-mail::panel>

El código caduca dentro de **1 hora**. Si no solicitaste el cambio, ignora este correo: tu contraseña actual sigue siendo válida.

Gracias,<br>
{{ config('mail.from.name') }}
</x-mail::message>
