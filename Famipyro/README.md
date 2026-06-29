# Famipyro

Boutique de vente de feux d'artifice en PHP/MySQL avec panier client, impression de commande et espace admin.

## Fonctions incluses

- vitrine inspirée d'une borne catalogue verte
- catégories de produits comme sur votre maquette
- panier avec ajout et suppression d'articles
- création de commande sans paiement en ligne
- page d'impression pour préparation par un collègue
- espace admin pour ajouter une référence, gérer le stock et activer/désactiver un produit
- espace admin pour encoder des promotions de type X achetés = Y gratuits par numéro d'article
- application automatique des promotions dans le panier, le total de commande et l'impression
- chargement d'image produit depuis l'ordinateur

## Installation

1. Créez une base MySQL nommée famipyro.
2. Importez le fichier database.sql.
3. Ajustez les accès MySQL dans includes/config.php.
4. Déployez le dossier sur votre hébergement IONOS.

## Accès admin

- utilisateur : admin
- mot de passe : admin123

Pensez à modifier ces identifiants avant la mise en ligne.

## Impression sans validation

Le site ouvre directement la fenêtre d'impression. Pour éviter la demande de confirmation du navigateur, le poste de préparation doit être configuré en mode kiosque d'impression sur Edge ou Chrome, car un navigateur web ne peut pas forcer une impression silencieuse standard sans réglage local.
