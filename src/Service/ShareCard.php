<?php

namespace App\Service;

use App\Entity\Project;

/**
 * The picture of a game when it is shared (og:image, 1200 x 630, what Facebook, X, Discord... show): its
 * picture enlarged with sharp pixels, its title and category at the bottom, the site's stripe. Made once per
 * published version, kept next to it.
 */
final class ShareCard
{
    public const WIDTH = 1200;
    public const HEIGHT = 630;
    private const FONT = __DIR__ . '/../../resources/fonts/DejaVuSansMono-Bold.ttf';
    private const STRIPE = ['#ff3d7f', '#ff9f1c', '#ffe14d', '#3ddc84', '#3dbbff', '#9b5cff'];

    public function __construct(private GameFiles $files)
    {
    }

    /** the file, made if needed; null without a picture of the game */
    public function file(Project $project, string $category, string $lang): ?string
    {
        $thumb = $this->files->thumbnail($project);
        if (!$thumb) {
            return null;
        }
        $file = dirname($thumb) . "/share-$lang.png";
        if (!is_file($file) || filemtime($file) < filemtime($thumb)) {
            $this->make($thumb, $project->getTitle(), $category, $file);
        }
        return is_file($file) ? $file : null;
    }

    private function make(string $thumb, string $title, string $category, string $file): void
    {
        $source = @imagecreatefrompng($thumb);
        if (!$source) {
            return;
        }
        $card = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagefill($card, 0, 0, $this->color($card, '#0b0e1f'));

        // the game's picture, covering the card, enlarged without blur (pixel art)
        [$w, $h] = [imagesx($source), imagesy($source)];
        $scale = max(self::WIDTH / $w, self::HEIGHT / $h);
        [$dw, $dh] = [(int)ceil($w * $scale), (int)ceil($h * $scale)];
        imagecopyresized($card, $source, (int)((self::WIDTH - $dw) / 2), (int)((self::HEIGHT - $dh) / 2), 0, 0, $dw, $dh, $w, $h);

        // a dark band at the bottom for the text, fading in
        for ($y = 0; $y < 220; $y++) {
            $alpha = (int)(127 - min(1, $y / 120) * 120);     // 127 = transparent
            imageline($card, 0, self::HEIGHT - 220 + $y, self::WIDTH, self::HEIGHT - 220 + $y, imagecolorallocatealpha($card, 11, 14, 31, $alpha));
        }

        $size = 64;
        while ($size > 30 && $this->width($size, $title) > self::WIDTH - 120) {
            $size -= 4;
        }
        imagettftext($card, 22, 0, 60, self::HEIGHT - 132, $this->color($card, '#ffe14d'), self::FONT, mb_strtoupper($category));
        imagettftext($card, $size, 0, 60, self::HEIGHT - 52, $this->color($card, '#ffffff'), self::FONT, $title);
        $site = 'jumphp.net';
        imagettftext($card, 22, 0, self::WIDTH - 60 - $this->width(22, $site), self::HEIGHT - 132, $this->color($card, '#9aa3c7'), self::FONT, $site);

        $band = self::WIDTH / count(self::STRIPE);
        foreach (self::STRIPE as $i => $hex) {
            imagefilledrectangle($card, (int)($i * $band), self::HEIGHT - 10, (int)(($i + 1) * $band), self::HEIGHT, $this->color($card, $hex));
        }
        imagepng($card, "$file.tmp", 9);
        rename("$file.tmp", $file);
    }

    private function width(int $size, string $text): int
    {
        $box = imagettfbbox($size, 0, self::FONT, $text) ?: [0, 0, 0, 0, 0];
        return $box[2] - $box[0];
    }

    private function color(\GdImage $image, string $hex): int
    {
        return (int)imagecolorallocate($image, ...array_map('hexdec', str_split(ltrim($hex, '#'), 2)));
    }
}
