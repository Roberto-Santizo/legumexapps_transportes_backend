<x-mail::message>
<p class="eyebrow">Cuenta activa</p>

# Bienvenido a bordo, {{ $user->name }}

Tu cuenta quedó confirmada. Ya puedes iniciar sesión con tu correo y tu contraseña, y trabajar con los transportes asignados a tu perfil.

<x-mail::record :rows="[
    'Correo' => $user->email,
    'Perfil' => $user->role->label(),
]" />

<x-mail::button :url="config('app.url')" align="left">
Iniciar sesión
</x-mail::button>

<x-mail::notice>
¿No reconoces esta cuenta o el perfil no corresponde a tu puesto? Avisa al administrador para darla de baja.
</x-mail::notice>
</x-mail::message>
