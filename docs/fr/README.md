# Extension discourse

Permet à un forum Discourse d'utiliser les comptes du wiki pour se connecter,
avec le protocole DiscourseConnect de Discourse (anciennement « Discourse SSO »).

C'est bien plus léger qu'un serveur OpenID Connect. Le wiki et Discourse
partagent un secret, et le wiki répond sur une seule URL. Le wiki ne garde ni
session ni jeton pour ça. Discourse génère et vérifie lui-même son `nonce` à
usage unique.

## Comment se passe une connexion

1. Une personne clique sur « Se connecter » dans Discourse. Discourse l'envoie
   sur `/?api/discourse/sso` du wiki, avec une charge signée.
2. Si elle n'est pas connectée au wiki, la même URL affiche le formulaire de
   connexion du wiki. Une fois connectée, elle revient sur cette URL.
3. Le wiki signe une réponse avec le secret partagé (nom d'utilisateur, email,
   un identifiant qui ne change jamais) et renvoie le navigateur vers Discourse.
   Discourse la connecte, et crée son compte Discourse la première fois.

Les deux côtés doivent être servis en HTTPS et avoir exactement le même
secret. Un caractère de trop et chaque connexion échoue avec « Signature SSO
invalide ».

## 1. Configurer YesWiki

Activez l'extension, depuis `/admin/updates` ou en ligne de commande :

```sh
./yeswicli extension:enable discourse
```

Générez un long secret aléatoire :

```sh
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

Ajoutez-le dans le `yeswiki.config.php` du wiki :

```php
'discourse_connect' => [
    'sso_secret' => '<le secret généré>',
],
```

C'est tout côté wiki. Il n'y a pas de page à créer ni rien à insérer.
L'URL à donner à Discourse est :

```
https://<domaine-du-wiki>/?api/discourse/sso
```

## 2. Configurer Discourse

Dans l'administration de Discourse, rubrique Paramètres, Connexion (ou les clés
équivalentes dans `app.yml` pour une installation Docker), réglez :

| Paramètre | Valeur |
|---|---|
| `enable_discourse_connect` | `true` |
| `discourse_connect_url` | `https://<domaine-du-wiki>/?api/discourse/sso` |
| `discourse_connect_secret` | le même secret que `sso_secret` ci-dessus |

Deux réglages facultatifs comptent ici :

- `auth_immediately` envoie directement sur la connexion du wiki, sans passer
  par l'écran de connexion de Discourse.
- `discourse_connect_allows_all_return_paths` reste désactivé, sauf besoin de
  rediriger vers n'importe quel chemin après connexion. Discourse vérifie
  lui-même `return_sso_url`.

Tout compte du wiki peut se connecter à Discourse. Il n'y a pas encore de
restriction par groupe. Le nom d'utilisateur du wiki devient l'`external_id`
Discourse, le lien permanent entre les deux comptes, et le nom d'utilisateur
proposé dans Discourse. C'est le nom et pas l'email parce qu'on peut changer
son email dans le wiki. Le wiki envoie toujours `email_verified=true`, YesWiki
n'ayant pas d'étape de confirmation d'email à part.

## En cas de problème

- « Signature SSO invalide » : les deux secrets diffèrent. Cherchez un espace
  ou un retour à la ligne attrapé au copier-coller.
- « Discourse Connect n'est pas configuré sur ce wiki » : `sso_secret` est vide
  dans `yeswiki.config.php`.
- « Requête SSO invalide ou malformée » : `sso` ou `sig` manque, ou la charge
  n'a pas de `nonce` ou de `return_sso_url`. La requête ne vient sans doute pas
  du Discourse qui a ce wiki comme `discourse_connect_url`.

## Ce qu'elle ne fait pas

- Être admin du wiki ne rend pas admin ou modérateur de Discourse.
- Elle ne peut pas tenir certains comptes du wiki à l'écart de Discourse.
- Se déconnecter du wiki ne déconnecte pas de Discourse.
