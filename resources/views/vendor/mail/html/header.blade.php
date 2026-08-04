{{--
    Overrides Illuminate\Mail\resources\views\html\header.blade.php.

    The framework original only rendered an <img> when the slot was literally
    "Laravel", otherwise it printed the app name as text. Every markdown mail
    (email verification, password reset, outbid, approvals, …) therefore had a
    bare text header. It now always renders the brand logo, with the brand name
    as alt text so image-blocking clients still show the right company.
--}}
@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
{{-- width attribute as well as CSS: Outlook ignores the stylesheet. --}}
<img src="{{ \App\Support\Brand::logoUrl() }}"
     class="logo"
     alt="{{ config('brand.legal_name') }}"
     width="170"
     style="width: 170px; max-width: 170px; height: auto; background: #ffffff; border-radius: 8px;">
</a>
</td>
</tr>
