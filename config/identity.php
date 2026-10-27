<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Identity provider
    |--------------------------------------------------------------------------
    |
    | DEC-013 (identity provider and MFA policy) is still OPEN. Only "local"
    | email/password authentication is implemented; any other value fails on
    | login instead of silently falling back.
    |
    */

    'provider' => env('IDENTITY_PROVIDER', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Multi-factor authentication enforcement
    |--------------------------------------------------------------------------
    |
    | Two-factor authentication is available to every user. When enabled,
    | accounts without confirmed two-factor authentication must set it up
    | before using the application. Off until DEC-013 is decided.
    |
    */

    'mfa_required' => (bool) env('IDENTITY_MFA_REQUIRED', false),

    /*
    |--------------------------------------------------------------------------
    | Development seed password
    |--------------------------------------------------------------------------
    |
    | Optional shared password for the fictitious [DEV] accounts created by
    | DevelopmentIdentitySeeder (local environment only). When empty, the
    | seeder generates a random password and prints it once.
    |
    */

    'development_seed_password' => env('DEV_SEED_PASSWORD'),

    // Explicit, manual staging-only demo seed. No personal email is stored
    // in this repository; operators inject it through their secret manager.
    'staging_admin_email' => env('STAGING_PILOT_ADMIN_EMAIL'),
    'staging_seed_confirm' => env('STAGING_PILOT_SEED_CONFIRM'),

];
