@props(['rows' => []])
<table class="record" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="record-cell">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
@foreach ($rows as $term => $value)
<tr>
<td class="record-term">{{ $term }}</td>
<td class="record-value">{{ $value }}</td>
</tr>
@endforeach
</table>
</td>
</tr>
</table>
