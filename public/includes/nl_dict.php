<?php
// ============================================================
// nl_dict.php — DICTIONNAIRE d'interface FR → NL (néerlandais de Belgique).
//
//   L'INTERFACE (boutons, menus, paramètres…) est fixe : elle vit dans le code.
//   On la traduit UNE fois, ici, dans un fichier figé (généré à la main, corrigeable).
//   À l'exécution, pour un utilisateur en NL, on remplace chaque libellé FR par son NL.
//   -> aucune API, aucun coût, instantané, fiable.
//
//   ⚠️ Ce dictionnaire ne touche JAMAIS au contenu (guide, quiz) : celui-là est
//      traduit dynamiquement par Claude (voir i18n_nl.php). Ici : seulement l'interface.
//
//   Comment ça marche (nlDictApply) :
//     - on protège <script>/<style>/<textarea>/<pre> (on n'y touche pas) ;
//     - on remplace un texte entre balises SEULEMENT si, une fois « trimé », il est
//       EXACTEMENT une clé du dictionnaire (pas de remplacement partiel → pas de casse) ;
//     - on traduit aussi quelques attributs visibles (placeholder, title, alt, aria-label),
//       toujours en correspondance EXACTE.
// ============================================================

if (!function_exists('nlDict')) {
    /** Le dictionnaire FR => NL de l'interface. Ajoute/corrige librement une entrée. */
    function nlDict()
    {
        static $d = null;
        if ($d !== null) { return $d; }
        $d = [
            // — Navigation / actions générales —
            'Accueil' => 'Startpagina',
            'Retour' => 'Terug',
            'Quitter' => 'Verlaten',
            'Annuler' => 'Annuleren',
            'Valider' => 'Bevestigen',
            'Enregistrer' => 'Opslaan',
            'Modifier' => 'Bewerken',
            'Supprimer' => 'Verwijderen',
            'Ouvrir' => 'Openen',
            'Fermer' => 'Sluiten',
            'Créer' => 'Aanmaken',
            'Confirmer' => 'Bevestigen',
            'Continuer' => 'Doorgaan',
            'Rechercher' => 'Zoeken',
            'Oui' => 'Ja',
            'Non' => 'Nee',
            'Oui, continuer' => 'Ja, doorgaan',
            'J\'ai compris' => 'Begrepen',
            'Déconnexion' => 'Afmelden',
            'Notifications' => 'Meldingen',
            'Langue' => 'Taal',
            'Chargement…' => 'Laden…',
            'Chargement du document…' => 'Document laden…',

            // — Paramètres : onglets & sections —
            'Paramètres' => 'Instellingen',
            'Paramètres utilisateur' => 'Gebruikersinstellingen',
            'Gestion des modules' => 'Modulebeheer',
            'Gestion des utilisateurs' => 'Gebruikersbeheer',
            'Gestion des profils' => 'Profielbeheer',
            'Gestion des agences' => 'Kantorenbeheer',
            'Widget' => 'Widget',
            'Stockage' => 'Opslag',
            'Outils' => 'Hulpmiddelen',
            'Préférences' => 'Voorkeuren',
            'Modules' => 'Modules',
            'Profils' => 'Profielen',
            'Utilisateurs' => 'Gebruikers',
            'Agences intérim' => 'Uitzendkantoren',
            'Widget d\'accueil' => 'Startwidget',
            'Phrases qui défilent' => 'Doorlopende zinnen',
            'Créer un module' => 'Een module aanmaken',
            'Ce module contient d\'autres modules' => 'Deze module bevat andere modules',
            'Accès' => 'Toegang',
            'Choisir les profils…' => 'Profielen kiezen…',
            'Nom' => 'Naam',
            'Description' => 'Beschrijving',
            'Mot de passe' => 'Wachtwoord',
            'Identifiant' => 'Gebruikersnaam',

            // — Page module / contenu —
            'Ajout de contenu' => 'Inhoud toevoegen',
            'Le guide' => 'De gids',
            'Vidéo' => 'Video',
            'Modifier le contenu' => 'Inhoud bewerken',
            'Modifier le guide' => 'De gids bewerken',
            'Contrôler le quiz' => 'De quiz nakijken',
            'Modifier et uniformiser' => 'Bewerken en uniformiseren',
            'Valider et uniformiser' => 'Bevestigen en uniformiseren',
            'Passer le quiz' => 'Naar de quiz',
            'Ce module n\'a pas encore de contenu.' => 'Deze module heeft nog geen inhoud.',
            'Aucun sous-module pour l\'instant.' => 'Nog geen submodule.',
            'Fichier manquant' => 'Ontbrekend bestand',
            'Document actuel' => 'Huidig document',
            'Vidéo actuelle' => 'Huidige video',
            'Vidéo en préparation…' => 'Video wordt voorbereid…',
            'Sous-titres' => 'Ondertitels',
            'à évaluer' => 'te evalueren',
            'À évaluer' => 'Te evalueren',
            'Obligatoire' => 'Verplicht',

            // — Éditeur / relecture —
            'Relecture' => 'Nalezen',
            'Gras' => 'Vet',
            'Ajouter un bloc…' => 'Een blok toevoegen…',
            'Titre de section' => 'Sectietitel',
            'Paragraphe' => 'Paragraaf',
            'Liste' => 'Lijst',
            'Étapes' => 'Stappen',
            'Encadré' => 'Kadertekst',
            'Chiffres clés' => 'Kerncijfers',
            'Citation' => 'Citaat',
            'Aligner à gauche' => 'Links uitlijnen',
            'Centrer' => 'Centreren',
            'Aligner à droite' => 'Rechts uitlijnen',
            'Terminer' => 'Beëindigen',
            'Petite' => 'Klein',
            'Moyenne' => 'Gemiddeld',
            'Grande' => 'Groot',
            'Pivoter' => 'Draaien',

            // — Quiz —
            'Contrôle du quiz' => 'Quizcontrole',
            'Valider le quiz' => 'Quiz bevestigen',
            'Unique' => 'Enkel',
            'Multiple' => 'Meervoudig',
            'Ajouter une question' => 'Een vraag toevoegen',
            'Résultat immédiat' => 'Onmiddellijk resultaat',

            // — Divers —
            'Bienvenue sur Famiformation' => 'Welkom op Famiformation',
            'Formation terminée' => 'Opleiding voltooid',
            'NOUVEAU' => 'NIEUW',
            'Aucun résultat.' => 'Geen resultaat.',
            'Chargement de la vidéo…' => 'Video laden…',
        ];
        return $d;
    }
}

if (!function_exists('nlDictApply')) {
    /**
     * Applique le dictionnaire au HTML final (pour un utilisateur en NL).
     * Remplacement par correspondance EXACTE d'un nœud de texte (ou d'un attribut visible),
     * jamais partiel → aucune casse possible.
     */
    function nlDictApply($html)
    {
        $dict = nlDict();
        if (empty($dict) || $html === '' || $html === null) { return $html; }

        // 1) Protéger les zones à ne pas toucher (JS, CSS, champs multi-lignes, préformaté).
        $stash = [];
        $n = 0;
        $html = preg_replace_callback('#<(script|style|textarea|pre)\b[^>]*>.*?</\1>#is', function ($m) use (&$stash, &$n) {
            $key = "\x02FMDICT" . ($n++) . "\x03";
            $stash[$key] = $m[0];
            return $key;
        }, (string) $html);

        // 2) Texte entre deux balises : on remplace si le nœud (trimé) est EXACTEMENT une clé.
        $html = preg_replace_callback('#>([^<>]+)<#u', function ($m) use ($dict) {
            $raw = $m[1];
            $t = trim($raw);
            if ($t === '' || !isset($dict[$t])) { return $m[0]; }
            $lead = substr($raw, 0, strlen($raw) - strlen(ltrim($raw)));
            $trail = substr($raw, strlen(rtrim($raw)));
            return '>' . $lead . $dict[$t] . $trail . '<';
        }, $html);

        // 3) Attributs VISIBLES uniquement (jamais « value », qui peut être envoyé au serveur).
        $html = preg_replace_callback('#\b(placeholder|title|alt|aria-label)="([^"]*)"#u', function ($m) use ($dict) {
            $t = trim($m[2]);
            if ($t === '' || !isset($dict[$t])) { return $m[0]; }
            return $m[1] . '="' . htmlspecialchars($dict[$t], ENT_QUOTES) . '"';
        }, $html);

        // 4) Restaurer les zones protégées.
        if (!empty($stash)) { $html = strtr($html, $stash); }
        return $html;
    }
}
