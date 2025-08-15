<?php

namespace EvolutionTech\FaceDetection;

use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;

class ImageResizer
{
    /** @var ImageManager */
    private ImageManager $manager;

    public function __construct(?ImageManager $manager = null)
    {
        // usa o mesmo padrão de driver do pacote (config('facedetection.driver', 'gd'))
        $this->manager = $manager ?: $this->defaultDriver();
    }

    /**
     * Redimensiona uma imagem recebida em Base64 (com ou sem data-uri) e
     * retorna o resultado em Base64 (opcionalmente com prefixo data-uri).
     *
     * @param string      $inputBase64 Base64 cru OU data-uri (data:image/...;base64,...)
     * @param int         $width       Largura alvo
     * @param int|null    $height      Altura alvo (se null, usa o mesmo valor de $width)
     * @param string      $mode        'cover' (preenche e corta centro) | 'max' (apenas reduz p/ caber)
     * @param bool        $upscale     Permite ampliar se a imagem for menor que o alvo (aplica ao modo 'cover')
     * @param string      $format      'jpg' | 'png' | 'webp'
     * @param int         $quality     Qualidade do encoder (jpg/webp)
     * @param bool        $dataUri     Se true, retorna com prefixo 'data:image/...;base64,'
     * @return string
     */
    public function resizeBase64(
        string $inputBase64,
        int $width,
        ?int $height = null,
        string $mode = 'cover',
        bool $upscale = true,
        string $format = 'jpg',
        int $quality = 90,
        bool $dataUri = true
    ): string {
        $height ??= $width;

        $img = $this->manager->read($this->normalizeInput($inputBase64));
        if (method_exists($img, 'orient')) {
            $img = $img->orient();
        }

        $srcW = $img->width();
        $srcH = $img->height();

        // Evita fazer nada se já estiver no tamanho (e upscaling desabilitado)
        if ($mode === 'max') {
            // escala para caber dentro da caixa (nunca aumenta)
            $scale = min($width / $srcW, $height / $srcH);
            $scale = min($scale, 1.0); // sem upscaling
            $newW  = (int) max(1, round($srcW * $scale));
            $newH  = (int) max(1, round($srcH * $scale));

            $img = $img->resize($newW, $newH);
            // OBS: 'max' não força bordas exatas width x height
        } else {
            // 'cover' – preenche totalmente a área e corta excedentes (centralizado)
            // permite upscaling se $upscale = true
            $scale = max($width / $srcW, $height / $srcH);
            if (!$upscale) {
                $scale = min($scale, 1.0);
            }

            $newW = (int) max(1, round($srcW * $scale));
            $newH = (int) max(1, round($srcH * $scale));

            $img  = $img->resize($newW, $newH);

            $offX = (int) max(0, floor(($newW - $width) / 2));
            $offY = (int) max(0, floor(($newH - $height) / 2));

            // garante saída exatamente no tamanho desejado
            $img  = $img->crop($width, $height, $offX, $offY);
        }

        // Encode -> Base64
        [$encoded, $mime] = $this->encode($img, $format, $quality);
        $bin = method_exists($encoded, 'toString') ? $encoded->toString() : (string) $encoded;
        $b64 = base64_encode($bin);

        return $dataUri ? ("{$mime},{$b64}") : $b64;
    }

    /* ========================= Helpers ========================= */

    private function defaultDriver(): ImageManager
    {
        $driverKey = function_exists('config') ? (string) config('facedetection.driver', 'gd') : 'gd';
        return match (strtolower($driverKey)) {
            'imagick' => new ImageManager(new ImagickDriver()),
            default   => new ImageManager(new GdDriver()),
        };
    }

    /**
     * Aceita:
     * - data-uri ('data:image/...;base64,...')
     * - base64 cru
     * - caminho de arquivo (se por acaso vier assim)
     */
    private function normalizeInput(string $input): string
    {
        if (str_starts_with($input, 'data:image/')) {
            return $input;
        }

        if (is_file($input)) {
            return $input;
        }

        $maybeBase64 = preg_replace('/\s+/', '', $input ?? '');
        if ($maybeBase64 !== null && preg_match('/^[A-Za-z0-9+\/=]+$/', $maybeBase64)) {
            return 'data:image/jpeg;base64,' . $maybeBase64;
        }

        return $input;
    }

    /**
     * @return array{0:mixed,1:string} [encoded, mimePrefix]
     */
    private function encode(ImageInterface $img, string $format, int $quality): array
    {
        $fmt = strtolower($format);
        return match ($fmt) {
            'png'  => [$img->encode(new PngEncoder()), 'data:image/png;base64'],
            'webp' => [$img->encode(new WebpEncoder(quality: $quality)), 'data:image/webp;base64'],
            default => [$img->encode(new JpegEncoder(quality: $quality)), 'data:image/jpeg;base64'],
        };
    }
}
