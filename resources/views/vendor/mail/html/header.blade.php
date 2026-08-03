@props(['url'])
@php
    $logoUrl = config('app.logo_url');
    $brand = config('mail.from.name', config('app.name'));
@endphp
<tr>
<td align="center">
<table class="brand" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="brand-cell" align="left">
<a href="{{ $url }}" style="display: inline-block; text-decoration: none;">
@if (filled($logoUrl))
<img src="{{ $logoUrl }}" class="logo" alt="{{ $brand }}" height="118">
@else
{{ $brand }}
@endif
</a>
</td>
</tr>
<tr>
<td class="header-rule" height="4">&nbsp;</td>
</tr>
</table>
</td>
</tr>
