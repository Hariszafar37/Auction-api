<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    |
    | Turns every registration bot check off in one move. Exists so that a
    | false positive on live can be neutralised from the environment without
    | waiting on a deploy. Leave enabled.
    |
    */

    'enabled' => env('BOT_GUARD_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Form signal (honeypot + fill timing)
    |--------------------------------------------------------------------------
    |
    | The registration form ships two extra fields: a hidden decoy input that a
    | human never sees (and therefore never fills), and the timestamp at which
    | the form was rendered.
    |
    | A conventional honeypot only rejects a decoy that was FILLED IN, which
    | catches nothing here — the bot posts the API host directly and never
    | renders our form, so it submits no decoy at all. `require_presence` is
    | therefore the check that matters: the fields must be PRESENT. A client
    | that never loaded the form cannot know they exist.
    |
    | DEPLOY ORDER: the frontend must ship first, so that live browsers are
    | already sending these fields before the backend begins requiring them.
    | Set `require_presence` to false to open that window if the two deploys
    | cannot be sequenced.
    |
    */

    'form_signal' => [
        'require_presence' => env('BOT_GUARD_REQUIRE_FORM_SIGNAL', true),

        // Field name of the hidden decoy. Deliberately plausible-looking so that
        // an autofill-driven scraper is tempted to populate it.
        'honeypot_field' => env('BOT_GUARD_HONEYPOT_FIELD', 'website'),

        // A human cannot read, tab through and complete the registration form in
        // under this many seconds. Scripts submit in well under one.
        'min_fill_seconds' => (int) env('BOT_GUARD_MIN_FILL_SECONDS', 3),

        // Upper bound on how stale a rendered form may be, in seconds. Generous:
        // people do leave a signup tab open over lunch. Caps token replay.
        'max_form_age_seconds' => (int) env('BOT_GUARD_MAX_FORM_AGE_SECONDS', 86400),
    ],

    /*
    |--------------------------------------------------------------------------
    | Machine-generated name detection
    |--------------------------------------------------------------------------
    |
    | See App\Support\BotSignals. Modes:
    |
    |   reject — refuse the registration with a validation error (default)
    |   log    — allow it through but record the hit, for tuning against real
    |            traffic before switching to reject
    |   off    — skip the check entirely
    |
    | Calibrated against the live attack payloads (scored 6-12) and a corpus of
    | awkward real names including hyphenated, apostrophed, all-caps, Slavic and
    | non-Latin forms (scored 0-2). The threshold is 5.
    |
    */

    'name_heuristic' => env('BOT_GUARD_NAME_HEURISTIC', 'reject'),

];
