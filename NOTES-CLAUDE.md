# Notes de session autonome — Claude

> Travail réalisé pendant l'absence de Jimmy.
> Tout est **codé, linté (PHP 8.3.32), commité et poussé** sur `main` (donc déployé).
> Ce fichier explique **ce que j'ai fait, pourquoi, et ce qu'il te reste à faire.**

---

## ⚡ CE QUE TU DOIS FAIRE EN PREMIER (2 minutes)

Tout le code est en place, mais **deux clés manquent dans Railway → Variables**.
Sans elles, les nouvelles fonctions ne s'activeront pas (le site continue de tourner normalement, mais en silence).

| Variable | Sert à | Sans elle |
|---|---|---|
| `ANTHROPIC_API_KEY` | Uniformisation du guide, quiz, **traduction NL du contenu** | Pas de mise en forme IA, pas de quiz, **pas de néerlandais** |
| `OPENAI_API_KEY` | **Whisper** : transcription des vidéos → sous-titres + quiz depuis la vidéo | Les vidéos n'auront des sous-titres **que** si un `.srt` est fourni à la main |

**Vérification facile :** Paramètres → onglet **🧰 Outils** → chaque brique affiche ✓ OK ou ✗ à configurer. L'état est testé **en direct**.

**Optionnel :**
- `FAMI_STT_PROVIDER=groq` + `GROQ_API_KEY` → transcription **moins chère** (même API, j'ai prévu le cas).
- `MYMEMORY_EMAIL` → élargit le quota gratuit de traduction des textes courts.

---

## ✅ Ce qui a été fait

### 1. Bilingue NL — le vrai trou était ailleurs que prévu
Le site avait bien `nom_nl` / `description_nl`… **mais le GUIDE et le QUIZ n'avaient AUCUNE version NL.**
Un utilisateur néerlandophone voyait le titre en NL et **tout le contenu en français**.

- Nouvelles colonnes : `contenu_ia_nl`, `quiz_json_nl`, `nl_hash`.
- **Synchronisation automatique** : à chaque enregistrement (contenu, relecture, quiz), le NL est régénéré **en tâche de fond** → tu n'attends pas les ~40-80 s de traduction.
- **Bug corrigé** : il n'y avait **aucun champ NL** dans le formulaire de module. Il y a maintenant un volet *« 🌐 Néerlandais (facultatif) »* : **vide = traduit automatiquement**, rempli = **ta correction fait autorité**.
- **Bouton de secours** *« 🌐 Traduire en NL »* sur chaque module + un indicateur *« à jour / à rafraîchir / pas encore traduit »*.

**Décision technique (importante) :** je n'envoie **jamais** le JSON brut à l'IA — elle pourrait renommer une clé, réordonner des options ou **casser les indices des bonnes réponses**. J'**extrais** les chaînes traduisibles, je les fais traduire **en lot** (même longueur, même ordre, **refus** si le compte ne tombe pas juste), puis je les **réinjecte** à leur place exacte.
→ Images, tailles, rotations, type de question et bonnes réponses restent **intacts**, par construction.

*Le `quiz_check.php` continue de corriger sur le FR : c'est volontaire et sans risque, les indices sont identiques dans les deux langues.*

### 2. Sous-titres vidéo (Whisper) — le scaffold existant était inutilisable
`transcription.php` existait, mais :
- **limite 25 Mo** alors que les vidéos vont jusqu'à **1 Go** → il aurait **toujours** échoué ;
- il renvoyait du **texte brut sans timecodes** → impossible d'afficher des sous-titres ;
- il n'était **branché nulle part**.

Moteur réécrit :
- **Extraction audio par ffmpeg** (déjà dans l'image Docker) : mp3 mono 16 kHz → **~3 Mo pour 10 min**. C'est **ce** point qui débloque tout : on passe enfin sous la limite des 25 Mo (~70 min de vidéo), et on n'envoie pas l'image (moins cher, plus rapide).
- Format **SRT** demandé (timecodes) → converti en **WebVTT**, seul format lu par les navigateurs.
- **Traduction NL des sous-titres** avec **timecodes conservés**.
- Le lecteur affiche **2 pistes** (Français / Nederlands), celle de la langue de l'utilisateur activée par défaut.
- **Le quiz est enrichi par la vidéo** : une fois le transcript disponible, le quiz est régénéré sur *guide + vidéo*.

**Contrainte UX respectée :** l'utilisateur **n'a rien à faire**. Le champ `.srt` est **facultatif, replié**, avec la mention *« inutile dans la plupart des cas : le site le fait tout seul »*. Il n'est là que pour ceux qui ont **déjà** un `.srt` (c'est alors gratuit et plus exact).

**Décision API vs installation :** j'ai choisi l'**API** (le site est sur Railway ; un Whisper local demanderait un binaire lourd + du CPU). **Mais tout est derrière une abstraction** (`famiSttProvider` / `famiSttRun` + `FAMI_STT_PROVIDER`) : basculer vers un **Whisper LOCAL** quand le site tournera chez toi ne demandera qu'**un cas supplémentaire** dans `famiSttRun()`. Rien d'autre à toucher.

**TTS néerlandais (voix synthétique) :** volontairement **non implémenté**. Pas de solution gratuite correcte, et les sous-titres NL suffisent à rendre la vidéo bilingue. Je n'ai pas voulu bloquer le projet là-dessus.

### 3. Onglet « 🧰 Outils »
Page **informative** : chaque brique (Claude, Whisper, MyMemory, ffmpeg, poppler, volume, PHP, MySQL, Railway) avec **son rôle, son état vérifié en direct, où se règle sa clé, et son coût**. Plus un schéma qui montre l'enchaînement complet.
Ce n'est pas une liste écrite en dur : elle **teste réellement** les clés et les binaires à chaque affichage.

---

## ⚠️ LE POINT À SURVEILLER (le seul vrai risque)

Beaucoup de choses reposent sur les **tâches de fond** (`nohup php worker &`) :
compression vidéo 720p, sous-titres, traduction NL.

**Ce mécanisme n'a JAMAIS été vérifié en conditions réelles.** Il vient du code existant (compression vidéo), mais personne n'a encore confirmé qu'il tourne vraiment sur Railway.

**Si ça ne marche pas**, les symptômes seront :
- une vidéo qui reste bloquée sur **« en préparation »** ;
- un module qui reste sur **« pas encore traduit »**.

**Filets de sécurité déjà en place :**
- Le **français reste toujours affiché** si le NL manque → **aucune régression possible** pour l'utilisateur.
- Le bouton **« 🌐 Traduire en NL »** fonctionne **en direct** (synchrone) : il te dépanne même si le fond est cassé.
- La vidéo reste **parfaitement lisible** même sans sous-titres.

**Premier test à faire à ton retour :** dépose une vidéo. Si elle passe de *« en préparation »* à lisible → **tout le pipeline fonctionne** (compression, sous-titres, quiz, NL). Sinon, dis-le-moi et je branche un diagnostic + un bouton « relancer ».

---

## 🧰 Note d'environnement

Ce PC n'avait **pas de PHP**. J'ai installé un **PHP 8.3.32 portable** (même version que la prod) dans :

```
C:\Users\Enylson.Laine\php-portable\php.exe
```

Chaque fichier PHP modifié a été **linté** avant chaque commit, comme demandé.

---

## 📌 Reste à faire (pour qu'on en parle)

- **Coordonner guide + vidéo** avant le quiz (aujourd'hui l'avertissement de complétion est *par page*, pas combiné).
- **Étendre le fond vectoriel** aux pages déconnectées (`login.php`).
- **Vérifier la refonte** du bloc « Ajout de contenu ».
- Décider si on garde l'API Whisper ou si on passe au **Whisper local** (l'abstraction est prête).
