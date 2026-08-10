<?php

namespace App\Support;

/**
 * Generator captcha SVG sederhana, tanpa dependency eksternal (tidak butuh
 * ekstensi GD). Setara secara fungsional dengan `svg-captcha` pada versi
 * Node.js: menghasilkan teks acak + SVG untuk ditampilkan di halaman login.
 */
class CaptchaGenerator
{
    // Karakter yang gampang tertukar (0/O, 1/l/I) sengaja dibuang, sama seperti
    // opsi `ignoreChars: '0oO1lI'` pada versi Node.js.
    private const CHARSET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz';

    private const COLORS = ['#166534', '#15803d', '#166534', '#0f766e', '#1e40af', '#7c2d12'];

    public static function generate(int $length = 6, int $width = 160, int $height = 60): array
    {
        $text = self::randomText($length);

        return [
            'text' => $text,
            'svg' => self::renderSvg($text, $width, $height),
        ];
    }

    private static function randomText(int $length): string
    {
        $chars = str_split(self::CHARSET);
        $text = '';
        for ($i = 0; $i < $length; $i++) {
            $text .= $chars[random_int(0, count($chars) - 1)];
        }

        return $text;
    }

    private static function renderSvg(string $text, int $width, int $height): string
    {
        $letters = str_split($text);
        $count = count($letters);
        $slot = $width / max($count, 1);

        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%2$d" viewBox="0 0 %1$d %2$d" role="img" aria-label="captcha">',
            $width,
            $height
        );
        $svg .= sprintf('<rect width="100%%" height="100%%" fill="#f0fdf4" rx="6"/>');

        // Garis-garis noise, setara opsi `noise: 2` pada versi Node.js.
        for ($i = 0; $i < 3; $i++) {
            $x1 = random_int(0, $width);
            $y1 = random_int(0, $height);
            $x2 = random_int(0, $width);
            $y2 = random_int(0, $height);
            $color = self::COLORS[array_rand(self::COLORS)];
            $svg .= sprintf(
                '<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="%s" stroke-width="1" opacity="0.35"/>',
                $x1,
                $y1,
                $x2,
                $y2,
                $color
            );
        }

        foreach ($letters as $index => $letter) {
            $x = ($index + 0.5) * $slot;
            $y = $height / 2 + random_int(-6, 6);
            $rotate = random_int(-25, 25);
            $color = self::COLORS[array_rand(self::COLORS)];
            $fontSize = random_int($height / 2 - 4, $height / 2 + 4);

            $svg .= sprintf(
                '<text x="%d" y="%d" font-family="Verdana, sans-serif" font-size="%d" font-weight="700" fill="%s" text-anchor="middle" transform="rotate(%d %d %d)">%s</text>',
                (int) $x,
                (int) $y,
                $fontSize,
                $color,
                $rotate,
                (int) $x,
                (int) $y,
                htmlspecialchars($letter, ENT_XML1)
            );
        }

        $svg .= '</svg>';

        return $svg;
    }
}
