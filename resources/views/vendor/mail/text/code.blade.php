@props(['code', 'label' => 'Código', 'note' => null])
{{ $label }}: {{ $code }}
@if (filled($note))

{{ $note }}
@endif
