# discourse extension

Lets a Discourse forum use this wiki's user accounts as its login provider, via
Discourse's own **DiscourseConnect** protocol (formerly known as "Discourse SSO").

This is a much lighter integration than a full OpenID Connect server: there is a
single shared secret between the wiki and Discourse, and one endpoint. YesWiki
keeps no session/token state of its own for this — Discourse generates and
verifies its own one-time `nonce`.

## How it works, in short

1. A visitor clicks "Log in" on Discourse. Discourse redirects them to this
   wiki's `/api/discourse/sso` endpoint with a signed payload.
2. If the visitor isn't logged into the wiki, they see the wiki's normal login
   form right there (no extra redirect). Once they log in, they land back on
   the same URL automatically.
3. Once logged in, the wiki signs a response payload (username, email, a
   stable user ID) with the shared secret and redirects the browser back to
   Discourse, which logs the user in (creating the Discourse account on first
   login).

Both sides must be reachable over **HTTPS**, and must be configured with the
**exact same secret** - if they don't match byte-for-byte, every login attempt
fails with an invalid-signature error.

## 1. Configure YesWiki

Generate a long random secret, for example:

```sh
openssl rand -hex 32
```

Add it to this wiki's `wakka.config.php`:

```php
$wakkaConfig['discourse_connect']['sso_secret'] = '<paste the generated secret here>';
```

That's the only configuration needed on the YesWiki side. The endpoint is a
fixed URL - you don't need to create a wiki page or embed anything:

```
https://<your-wiki-domain>/?api/discourse/sso
```

## 2. Configure Discourse

In Discourse's admin panel, go to **Settings → Login** (or set the equivalent
keys in `discourse.conf` / environment variables for a Docker install) and set:

| Setting | Value |
|---|---|
| `enable_discourse_connect` | `true` |
| `discourse_connect_url` | `https://<your-wiki-domain>/?api/discourse/sso` |
| `discourse_connect_secret` | the exact same secret as `sso_secret` above |

Optional, depending on how you want the integration to behave:

- `auth_immediately` - if enabled, visitors are sent straight to the wiki's
  login instead of seeing Discourse's own login screen first.
- `discourse_connect_allows_all_return_paths` - leave this off (default)
  unless you specifically need to support redirecting to arbitrary paths after
  login; Discourse validates `return_sso_url` itself.

Any YesWiki account can log into Discourse this way - there is currently no
group restriction. The user's wiki username is used as both the Discourse
`external_id` (the permanent, unchanging account link - this is why it's the
username and not the email, since the email is editable in a wiki account) and
the suggested Discourse username. The wiki's authentication is trusted as
sufficient proof of the email address (`email_verified` is always sent as
`true`) - YesWiki itself has no separate email-confirmation step.

## Troubleshooting

- **"Invalid SSO signature"**: the two `sso_secret` / `discourse_connect_secret`
  values don't match exactly (check for trailing whitespace/newlines when
  pasting).
- **"Discourse Connect is not configured on this wiki"**: `sso_secret` is empty
  in `wakka.config.php` on the YesWiki side.
- **"Invalid or malformed SSO request"**: the `sso`/`sig` query parameters are
  missing, or the payload doesn't contain a `return_sso_url`/`nonce` - usually
  means the request didn't actually come from Discourse's configured
  `discourse_connect_url`.

## What this tool intentionally does not do

- No admin/moderator mapping: being a wiki admin does not make you a Discourse
  staff member.
- No group-based access restriction: any wiki account can SSO into Discourse.
- No single sign-out: logging out of the wiki does not log you out of
  Discourse.
