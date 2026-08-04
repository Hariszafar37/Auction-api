<?php

namespace App\Support;

/**
 * Single source of truth for the outward-facing brand identity.
 *
 * Deliberately constants, not env vars. The sender name and the wording in
 * transactional email is part of the brand, not part of a deployment
 * environment — production was sending "Regards, CarAuction" purely because
 * MAIL_FROM_NAME interpolated ${APP_NAME}, and APP_NAME differed per server.
 * Anchoring the brand in code means a mis-set .env can no longer leak the
 * wrong company name to a customer.
 *
 * APP_NAME remains free to differ (logs, queue names, Horizon tags); it is
 * simply no longer allowed anywhere a customer can see it.
 */
class Brand
{
    /** Customer-facing brand name — used in "From" headers and email copy. */
    public const NAME = 'Colonial Auction Services';

    /** Full legal entity name — used in footers, legal notices and PDFs. */
    public const LEGAL_NAME = 'Colonial Auction Services, Inc.';

    /**
     * Address every transactional email is sent from.
     *
     * Pinned for the same reason as NAME: MAIL_FROM_ADDRESS drifted per server
     * (local still had the framework's hello@example.com placeholder). The
     * sending domain must stay SPF/DKIM-authorised on the mail provider.
     */
    public const FROM_ADDRESS = 'noreply@colonialauctions.com';

    /** Path, relative to public/, of the canonical brand logo. */
    public const LOGO_PATH = 'images/colonial-logo.png';

    /**
     * Absolute URL of the brand logo for use in email.
     *
     * Mail clients cannot resolve relative paths and largely block base64
     * data: URIs, so email must reference an absolute, publicly reachable URL.
     * BRAND_LOGO_URL allows pointing at a CDN without touching code.
     */
    public static function logoUrl(): string
    {
        return config('brand.logo_url') ?: asset(self::LOGO_PATH);
    }
}
