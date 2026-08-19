<x-mail::message>
# {{ count($banks) }} bank(s) broken for everyone

Every connection to the bank(s) below is failing, so nobody using them is getting
data. Each one has a command that tells its users, ready to paste — it runs as a
dry run, so it only lists who would be emailed. Drop `--dry-run` from the end to
actually send it, and it will ask for confirmation first.

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
