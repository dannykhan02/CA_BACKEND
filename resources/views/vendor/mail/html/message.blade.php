<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.url')">
DocIntel
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} DocIntel. {{ __('All rights reserved.') }}

Questions? Just reply to this email or write to <a href="mailto:{{ config('mail.reply_to.address') }}">{{ config('mail.reply_to.address') }}</a>.
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
