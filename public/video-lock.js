/*
 * video-lock.js — Empêche d'avancer sur la timeline des vidéos YouTube.
 *
 * Les contrôles (lecture, pause, volume, RETOUR EN ARRIÈRE) restent
 * disponibles, mais tout saut VERS L'AVANT au-delà de la partie déjà
 * regardée est annulé : la vidéo revient au point le plus loin atteint.
 *
 * Gère deux situations :
 *   1) Pages avec une <iframe> YouTube simple  -> on "adopte" l'iframe.
 *   2) Pages qui créent elles-mêmes un lecteur (var player = new YT.Player)
 *      -> on se branche sur ce lecteur global sans toucher à leur logique
 *         (déblocage de quiz, validation de la vue, etc.).
 *
 * Neutralise aussi les overlays transparents qui bloquaient les clics,
 * pour que les contrôles redeviennent utilisables.
 */
(function () {
    "use strict";

    function nowMs() { return new Date().getTime(); }

    // Anti-avance générique pour un lecteur YT.Player donné
    function installAntiSkip(player) {
        var maxTime = -1;          // -1 = pas encore initialisé
        var lastWall = nowMs();
        setInterval(function () {
            if (!player || typeof player.getCurrentTime !== "function") return;
            var t = player.getCurrentTime();
            if (typeof t !== "number" || isNaN(t)) return;
            var now = nowMs();
            if (maxTime < 0) { maxTime = t; lastWall = now; return; }
            var elapsed = (now - lastWall) / 1000;
            lastWall = now;
            var rate = (typeof player.getPlaybackRate === "function" ? player.getPlaybackRate() : 1) || 1;
            var allowed = elapsed * rate + 0.75; // avance normale tolérée
            if (t > maxTime + allowed) {
                try { player.seekTo(maxTime, true); } catch (e) {}
            } else if (t > maxTime) {
                maxTime = t;
            }
        }, 300);
    }

    // Neutralise les overlays qui captaient les clics (clic-through)
    function neutralizeOverlays() {
        var els = document.querySelectorAll(".video-overlay, #videoOverlay");
        for (var i = 0; i < els.length; i++) {
            els[i].style.pointerEvents = "none";
        }
    }

    // ---- Cas 1 : iframes YouTube simples à adopter ----
    function isYouTube(iframe) {
        var src = iframe.getAttribute("src") || "";
        return src.indexOf("youtube.com/embed/") !== -1 ||
               src.indexOf("youtube-nocookie.com/embed/") !== -1;
    }

    var iframes = Array.prototype.slice
        .call(document.querySelectorAll("iframe"))
        .filter(isYouTube);

    var counter = 0;
    iframes.forEach(function (f) {
        if (!f.id) f.id = "famiyt_" + counter++;
        var src = f.getAttribute("src") || "";
        if (src.indexOf("enablejsapi=1") === -1) {
            src += (src.indexOf("?") === -1 ? "?" : "&") + "enablejsapi=1";
        }
        if (src.indexOf("origin=") === -1) {
            src += "&origin=" + encodeURIComponent(location.origin);
        }
        f.setAttribute("src", src);
        f.setAttribute("data-familock", "pending");
    });

    function adoptIframes() {
        iframes.forEach(function (f) {
            if (f.getAttribute("data-familock") !== "pending") return;
            f.setAttribute("data-familock", "done");
            try {
                var p = new window.YT.Player(f.id, { events: {} });
                installAntiSkip(p);
            } catch (e) {}
        });
    }

    // ---- Cas 2 : lecteur global créé par la page (window.player) ----
    function hookGlobalPlayer() {
        var attempts = 0;
        var iv = setInterval(function () {
            attempts++;
            var p = window.player;
            if (p && typeof p.getCurrentTime === "function" && typeof p.addEventListener === "function") {
                clearInterval(iv);
                installAntiSkip(p);
            } else if (attempts > 100) { // ~20 s puis on abandonne
                clearInterval(iv);
            }
        }, 200);
    }

    // ---- Chargement de l'API YouTube (en préservant un callback existant) ----
    function whenYouTubeReady(callback) {
        if (window.YT && window.YT.Player) { callback(); return; }
        var previous = window.onYouTubeIframeAPIReady;
        window.onYouTubeIframeAPIReady = function () {
            if (typeof previous === "function") { try { previous(); } catch (e) {} }
            callback();
        };
        if (!document.querySelector('script[src*="youtube.com/iframe_api"]')) {
            var tag = document.createElement("script");
            tag.src = "https://www.youtube.com/iframe_api";
            document.head.appendChild(tag);
        }
    }

    neutralizeOverlays();
    if (iframes.length) {
        whenYouTubeReady(adoptIframes);
    }
    // Toujours tenter de se brancher sur un éventuel lecteur maison
    hookGlobalPlayer();
})();
