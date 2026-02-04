# LinkedInAuth (MediaWiki extension)

## Purpose
LinkedInAuth is a MediaWiki extension that adds LinkedIn OAuth login with a <linAuth> tag, dedicated Special pages (login, callback, auto-login, status, users), and automatic MediaWiki user creation and group assignment. Config is LocalSettings-only.

## Current status
- Parser tag: `<linAuth>...</linAuth>` renders as button/link/custom based on `type`
- Default login entry point is `Special:LinkedInAuthLogin` which runs the OAuth flow
- Callback handler is `Special:LinkedInAuthCallback`
- Auto-login handler is `Special:LinkedInAuthAutoLogin`
- Admin pages: `Special:LinkedInAuthStatus`, `Special:LinkedInAuthUsers`, `Special:LinkedInAuthWelcome`

## Features
1) Enable with:

```php
wfLoadExtension( 'LinkedInAuth' );
```

2) Tag for LinkedIn login button/link/custom output:

```wiki
<linAuth type="button">Apply via LinkedIn</linAuth>
<linAuth type="link">Apply via LinkedIn</linAuth>
<linAuth type="custom">
<html><a href="/Special:LinkedInAuthLogin" class="mw-ui-button">Apply via LinkedIn</a></html>
</linAuth>
```

Behavior:
- `type="button"` (default) outputs `<a class="mw-ui-button" href="...">TEXT</a>` using the configured login URL
- `type="link"` outputs `<a href="...">TEXT</a>` using the configured login URL
- `type="custom"` outputs the tag content as-is (wikitext expanded)

The tag name is case-insensitive (`<linAuth>` or `<linauth>`). Use it to embed the LinkedIn login UI on any page.

## Custom button example (tooltip)
```wiki
<linAuth type="custom">
<div class="tooltip-wrapper">
  <a class="hero-cta-button tooltip-trigger-expert"
     href="/Special:LinkedInAuthLogin">
      <span class="hero-action__icon hero-action__icon--linkedin"></span>
      <span class="hero-action__label">Apply via LinkedIn</span>
  </a>
  <div class="tooltip-content-expert">
    <strong>Apply via LinkedIn</strong><br>
    Sign in with your LinkedIn profile to immediately create and activate
    your account.<br><br>
    After logging in, you will be able to access content
    using your MediaWiki account.<br>
  </div>
</div>
</linAuth>
```

## Configuration
```php
// LinkedIn OAuth credentials and redirect.
$wgLinkedInAuthClientId = '';
$wgLinkedInAuthClientSecret = '';
$wgLinkedInAuthHmacKey = ''; // secret used to sign auto-login tokens
$wgLinkedInAuthRedirectUri = ''; // optional override for redirect_uri
$wgLinkedInAuthCallbackUrl = ''; // optional override for callback URL (e.g., legacy script)
$wgLinkedInAuthAutoLoginUrl = ''; // optional override for auto-login URL (e.g., legacy script)
$wgLinkedInAuthDefaultReturnTo = 'Main_Page'; // default landing page when returnTo is not provided

// Optional: override the login URL used by button/link outputs.
// If not set, the extension uses Special:LinkedInAuthLogin.
$wgLinkedInAuthLoginUrl = '';

// Optional debug log path. If empty or unset, defaults to $IP/sso_login_debug.log.
$wgLinkedInAuthLogPath = '';
$wgLinkedInAuthDebug = false;
```

## Default login entry point
The special page `Special:LinkedInAuthLogin` is used when no custom URL is configured.
It executes the OAuth login flow end-to-end inside the extension.

## Default callback entry point
The special page `Special:LinkedInAuthCallback` is the default redirect URI when no override is set.
Recommended LinkedIn app redirect URL (index.php form):
`https://your-wiki.example/index.php?title=Special:LinkedInAuthCallback`

## Default auto-login entry point
The special page `Special:LinkedInAuthAutoLogin` handles the automatic login flow.
You can set `$wgLinkedInAuthAutoLoginUrl` to keep using the legacy script if needed.

## Database tables
Run `php maintenance/run.php update` to create:
- `linkedinauth_tokens` (LinkedIn tokens + user mapping)

## Debug
To debug auto-login payloads (admins only), add `?debug=1` to `Special:LinkedInAuthAutoLogin`.

## Admin UI
The special pages are listed under a dedicated "LinkedIn Auth" section in `Special:SpecialPages`.
`Special:LinkedInAuthStatus` shows the current resolved configuration values (secrets masked).
It also provides a connectivity test to LinkedIn's OpenID configuration endpoint.
`Special:LinkedInAuthUsers` lists LinkedIn-created users and supports safe hard-delete for testing.

## Installation
Add to `LocalSettings.php`:

```php
wfLoadExtension( 'LinkedInAuth' );
```

## LocalSettings-only secrets
The extension reads credentials and the HMAC signing key from `LocalSettings.php` only.

## Notes

