@props(['code', 'label' => 'Código', 'note' => null])
{{-- El código va como una sola cadena, sin separadores: así el autorrelleno
     del teléfono lo detecta y se puede copiar de una pieza. --}}
<table class="ticket" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="ticket-perforation" height="1">&nbsp;</td>
</tr>
<tr>
<td class="ticket-cell" align="center">
<p class="ticket-label">{{ $label }}</p>
<table class="digits" cellpadding="0" cellspacing="0" role="presentation" align="center">
<tr>
<td class="digit">{{ $code }}</td>
</tr>
</table>
@if (filled($note))
<p class="ticket-note">{{ $note }}</p>
@endif
</td>
</tr>
</table>
