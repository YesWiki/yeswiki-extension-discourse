<?php

return [
    'DISCOURSE_FORM_BUILDER_LABEL' => 'Discussion sur le forum',
    'DISCOURSE_FORM_BUILDER_HINT' => 'Enregistrer une fiche qui n\'a pas encore de sujet en ouvre un sur le forum Discourse, et la fiche y renvoie.',
    'DISCOURSE_FORM_BUILDER_NOT_CONFIGURED' => 'discourse_connect.url et discourse_connect.api_key ne sont pas renseignés sur ce wiki : aucun sujet ne sera ouvert.',
    'DISCOURSE_FORM_BUILDER_CATEGORY' => 'Catégorie du forum',
    'DISCOURSE_FORM_BUILDER_NO_CATEGORY' => 'Celle par défaut du forum',
    'DISCOURSE_FORM_BUILDER_CATEGORY_HINT' => 'Les sujets des fiches de ce formulaire y sont ouverts.',
    'DISCOURSE_FORM_BUILDER_CATEGORY_ID_HINT' => 'Le numéro de la catégorie Discourse. Le forum n\'a pas pu donner sa liste.',
    'DISCOURSE_FORM_BUILDER_TAGS' => 'Étiquettes des sujets',
    'DISCOURSE_FORM_BUILDER_TAGS_PLACEHOLDER' => 'atelier, parcours',
    'DISCOURSE_FORM_BUILDER_TAGS_HINT' => 'Séparées par des virgules. Elles sont posées par le compte api_username, qui crée celles que le forum n\'a pas encore s\'il en a le droit.',
    'DISCOURSE_FORM_BUILDER_TAGS_EXISTING' => 'Séparées par des virgules. Déjà sur le forum : %{tags}',
    'DISCOURSE_FORM_BUILDER_TOPIC_BODY' => 'Premier message du sujet',
    'DISCOURSE_FORM_BUILDER_TOPIC_BODY_HINT' => 'En markdown. {{title}} donne le titre de la fiche, {{url}} son adresse, et {{bf_…}} la valeur d\'un champ. Vide, le message renvoie simplement vers la fiche.',
];
