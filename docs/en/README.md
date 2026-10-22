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

## Opening a forum topic for each new entry

The `discourse` field, added to a form, opens a topic on Discourse when an
entry of that form is created. The topic takes the entry's title, and its
first post links back to the entry. The entry then shows a "Discuss on the
forum" button pointing to the topic.

The topic is posted as the Discourse account of whoever created the entry,
found through the SSO above (`external_id` is the wiki username). If that
person has never logged into Discourse, or created the entry without being
logged in, the topic is posted as `api_username`.

An entry that has no topic yet gets one the next time it is saved: one whose
topic could not be opened, or one that existed before the field was added. The
topic is then posted in the name of whoever saves it. An entry that already
has a topic keeps it, and imported entries open none.

### Settings

In Discourse, under Admin, API, create a key with the user level "All users".
A key tied to a single user cannot post in someone else's name. Then add the
forum's address and the key to `yeswiki.config.php`, next to `sso_secret`:

```php
'discourse_connect' => [
    'sso_secret' => '<the SSO secret>',
    'url' => 'https://forum.example.org',
    'api_key' => '<the API key>',
    'api_username' => 'system',
],
```

### In a form

In the form designer, add a "Forum discussion" block. Its settings:

- **Forum category**: where the topics go, picked from the forum's list.
  Without one, topics land in Discourse's default category. When the forum
  does not answer, the designer asks for the category's number instead: the
  last number in its address, `/c/ateliers/5` is `5`.
- **Help text**: a sentence shown under the field in the entry form.
- **Read and write access**, under the advanced settings: who sees the forum
  button, and whose saves may open a topic.

The field has no default value and cannot be required: the topic only exists
once the entry is saved. In the form's wiki code it reads:

```json
{ "type": "discourse", "name": "bf_discourse", "label": "Forum", "category": "5" }
```

### Topic activity

Next to the "Discuss on the forum" button, the entry shows the number of
replies and twelve small bars, one per week, with the number of posts in that
week. The bar on the right is the current week, drawn darker. Hovering a bar
gives its dates and count. The wiki asks Discourse once per topic and keeps
the answer for 15 minutes in `cache/discourse/`.

The weeks are counted from Discourse's own timeline. Moderator notes such as
"closed" or "pinned" count as posts, and in a topic of more than 300 posts the
weeks are an estimate.

### In cards

The extension adds a card presentation, "Cards with forum activity". It is
the standard card plus the replies and bars at the bottom of each card. It
needs `dynamic="true"`:

```
{{entrylist id="12" template="discourse-card" dynamic="true"}}
```

Each card asks for its activity only when it scrolls into view. A card whose
entry has no topic stays as it is.

For a card template of your own, put the element where the activity should
go, and load its script once:

```twig
{{ include_javascript('extensions/discourse/javascripts/discourse-activity.js', false, true) }}
<discourse-activity entry="{{ entry.id_fiche }}"></discourse-activity>
```

In a dynamic (Vue) template, write `:entry="entry.tag"` instead. The element
calls `/?api/discourse/entries/<entry>/activity`, which answers only if the
reader may read the entry and the field.

The server-side card (`template="card"` without `dynamic`) cannot show it: it
only knows text slots.

### When the forum refuses the topic

If Discourse refuses the topic itself, the entry is not saved either: the form
comes back, filled in, with the forum's message, so the title can be fixed.
Discourse refuses, among others:

- a title shorter than its `min_topic_title_length`, 15 characters by default;
- a title it finds unclear, such as "test";
- a title another topic already has, when `allow_duplicate_topic_titles` is
  off.

### When no topic is created

Otherwise the entry is saved without a topic. A message says why after the
save, and the wiki's log keeps a line about it. The usual causes:

- `url` or `api_key` is empty.
- The forum does not answer.
- The account posting the topic is not allowed to post in the category.

## What it does not do

- Being a wiki admin does not make you a Discourse admin or moderator.
- It cannot keep some wiki accounts out of Discourse.
- Logging out of the wiki does not log you out of Discourse.
