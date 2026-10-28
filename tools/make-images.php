<?php
// Draws public/favicon.png (64 x 64) and public/og.png (1200 x 630, the sharing picture) with GD: run once.

function block(GdImage $im, array $rows, int $x, int $y, int $size, int $color): void
{
    foreach ($rows as $j => $row) {
        foreach (str_split($row) as $i => $c) {
            if ($c === '#') {
                imagefilledrectangle($im, $x + $i * $size, $y + $j * $size, $x + ($i + 1) * $size - 1, $y + ($j + 1) * $size - 1, $color);
            }
        }
    }
}

$j = ['..####', '....#.', '....#.', '....#.', '#...#.', '#...#.', '.###..'];

$icon = imagecreatetruecolor(64, 64);
imagefill($icon, 0, 0, imagecolorallocate($icon, 11, 11, 24));
imagefilledrectangle($icon, 4, 4, 59, 59, imagecolorallocate($icon, 255, 79, 163));
imagefilledrectangle($icon, 8, 8, 55, 55, imagecolorallocate($icon, 11, 11, 24));
block($icon, $j, 14, 11, 6, imagecolorallocate($icon, 255, 214, 10));
imagepng($icon, __DIR__ . '/../public/favicon.png', 9);

$og = imagecreatetruecolor(1200, 630);
imagefill($og, 0, 0, imagecolorallocate($og, 11, 11, 24));
$bands = [[255, 79, 163], [255, 214, 10], [61, 220, 132], [52, 178, 255], [157, 92, 255]];
foreach ($bands as $i => [$r, $g, $b]) {
    imagefilledrectangle($og, 0, 560 + $i * 14, 1199, 573 + $i * 14, imagecolorallocate($og, $r, $g, $b));
}
srand(7);
for ($i = 0; $i < 90; $i++) {
    $c = imagecolorallocate($og, 120 + rand(0, 135), 120 + rand(0, 135), 200);
    $x = rand(0, 1199); $y = rand(0, 540);
    imagefilledrectangle($og, $x, $y, $x + 3, $y + 3, $c);
}
block($og, $j, 90, 150, 30, imagecolorallocate($og, 255, 214, 10));
$text = imagecreatetruecolor(160, 40);
imagefill($text, 0, 0, imagecolorallocate($text, 11, 11, 24));
imagecolortransparent($text, imagecolorallocate($text, 11, 11, 24));
imagestring($text, 5, 0, 0, 'JumPHP', imagecolorallocate($text, 255, 255, 255));
imagestring($text, 5, 0, 20, 'PLAYGROUND', imagecolorallocate($text, 255, 79, 163));
imagecopyresized($og, $text, 330, 150, 0, 0, 800, 200, 160, 40);
$sub = imagecreatetruecolor(400, 16);
imagefill($sub, 0, 0, imagecolorallocate($sub, 11, 11, 24));
imagecolortransparent($sub, imagecolorallocate($sub, 11, 11, 24));
imagestring($sub, 3, 0, 0, 'Retro games in PHP, played in the browser', imagecolorallocate($sub, 200, 200, 230));
imagecopyresized($og, $sub, 334, 400, 0, 0, 800, 42, 287, 15);
imagepng($og, __DIR__ . '/../public/og.png', 9);
