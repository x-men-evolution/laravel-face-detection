<?php

namespace EvolutionTech\FaceDetection;

use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;

final class ImagePadder
{
    private ImageManager $im;

    public function __construct(?string $driver = null)
    {
        $driver = strtolower($driver ?? (function_exists('config') ? (string) config('facedetection.driver', 'gd') : 'gd'));
        $this->im = $driver === 'imagick'
            ? new ImageManager(new ImagickDriver())
            : new ImageManager(new GdDriver());
    }

    /**
     * Adiciona borda (canvas) mantendo 1:1 e devolve Base64.
     * - $padPct: borda uniforme em % do lado da imagem (ex.: 0.10 = 10%)
     * - $shiftUpPct: desloca a imagem para CIMA dentro do canvas (para sobrar mais embaixo)
     * - $bg: cor do fundo (hex ou nome CSS), ex.: '#FFFFFF'
     * - $format: 'jpg'|'png'|'webp'
     */
    public function padSquareDataUri(
        string $dataUri,
        float $padPct = 0.10,
        float $shiftUpPct = 0.07,
        string $bg = '#FFFFFF',
        string $format = 'jpg',
        int $quality = 95
    ): string {
        $img = $this->im->read($dataUri);

        // garante quadrado (a classe já envia 1:1; se não for, usa o maior lado)
        $side = max($img->width(), $img->height());

        // canvas maior (zoom-out visual)
        $padPx   = (int) round($side * $padPct);
        $newSide = $side + 2 * $padPx;

        /** @var ImageInterface $canvas */
        $canvas = $this->im->create($newSide, $newSide);
        if (method_exists($canvas, 'fill')) {
            $canvas->fill($bg);
        }

        // desloca imagem para cima para sobrar mais borda no queixo
        $shiftUpPx = (int) round($side * $shiftUpPct);
        $offsetX   = $padPx;
        $offsetY   = max(0, min($padPx - $shiftUpPx, $newSide - $side));

        // insere a imagem no canvas (top-left + offsets)
        if (method_exists($canvas, 'place')) {
            $canvas->place($img, 'top-left', $offsetX, $offsetY);
        } elseif (method_exists($canvas, 'insert')) { // fallback v2
            $canvas->insert($img, 'top-left', $offsetX, $offsetY);
        }

        // encode + data-uri
        $mime = 'data:image/jpeg;base64';
        $encoded = match (strtolower($format)) {
            'png'  => ($mime = 'data:image/png;base64')  && $canvas->encode(new \Intervention\Image\Encoders\PngEncoder()),
            'webp' => ($mime = 'data:image/webp;base64') && $canvas->encode(new \Intervention\Image\Encoders\WebpEncoder(quality: $quality)),
            default => $canvas->encode(new \Intervention\Image\Encoders\JpegEncoder(quality: $quality)),
        };

        $bin = method_exists($encoded, 'toString') ? $encoded->toString() : (string) $encoded;
        return $mime . ',' . base64_encode($bin);
    }
}
