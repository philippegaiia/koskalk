# Language Selector Design

**Date:** 2026-07-11

## Goal

Give every visitor an obvious way to choose the interface language before authentication and throughout the signed-in application, while remembering the preference appropriately for guests and registered users.

## Decisions

- Language selection is globally available, not confined to Settings.
- The selector appears on the public site, authentication pages, free calculator, and signed-in application shell.
- Registered users persist their choice in `users.locale`.
- Guests persist an explicit choice in the session and a long-lived `soapkraft_locale` cookie.
- Browser language detection is used only when no saved user, session, or cookie preference exists.
- Only active `supported_locales` are selectable.
- Language and number format remain independent preferences.
- The selector is non-blocking; there is no first-visit modal.

## Locale Resolution

For each public application request, resolve the interface locale in this order:

1. Authenticated user's active saved locale.
2. Active locale stored in the session.
3. Active locale stored in the explicit preference cookie.
4. First active locale matching the browser `Accept-Language` header.
5. Active default locale.
6. Laravel fallback locale (`en`) as the final safeguard.

An inactive or unknown preference is ignored. Locale matching first attempts the exact supported code and then its base language, so `fr-FR` can resolve to registered locale `fr`.

The Filament admin always remains English through its existing admin-locale middleware.

## Persistence

- Add nullable `locale` (16 characters) to `users`, constrained to `supported_locales.code` with update cascade and delete restriction.
- The locale-switch endpoint accepts only active locale codes.
- For an authenticated user, switching language updates `users.locale`, session, and cookie.
- For a guest, switching language updates session and cookie.
- Registration initializes the new user's locale from the currently resolved active guest locale.
- Login does not overwrite an existing saved user preference.

The preference cookie is not created from passive browser detection. It records only an explicit user choice.

## User Interface

- Create one reusable Blade language-selector component.
- Use a globe icon plus the current locale code or native name.
- Menu options display each language's `native_name`, allowing users to recognize their language even when the current interface is unfamiliar.
- The current language is marked and disabled.
- Switching submits a CSRF-protected POST request and returns to the current page.
- The control has an accessible label and keyboard-operable native semantics.

Placement:

- Public shell header: homepage and authentication pages.
- Calculator shell header: free calculator.
- Signed-in app shell: global utility area, available on desktop and mobile.
- Settings profile section: full language select beside the separate number-format preference.

## Application Components

- `App\Services\LocalePreferenceResolver`: active locale lookup, browser matching, and precedence.
- `App\Http\Middleware\SetApplicationLocale`: applies the resolved locale before views and route handlers run.
- `App\Http\Controllers\LocalePreferenceController`: validates explicit switches and persists them.
- `App\View\Components\LanguageSelector` or an equivalent shared Blade component: supplies active locale options and renders the control.

The locale middleware applies to the public web application but not to the Filament admin locale, which remains forced to English.

## Translation Boundaries

- Interface labels continue resolving through Laravel and `spatie/laravel-translation-loader`.
- Platform ingredient names continue resolving through `ingredient_translations`.
- Number formatting continues using the separate number-locale preference.
- If an interface or ingredient translation is missing, existing English fallback behavior remains in effect.

## Safety And Edge Cases

- Redirect only to a local previous URL; otherwise use the dashboard or homepage.
- Reject inactive and unknown locales.
- If a saved locale is later deactivated, fall back without deleting the stored preference.
- Do not infer language from IP address or country.
- Do not automatically change number format when language changes.
- Do not expose inactive languages simply because translation rows exist.

## Testing

- Resolution precedence for user, session, cookie, browser, default, and fallback.
- Exact and base-language browser matching.
- Rejection of inactive and unknown locale switches.
- Guest session and cookie persistence.
- Authenticated user persistence.
- Registration inheritance without login overwrite.
- Selector rendering on public, calculator, authentication, and app shells.
- Settings language persistence independent from number format.
- Filament admin remains English.
- Existing interface and ingredient translation fallbacks continue working.

## Out Of Scope

- Translating additional hard-coded application content in this slice.
- Automatic or machine translation.
- Geographic language detection.
- A mandatory language-selection modal.
- Tying interface language to number or currency formatting.
