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

## Ouvrir un sujet sur le forum pour chaque nouvelle fiche

Le champ `discourse`, ajouté à un formulaire, ouvre un sujet sur Discourse
quand une fiche de ce formulaire est créée. Le sujet prend le titre de la
fiche, et son premier message renvoie vers la fiche. La fiche affiche ensuite
un bouton « Discuter sur le forum » qui mène au sujet.

Le sujet est publié au nom du compte Discourse de la personne qui a créé la
fiche, retrouvé grâce au SSO ci-dessus (l'`external_id` est le nom
d'utilisateur du wiki). Si elle ne s'est jamais connectée à Discourse, ou a
créé la fiche sans être connectée, le sujet est publié au nom de
`api_username`.

Une fiche qui n'a pas encore de sujet en reçoit un la prochaine fois qu'elle
est enregistrée : celle dont le sujet n'a pas pu être ouvert, ou celle qui
existait avant l'ajout du champ. Le sujet est alors publié au nom de la
personne qui l'enregistre. Une fiche qui a déjà un sujet le garde, et les
fiches importées n'en ouvrent pas.

### Réglages

Dans Discourse, rubrique Admin, API, créez une clé avec le niveau
d'utilisateur « Tous les utilisateurs ». Une clé liée à un seul compte ne
peut pas publier au nom de quelqu'un d'autre. Ajoutez ensuite l'adresse du
forum et la clé dans `yeswiki.config.php`, à côté de `sso_secret` :

```php
'discourse_connect' => [
    'sso_secret' => '<le secret SSO>',
    'url' => 'https://forum.example.org',
    'api_key' => '<la clé API>',
    'api_username' => 'system',
],
```

### Dans un formulaire

Le champ accepte une `category` facultative, le numéro d'une catégorie
Discourse. C'est le dernier nombre de l'adresse de la catégorie :
`/c/ateliers/5` donne `5`. Sans elle, les sujets vont dans la catégorie par
défaut de Discourse.

```json
{ "type": "discourse", "name": "bf_discourse", "label": "Forum", "category": "5" }
```

Le constructeur de formulaire n'a pas encore de bloc pour ce champ. Ajoutez-le
dans l'onglet « Code Wiki (pour faire des copier-coller) » du formulaire, en
collant la ligne ci-dessus dans la liste des champs. Le constructeur l'affiche
ensuite comme un champ personnalisé et le garde tel quel quand le formulaire
est enregistré à nouveau.

### Activité du sujet

À côté du bouton « Discuter sur le forum », la fiche affiche le nombre de
réponses et douze petites barres, une par semaine, avec le nombre de messages
de la semaine. La barre de droite est la semaine en cours, en plus foncé. Le
survol d'une barre donne ses dates et son nombre. Le wiki interroge Discourse
une fois par sujet et garde la réponse 15 minutes dans `cache/discourse/`.

Les semaines sont comptées d'après la chronologie de Discourse. Les notes de
modération comme « fermé » ou « épinglé » comptent comme des messages, et
au-delà de 300 messages dans un sujet, les semaines sont une estimation.

### Dans des cartes

L'extension ajoute une présentation en cartes, « Cartes avec l'activité du
forum ». C'est la carte standard, avec les réponses et les barres en bas de
chaque carte. Elle demande `dynamic="true"` :

```
{{entrylist id="12" template="discourse-card" dynamic="true"}}
```

Chaque carte ne demande son activité qu'au moment où elle apparaît à l'écran.
Une carte dont la fiche n'a pas de sujet reste telle quelle.

Pour un modèle de carte à vous, placez l'élément là où l'activité doit
s'afficher, et chargez son script une fois :

```twig
{{ include_javascript('extensions/discourse/javascripts/discourse-activity.js', false, true) }}
<discourse-activity entry="{{ entry.id_fiche }}"></discourse-activity>
```

Dans un modèle dynamique (Vue), écrivez plutôt `:entry="entry.tag"`.
L'élément appelle `/?api/discourse/entries/<fiche>/activity`, qui ne répond
que si la personne peut lire la fiche et le champ.

La carte côté serveur (`template="card"` sans `dynamic`) ne peut pas
l'afficher : elle ne connaît que des emplacements de texte.

### Quand le forum refuse le sujet

Si Discourse refuse le sujet lui-même, la fiche n'est pas enregistrée non plus :
le formulaire revient, rempli, avec le message du forum, pour qu'on corrige le
titre. Discourse refuse notamment :

- un titre plus court que son `min_topic_title_length`, 15 caractères par
  défaut ;
- un titre qu'il juge peu clair, comme « test » ;
- un titre déjà pris par un autre sujet, quand `allow_duplicate_topic_titles`
  est désactivé.

### Quand aucun sujet n'est créé

Dans les autres cas, la fiche est enregistrée sans sujet. Un message en donne
la raison après l'enregistrement, et le journal du wiki en garde une ligne.
Les causes habituelles :

- `url` ou `api_key` est vide.
- Le forum ne répond pas.
- Le compte qui publie n'a pas le droit d'écrire dans la catégorie.

## Ce qu'elle ne fait pas

- Être admin du wiki ne rend pas admin ou modérateur de Discourse.
- Elle ne peut pas tenir certains comptes du wiki à l'écart de Discourse.
- Se déconnecter du wiki ne déconnecte pas de Discourse.
