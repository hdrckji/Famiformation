/*
 * pdf-viewer.js — Affichage des PDF directement dans la page sur mobile.
 *
 * Sur ordinateur, les <iframe> PDF s'affichent nativement : on n'y touche pas.
 * Sur mobile/tablette, le navigateur refuse souvent d'afficher un PDF en iframe
 * et propose "télécharger / ouvrir". On remplace alors l'iframe par un rendu
 * PDF.js (canvas), scrollable, intégré à la plateforme.
 */
(function () {
    "use strict";

    // 1. Détection mobile / tactile (laisse l'ordinateur tranquille)
    var ua = navigator.userAgent || "";
    var isMobile =
        window.matchMedia("(max-width: 820px)").matches ||
        /Android|iPhone|iPod/i.test(ua) ||
        /iPad/.test(ua) ||
        (navigator.maxTouchPoints > 1 && /Macintosh/.test(ua));
    if (!isMobile) return;

    // 2. Repère les iframes pointant vers un PDF
    function findPdfFrames() {
        return Array.prototype.slice
            .call(document.querySelectorAll("iframe, embed, object"))
            .filter(function (el) {
                var src = el.getAttribute("src") || el.getAttribute("data") || "";
                return src.split("#")[0].split("?")[0].toLowerCase().indexOf(".pdf") !== -1;
            });
    }

    var frames = findPdfFrames();
    if (!frames.length) return;

    var PDFJS_VERSION = "3.11.174";
    var CDN = "https://cdnjs.cloudflare.com/ajax/libs/pdf.js/" + PDFJS_VERSION + "/";

    function loadScript(src) {
        return new Promise(function (resolve, reject) {
            var s = document.createElement("script");
            s.src = src;
            s.onload = resolve;
            s.onerror = function () { reject(new Error("load " + src)); };
            document.head.appendChild(s);
        });
    }

    loadScript(CDN + "pdf.min.js")
        .then(function () {
            window.pdfjsLib.GlobalWorkerOptions.workerSrc = CDN + "pdf.worker.min.js";
            frames.forEach(renderFrame);
        })
        .catch(function () {
            /* CDN indisponible : on laisse l'iframe d'origine en place */
        });

    function renderFrame(frame) {
        // URL absolue du PDF (résout les chemins relatifs), sans fragment #...
        var url = (frame.src || frame.getAttribute("src") || frame.getAttribute("data") || "").split("#")[0];

        var box = document.createElement("div");
        box.style.cssText =
            "width:100%;max-width:900px;margin:16px auto;height:85vh;overflow:auto;" +
            "-webkit-overflow-scrolling:touch;background:#fff;border-radius:14px;" +
            "box-shadow:0 8px 32px rgba(0,0,0,0.12);box-sizing:border-box;padding:8px;";
        frame.parentNode.replaceChild(box, frame);

        var loading = document.createElement("div");
        loading.textContent = "Chargement du PDF…";
        loading.style.cssText = "padding:24px;text-align:center;color:#2d5a37;font-weight:700;";
        box.appendChild(loading);

        window.pdfjsLib
            .getDocument(url)
            .promise.then(function (pdf) {
                box.removeChild(loading);
                var dpr = Math.min(window.devicePixelRatio || 1, 2);
                var chain = Promise.resolve();
                for (var i = 1; i <= pdf.numPages; i++) {
                    chain = chain.then(makeRenderPage(pdf, i, box, dpr));
                }
                return chain;
            })
            .catch(function () {
                box.innerHTML =
                    '<div style="padding:20px;text-align:center;">' +
                    "Le PDF n'a pas pu s'afficher ici. " +
                    '<a href="' + url + '" style="color:#2d5a37;font-weight:700;">Ouvrir le PDF</a>' +
                    "</div>";
            });
    }

    function makeRenderPage(pdf, pageNumber, box, dpr) {
        return function () {
            return pdf.getPage(pageNumber).then(function (page) {
                var avail = (box.clientWidth || 320) - 16; // moins le padding
                var base = page.getViewport({ scale: 1 });
                var scale = avail / base.width;
                var viewport = page.getViewport({ scale: scale * dpr });

                var canvas = document.createElement("canvas");
                canvas.width = Math.floor(viewport.width);
                canvas.height = Math.floor(viewport.height);
                canvas.style.display = "block";
                canvas.style.width = "100%";
                canvas.style.height = "auto";
                canvas.style.margin = "0 auto 10px";
                box.appendChild(canvas);

                return page.render({
                    canvasContext: canvas.getContext("2d"),
                    viewport: viewport
                }).promise;
            });
        };
    }
})();
