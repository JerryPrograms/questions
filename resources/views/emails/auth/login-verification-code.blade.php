<x-mail::message>
# Your login verification code

Enter this code to finish signing in to {{ config('app.name') }}:

# {{ $code }}

This code expires in 2 minutes. If you did not try to sign in, you can ignore this email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
