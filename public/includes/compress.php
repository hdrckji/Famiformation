<?php
// ============================================================
// compress.php — COMPRESSION de tout fichier déposé sur le volume.
//
//   Tout ce qui arrive dans le stockage est allégé AVANT d'y être écrit :
//     • IMAGES (icônes, photos de profil, habillage, images extraites des PDF) :
//       redimensionnées (borne raisonnable) et ré-encodées (JPEG/WebP qualité ~82,
//       PNG optimisé). Une photo de téléphone de 6 Mo tombe souvent sous 300 Ko.
//     • VIDÉOS courtes (intro / outro) : ré-encodées en H.264 720p + « faststart »
//       (démarrage instantané), comme les vidéos de formation.
//
//   Chaque compression est un BONUS : si l'outil manque (GD, ffmpeg) ou échoue, on
//   garde le fichier d'origine — jamais on ne casse un upload pour l'alléger.
// ============================================================

if (!function_exists('famiCompressImageFile')) {
    /**
     * Compresse une image SUR PLACE. Réduit si un côté dépasse $maxDim, ré-encode.
     * @return bool true si le fichier a été réécrit (plus léger), false sinon (intact).
     */
    function famiCompressImageFile($absPath, $maxDim = 1600, $quality = 82)
    {
        if (!is_file($absPath) || !function_exists('imagecreatetruecolor')) { return false; }

        $info = @getimagesize($absPath);
        if ($info === false) { return false; }
        list($w, $h) = $info;
        $type = $info[2];
        if ($w < 1 || $h < 1) { return false; }

        switch ($type) {
            case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($absPath); break;
            case IMAGETYPE_PNG:  $src = @imagecreatefrompng($absPath);  break;
            case IMAGETYPE_WEBP: $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($absPath) : false; break;
            case IMAGETYPE_GIF:  $src = @imagecreatefromgif($absPath);  break;
            default: return false; // SVG et autres : rien à faire (déjà légers ou vectoriels)
        }
        if (!$src) { return false; }

        // Redimensionnement proportionnel si nécessaire.
        $scale = min(1.0, $maxDim / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $dst = imagecreatetruecolor($nw, $nh);
        if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_GIF || $type === IMAGETYPE_WEBP) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefilledrectangle($dst, 0, 0, $nw, $nh, $transparent);
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);

        $tmp = $absPath . '.z.tmp';
        $ok = false;
        switch ($type) {
            case IMAGETYPE_JPEG: $ok = imagejpeg($dst, $tmp, $quality); break;
            case IMAGETYPE_WEBP: $ok = function_exists('imagewebp') ? imagewebp($dst, $tmp, $quality) : false; break;
            case IMAGETYPE_PNG:  $ok = imagepng($dst, $tmp, 8); break;       // 8 = bonne compression sans perte
            case IMAGETYPE_GIF:  $ok = imagegif($dst, $tmp); break;
        }
        imagedestroy($dst);

        if (!$ok || !is_file($tmp) || filesize($tmp) <= 0) { @unlink($tmp); return false; }

        // On ne garde le nouveau fichier QUE s'il est réellement plus léger (le
        // redimensionnement est déjà un gain ; ce garde-fou couvre les petites images).
        if (filesize($tmp) < filesize($absPath) || ($nw < $w)) {
            @rename($tmp, $absPath);
            return true;
        }
        @unlink($tmp);
        return false;
    }
}

if (!function_exists('famiCompressVideoFile')) {
    /**
     * Compresse une COURTE vidéo (intro/outro) SUR PLACE : H.264 720p + faststart.
     * Synchrone (l'admin dépose un clip de quelques secondes, pas une formation).
     * @return bool true si réécrit.
     */
    function famiCompressVideoFile($absPath)
    {
        if (!is_file($absPath) || !function_exists('exec')) { return false; }
        $tmp = $absPath . '.z.mp4';
        $cmd = 'ffmpeg -y -i ' . escapeshellarg($absPath)
            . ' -vf ' . escapeshellarg('scale=-2:min(720\,ih)')      // au plus 720p, largeur paire
            . ' -c:v libx264 -preset veryfast -crf 24 -c:a aac -b:a 128k -movflags +faststart '
            . escapeshellarg($tmp) . ' 2>&1';
        @set_time_limit(0);
        $o = []; $code = 0;
        @exec($cmd, $o, $code);
        if ($code !== 0 || !is_file($tmp) || filesize($tmp) <= 0) { @unlink($tmp); return false; }
        @rename($tmp, $absPath);
        return true;
    }
}
