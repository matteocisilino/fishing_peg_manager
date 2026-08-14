<x-mail::message>
# Confirm operation

@if($actionLabel)
You requested to **{{ $actionLabel }}**.
@else
You requested a sensitive operation on the portal.
@endif

Use this OTP code to complete it:

<x-mail::panel>
<strong style="font-size: 1.5rem;">{{ $code }}</strong>
</x-mail::panel>

The code expires at {{ $expiresAt }}.

If you did not request this operation you can ignore this email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
