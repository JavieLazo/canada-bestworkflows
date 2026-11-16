@props(['date', 'today'])
<time datetime="{{ $date->format('Y-m-d') }}" title="{{ $date->format('M d, Y') }}">{{ $date->format($date->format('Y') === substr($today, 0, 4) ? 'M j' : 'M j, Y') }}</time>
