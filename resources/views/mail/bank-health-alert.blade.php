<x-mail::message>
# {{ count($banks) }} bank(s) broken for everyone

Nothing is getting through to the bank(s) below, so nobody using them is getting
data — the line under each one says how we know. Each has a command that tells its
users, ready to paste; it runs as a dry run, so it only lists who would be
emailed. Drop `--dry-run` from the end to actually send it, and it will ask for
confirmation first.

@if ($looksLikeProviderOutage)
**Check Enable Banking first.** This is most of the banks we have a live
connection to, which one bank being down cannot explain. If the provider is the
one that is failing, every command below would tell those users something untrue
about their own bank.

@endif
@foreach ($banks as $bank)
## {{ $bank['display_name'] }}

{{ $bank['reason'] }}

{{-- Unescaped on purpose: Blade would turn the quotes around the bank name
into &quot;, which the markdown renderer escapes a second time, and the
operator would paste &amp;quot; into a shell. CommonMark escapes the code
block's contents itself, so the raw string is what reaches the reader. --}}
```
{!! $bank['notify_command'] !!} --dry-run
```

@endforeach
These bank(s) will not be reported again for {{ $repeatAfterDays }} day(s), whether or not you send anything.
Run `php artisan banking:health` for the full table, including the banks that are only partly broken.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
