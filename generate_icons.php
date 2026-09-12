<?php
$sizes = [72, 96, 128, 144, 152, 192, 384, 512];
$publicDir = 'C:/Users/timot/OneDrive/Desktop/RunIt/runit-frontend/public/';

foreach ($sizes as $size) {
    $img = imagecreatetruecolor($size, $size);
    $bg  = imagecolorallocate($img, 0, 201, 167);
    $fg  = imagecolorallocate($img, 10, 31,  28);

    // Rounded background
    imagefill($img, 0, 0, $bg);

    // Draw R text centered
    $fontSize  = (int)($size * 0.45);
    $fontFile  = 'C:/Windows/Fonts/arialbd.ttf';
    $text      = 'R';

    if (file_exists($fontFile)) {
        $bbox = imagettfbbox($fontSize, 0, $fontFile, $text);
        $x    = ($size - ($bbox[2] - $bbox[0])) / 2;
        $y    = ($size - ($bbox[7] - $bbox[1])) / 2 - $bbox[7];
        imagettftext($img, $fontSize, 0, (int)$x, (int)$y, $fg, $fontFile, $text);
    }

    $file = $publicDir . 'icon-' . $size . '.png';
    imagepng($img, $file);
    imagedestroy($img);
    echo 'Created ' . $file . '<br>';
}
echo 'Done!';