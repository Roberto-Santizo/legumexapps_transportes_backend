<x-mail::message>
<p class="eyebrow">Registro &middot; Paso 2 de 2</p>

# Activa tu cuenta

Hola {{ $user->name }}, tu cuenta ya está creada. Escribe este código en la aplicación para activarla y poder iniciar sesión.

<x-mail::code
    :code="$code"
    label="Código de confirmación"
    note="Caduca 1 hora después de este envío."
/>


<x-mail::button
    :url="config('app.frontend_url') . '/confirmar-cuenta'"
    align="left"
    target="_blank"
    rel="noopener noreferrer"
>
    Confirmar Cuenta
</x-mail::button>

<x-mail::notice>
Si no solicitaste esta cuenta, no hagas nada. El código caduca solo y la cuenta queda sin activar.
</x-mail::notice>

</x-mail::message>
