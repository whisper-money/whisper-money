@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
@if (trim($slot) === 'Laravel')
<svg class="logo" width="75" height="75" viewBox="0 0 24 24" fill="none" s<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-bird-icon lucide-bird"><path d="M16 7h.01"/><path d="M3.4 18H12a8 8 0 0 0 8-8V7a4 4 0 0 0-7.28-2.3L2 20"/><path d="m20 7 2 .5-2 .5"/><path d="M10 18v3"/><path d="M14 17.75V21"/><path d="M7 18a6 6 0 0 0 3.84-10.61"/></svg>troke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: #18181b;">
    <path d="M16 7h.01"/>
    <path d="M3.4 18H12a8 8 0 0 0 8-8V7a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v10.9a2 2 0 0 0 2.4 2.1"/>
    <path d="M7 15h1"/>
    <path d="M7 12h1"/>
    <path d="M7 9h2"/>
    <path d="M11 12h6"/>
    <path d="M11 15h6"/>
    <path d="M11 9h6"/>
</svg>
@elseif (trim($slot) === config('app.name'))
<svg class="logo" xmlns="http://www.w3.org/2000/svg" width="75" height="75" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-bird-icon lucide-bird"><path d="M16 7h.01"/><path d="M3.4 18H12a8 8 0 0 0 8-8V7a4 4 0 0 0-7.28-2.3L2 20"/><path d="m20 7 2 .5-2 .5"/><path d="M10 18v3"/><path d="M14 17.75V21"/><path d="M7 18a6 6 0 0 0 3.84-10.61"/></svg>
@else
{!! $slot !!}
@endif
</a>
</td>
</tr>
