<x-mail::message>
<p class="eyebrow">Seguridad de la cuenta</p>

# Restablece tu contraseña

Hola {{ $user->name }}, recibimos una solicitud para cambiar la contraseña de tu cuenta. Escribe este código en la aplicación para crear la nueva.

<x-mail::code
    :code="$code"
    label="Código de restablecimiento"
    note="Caduca 1 hora después de este envío."
/>

<x-mail::notice>
Si no pediste el cambio, ignora este correo: tu contraseña actual sigue vigente. Avisa al administrador si esto se repite.
</x-mail::notice>
</x-mail::message>
