{{--
    Overrides Illuminate\Mail\resources\views\text\message.blade.php.
    The plain-text part is what many clients show in previews and what
    accessibility tooling reads, so it carries the same brand name as the
    HTML part rather than config('app.name').
--}}
<x-mail::layout>
    {{-- Header --}}
    <x-slot:header>
        <x-mail::header :url="config('brand.site_url')">
            {{ config('brand.name') }}
        </x-mail::header>
    </x-slot:header>

    {{-- Body --}}
    {{ $slot }}

    {{-- Subcopy --}}
    @isset($subcopy)
        <x-slot:subcopy>
            <x-mail::subcopy>
                {{ $subcopy }}
            </x-mail::subcopy>
        </x-slot:subcopy>
    @endisset

    {{-- Footer --}}
    <x-slot:footer>
        <x-mail::footer>
            {{-- No period after the legal name: it already ends in "Inc." --}}
            © {{ date('Y') }} {{ config('brand.legal_name') }} @lang('All rights reserved.')
        </x-mail::footer>
    </x-slot:footer>
</x-mail::layout>
