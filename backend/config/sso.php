<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
$csv = static function (?string $value, string $default = ''): array {
    return array_values(array_filter(
        array_map('trim', explode(',', $value ?: $default)),
        static fn (string $item): bool => $item !== ''
    ));
};

return [
    'enabled' => env('SSO_ENABLED', false),

    'driver' => env('SSO_DRIVER', 'oidc'),

    'auth_mode' => env('AUTH_MODE', 'local'),

    // Fork: example address shown in the login form's e-mail field (e.g. first.last@school.example).
    'login_email_placeholder' => env('LOGIN_EMAIL_PLACEHOLDER', 'max.mustermann@example.com'),

    'oidc' => [
        'display_name' => env('OIDC_DISPLAY_NAME', 'Company SSO'),

        'issuer_url' => env('OIDC_ISSUER_URL'),
        'client_id' => env('OIDC_CLIENT_ID'),
        'client_secret' => env('OIDC_CLIENT_SECRET'),
        'redirect_uri' => env('OIDC_REDIRECT_URI', env('APP_URL').'/auth/oidc/callback'),

        'scopes' => array_values(array_filter(
            explode(' ', (string) env('OIDC_SCOPES', 'openid profile email'))
        )),

        'require_verified_email' => env('OIDC_REQUIRE_VERIFIED_EMAIL', true),
        // Fork: claims that mark the email as verified when true. Microsoft Entra ID sends
        // xms_edov (an optional claim) instead of email_verified.
        'email_verified_claims' => $csv(env('OIDC_EMAIL_VERIFIED_CLAIMS'), 'email_verified'),
        'allowed_domains' => $csv(env('OIDC_ALLOWED_DOMAINS')),

        'provisioning_mode' => env('OIDC_PROVISIONING_MODE', 'disabled'),
        'sync_user_profile' => env('OIDC_SYNC_USER_PROFILE', true),
        'store_claims' => env('OIDC_STORE_CLAIMS', false),

        'sync_roles' => env('OIDC_SYNC_ROLES', false),
        'sync_roles_on_login' => env('OIDC_SYNC_ROLES_ON_LOGIN', false),
        'sync_roles_remove_unmapped' => env('OIDC_SYNC_ROLES_REMOVE_UNMAPPED', false),
        'groups_claim' => env('OIDC_GROUPS_CLAIM', 'groups'),

        'logout_mode' => env('OIDC_LOGOUT_MODE', 'local'),

        'clock_tolerance' => (int) env('OIDC_CLOCK_TOLERANCE', 60),
        'token_endpoint_auth_method' => env('OIDC_TOKEN_ENDPOINT_AUTH_METHOD', 'client_secret_basic'),

        'auto_provision' => [
            'default_role' => env('OIDC_AUTO_PROVISION_DEFAULT_ROLE', 'user'),
            'default_department_id' => env('OIDC_AUTO_PROVISION_DEFAULT_DEPARTMENT_ID'),
        ],

        'role_mapping' => [
            'VisitorPortal-Admins' => 'admin',
            'VisitorPortal-Reception' => 'receptionist',
            'VisitorPortal-Managers' => 'manager',
            'VisitorPortal-Users' => 'user',
        ],
    ],
];
