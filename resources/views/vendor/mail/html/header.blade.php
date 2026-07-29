@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
@if (trim($slot) === config('app.name'))
<span style="font-size: 20px; font-weight: 700; color: #2563eb; text-decoration: none;">{{ $slot }}</span>
@else
{!! $slot !!}
@endif
</a>
</td>
</tr>
