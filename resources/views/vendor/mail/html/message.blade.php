{{--
    Overrides Illuminate\Mail\resources\views\html\message.blade.php.

    The framework original printed config('app.name') as plain text in the
    header and footer. Both now use the pinned brand identity, and the header
    renders the same logo the website and the hand-built Mailables use.
--}}
<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('brand.site_url')">
{{ config('brand.name') }}
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
{{-- No period after the legal name: it already ends in "Inc." --}}
© {{ date('Y') }} {{ config('brand.legal_name') }} {{ __('All rights reserved.') }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
