# discourse extension

Lets a Discourse forum use this wiki's accounts to log in, through Discourse's
own DiscourseConnect protocol (once called "Discourse SSO").

It is much lighter than an OpenID Connect server. The wiki and Discourse share
one secret, and the wiki answers on one URL. The wiki keeps no session or token
for this. Discourse generates and checks its own one-time `nonce`.

## How a login goes

1. A visitor clicks "Log in" on Discourse. Discourse sends them to the wiki's
   `/?api/discourse/sso` with a signed payload.
2. If the visitor is not logged into the wiki, the same URL shows the wiki's
   login form. Once they log in, they come back to that URL.
3. The wiki signs a reply with the shared secret (username, email, a user ID
   that never changes) and sends the browser back to Discourse. Discourse logs
   the user in, and creates their Discourse account the first time.

Both sides must be served over HTTPS and hold the exact same secret. One stray
character and every login fails with "Invalid SSO signature".

## 1. Set up YesWiki

Switch the extension on, from `/admin/updates` or from a shell:

```sh
./yeswicli extension:enable discourse
```

Generate a long random secret:

```sh
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

Add it to the wiki's `yeswiki.config.php`:

```php
'discourse_connect' => [
    'sso_secret' => '<the generated secret>',
],
```

That is all on the wiki side. There is no page to create and nothing to embed.
The URL Discourse needs is:

```
https://<your-wiki-domain>/?api/discourse/sso
```

## 2. Set up Discourse

In the Discourse admin, under Settings, Login (or the matching keys in
`app.yml` for a Docker install), set:

| Setting | Value |
|---|---|
| `enable_discourse_connect` | `true` |
| `discourse_connect_url` | `https://<your-wiki-domain>/?api/discourse/sso` |
| `discourse_connect_secret` | the same secret as `sso_secret` above |

Two optional settings matter here:

- `auth_immediately` sends visitors straight to the wiki's login, skipping
  Discourse's own login screen.
- `discourse_connect_allows_all_return_paths` should stay off unless you need
  redirects to arbitrary paths after login. Discourse checks `return_sso_url`
  itself.

Any wiki account can log into Discourse. There is no group restriction yet.
The wiki username becomes the Discourse `external_id`, which is the permanent
link between the two accounts, and the suggested Discourse username. It is the
username and not the email because a wiki user can change their email. The
wiki sends `email_verified=true` every time, since YesWiki has no separate
email confirmation step.

## When it goes wrong

- "Invalid SSO signature" means the two secrets differ. Look for a trailing
  space or newline picked up when pasting.
- "Discourse Connect is not configured on this wiki" means `sso_secret` is
  empty in `yeswiki.config.php`.
- "Invalid or malformed SSO request" means `sso` or `sig` is missing, or the
  payload lacks `nonce` or `return_sso_url`. The request most likely did not
  come from the Discourse that has this wiki as its `discourse_connect_url`.

## What it does not do

- Being a wiki admin does not make you a Discourse admin or moderator.
- It cannot keep some wiki accounts out of Discourse.
- Logging out of the wiki does not log you out of Discourse.
