<x-mail::message>
# Weekly bank logo audit report

The weekly logo validation command found broken bank logo links and replaced them with `logo = null`.

**Updated banks:** {{ count($updatedBanks) }}

@foreach ($updatedBanks as $bank)
- **{{ $bank['name'] }}** (ID: {{ $bank['id'] }})  
  Previous logo: {{ $bank['previous_logo'] }}
@endforeach

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
