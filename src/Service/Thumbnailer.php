<?php

namespace App\Service;

/**
 * The picture of a game in the directory (640 × 360 PNG), redrawn with GD from the game's own pictures:
 * the site's sharing picture, assets/thumbnail.png, or the icon. Pixel art stays sharp (whole factors).
 */
final class Thumbnailer
{
    public const WIDTH = 640;
    public const HEIGHT = 360;

    public function make(string $game, ?string $siteImage, string $out): bool
    {
        $candidates = array_filter([
            $siteImage ? "$game/site/$siteImage" : null,
            "$game/assets/thumbnail.png",
            "$game/site/images/og.png",
            "$game/assets/icon.png",
        ]);
        foreach ($candidates as $file) {
            if (is_file($file) && filesize($file) < 10 * 1024 * 1024 && ($image = $this->load($file))) {
                imagepng($this->fit($image), $out, 9);
                return true;
            }
        }
        return false;
    }

    private function load(string $file): ?\GdImage
    {
        $info = @getimagesize($file);
        if (!$info || $info[0] * $info[1] > 25_000_000) {
            return null;
        }
        $image = match ($info[2]) {
            IMAGETYPE_PNG => @imagecreatefrompng($file),
            IMAGETYPE_JPEG => @imagecreatefromjpeg($file),
            IMAGETYPE_GIF => @imagecreatefromgif($file),
            IMAGETYPE_WEBP => @imagecreatefromwebp($file),
            default => false,
        };
        return $image ?: null;
    }

    private function fit(\GdImage $source): \GdImage
    {
        $w = imagesx($source);
        $h = imagesy($source);
        $out = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagefill($out, 0, 0, imagecolorallocate($out, 11, 11, 24));
        if ($w <= self::WIDTH / 2 && $h <= self::HEIGHT / 2) {
            // small pixel art (a 320 x 180 screen, an icon): the biggest whole factor, centred, sharp
            $scale = max(1, (int)floor(min(self::WIDTH / $w, self::HEIGHT / $h)));
            imagecopyresized($out, $source, intdiv(self::WIDTH - $w * $scale, 2), intdiv(self::HEIGHT - $h * $scale, 2),
                0, 0, $w * $scale, $h * $scale, $w, $h);
            return $out;
        }
        // a picture: covers the frame (cropped in the middle)
        $scale = max(self::WIDTH / $w, self::HEIGHT / $h);
        $sw = (int)round(self::WIDTH / $scale);
        $sh = (int)round(self::HEIGHT / $scale);
        imagecopyresampled($out, $source, 0, 0, intdiv($w - $sw, 2), intdiv($h - $sh, 2), self::WIDTH, self::HEIGHT, $sw, $sh);
        return $out;
    }
}
