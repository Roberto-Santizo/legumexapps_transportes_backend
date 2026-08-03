@props(['rows' => []])
@foreach ($rows as $term => $value)
{{ $term }}: {{ $value }}
@endforeach
