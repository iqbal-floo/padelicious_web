# Google sign-in

The backend endpoint is built and tested. It needs **your** OAuth
credentials before it will do anything — those can only be created in your
own Google Cloud project.

## What exists

`POST /api/v1/auth/google` with `{"access_token": "..."}`:

- verifies the token with Google via Socialite (`stateless()`),
- finds an existing `user_auth_identities` row for that Google id, or
- links to an existing account **with the same email** (so a password user
  who later uses Google keeps one account), or
- creates a new user with `password_hash = null` (social-only),
- creates the player profile, issues a Sanctum token, returns it.

Until credentials are set it returns **503** with
`"Google sign-in is not configured on this server."` — the Flutter client
turns that into "Google sign-in is not set up yet" rather than an error.

## Setup

1. Google Cloud Console → APIs & Services → Credentials.
2. Create an **OAuth 2.0 Client ID**. For the native app flow you need a
   client per platform (Android, iOS) plus a Web client, because
   `google_sign_in` on Android returns a token issued to the Web client.
3. Add to `.env`:

```
GOOGLE_CLIENT_ID=...apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=...
GOOGLE_REDIRECT_URI=https://your-host/api/v1/auth/google/callback
```

No migration or code change is needed — `config/services.php` already
reads these.

## Remaining Flutter work

The button currently shows "not set up yet". To finish:

1. `flutter pub add google_sign_in`
2. Platform config: `android/app/google-services.json` (or the OAuth client
   id in `build.gradle`), and the URL scheme in `ios/Runner/Info.plist`.
3. In the button handler, get the token and hand it to the existing
   method, which is already written and tested:

```dart
final account = await GoogleSignIn().signIn();
final gAuth = await account!.authentication;
final result = await appState.auth.signInWithGoogle(gAuth.accessToken!);
```

This was not added because the plugin needs platform credentials to even
build a working flow, and adding native config that cannot be verified
would be guesswork.
