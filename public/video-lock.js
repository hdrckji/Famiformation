/*
 * video-lock.js — Empêche d'avancer sur la timeline des vidéos YouTube.
 *
 * Les contrôles (lecture, pause, volume, retour en arrière) restent
 * disponibles, mais tout saut VERS L'AVANT au-delà de la partie déjà
 * regardée est annulé : la vidéo revient au point le plus loin atteint.
 *
 * Fonctionne en "adoptant" les <iframe> YouTube déjà présentes (sans
 * changer la mise en page) via l'API IFrame de YouTube.
 */
(function () {
    "use strict";

    function isYouTube(iframe) {
        var src = iframe.getAttribute("src") || "";
        return src.indexOf("youtube.com/embed/") !== -1 ||
               src.indexOf("youtube-nocookie.com/embed/") !== -1;
    }

    var iframes = Array.prototype.slice
        .call(document.querySelectorAll("iframe"))
        .filter(isYouTube);
    if (!iframes.length) return;

    // 1. S'assurer que chaque iframe a un id + enablejsapi (requis par l'API)
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

    // 2. Charger l'API YouTube si besoin, en préservant un éventuel callback
    function whenYouTubeReady(callback) {
        if (window.YT && window.YT.Player) {
            callback();
            return;
        }
        var previous = window.onYouTubeIframeAPIReady;
        window.onYouTubeIframeAPIReady = function () {
            if (typeof previous === "function") {
                try { previous(); } catch (e) {}
            }
            callback();
        };
        if (!document.querySelector('script[src*="youtube.com/iframe_api"]')) {
            var tag = document.createElement("script");
            tag.src = "https://www.youtube.com/iframe_api";
            (document.getElementsByTagName("script")[0] || document.head)
                .parentNode.insertBefore(tag, document.getElementsByTagName("script")[0] || null);
        }
    }

    // 3. Anti-avance pour un lecteur donné
    function makeAntiSkip() {
        var maxTime = 0;          // point le plus loin réellement regardé
        var lastWall = nowMs();   // horloge réelle, pour tolérer la mise en veille des onglets
        var player = null;
        var timer = null;

        function nowMs() {
            return new Date().getTime();
        }

        function tick() {
            if (!player || typeof player.getCurrentTime !== "function") return;
            var t = player.getCurrentTime() || 0;
            var now = nowMs();
            var elapsed = (now - lastWall) / 1000;
            lastWall = now;
            var rate = (typeof player.getPlaybackRate === "function" ? player.getPlaybackRate() : 1) || 1;
            // avance "normale" autorisée = temps réel écoulé × vitesse + marge
            var allowed = elapsed * rate + 0.75;
            if (t > maxTime + allowed) {
                try { player.seekTo(maxTime, true); } catch (e) {}
            } else if (t > maxTime) {
                maxTime = t;
            }
        }

        return {
            onReady: function (e) {
                player = e.target;
            },
            onStateChange: function (e) {
                if (!player) player = e.target;
                if (e.data === window.YT.PlayerState.PLAYING) {
                    lastWall = nowMs();
                    if (!timer) timer = setInterval(tick, 300);
                } else if (e.data === window.YT.PlayerState.ENDED) {
                    if (timer) { clearInterval(timer); timer = null; }
                    maxTime = (typeof player.getDuration === "function") ? player.getDuration() : maxTime;
                }
            }
        };
    }

    function init() {
        iframes.forEach(function (f) {
            if (f.getAttribute("data-familock") !== "pending") return;
            f.setAttribute("data-familock", "done");
            try {
                var lock = makeAntiSkip();
                new window.YT.Player(f.id, {
                    events: {
                        onReady: lock.onReady,
                        onStateChange: lock.onStateChange
                    }
                });
            } catch (e) {}
        });
    }

    whenYouTubeReady(init);
})();
