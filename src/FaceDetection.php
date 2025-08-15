<?php

namespace Arhey\FaceDetection;

use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\Interfaces\ImageInterface;

class FaceDetection
{
    /** @var array{x: float, y: float, w: float, h: float}|null */
    public $bounds;

    /** @var bool */
    public $found = false;

    /** @var ImageManager */
    public $driver;

    /** @var ImageInterface */
    private $image;

    /** @var int */
    private $padding_width = 0;

    /** @var int */
    private $padding_height = 0;

    /** @var array<int, mixed> */
    private $detection_data;

    /**
     * Limite superior de expansão automática do lado do quadrado
     * em relação ao tamanho da face (lado = max(w,h) * auto_expand_cap).
     * Ex.: 1.6 ≈ até +60% no lado, se houver espaço nas bordas.
     */
    private float $auto_expand_cap = 1.6;

    public function __construct()
    {
        $this->driver = $this->defaultDriver();

        if (function_exists('config')) {
            $this->padding_width  = (int) (config('facedetection.padding_width', 0));
            $this->padding_height = (int) (config('facedetection.padding_height', 0));
            // opcional: permitir override por config, mas não obrigatório
            $this->auto_expand_cap = (float) (config('facedetection.auto_expand_cap', $this->auto_expand_cap));
        }

        $detection_file = __DIR__ . '/Data/face.dat';
        if (!is_file($detection_file)) {
            throw new \Exception("Couldn't load detection data at {$detection_file}");
        }
        $data = file_get_contents($detection_file);
        if ($data === false) {
            throw new \Exception("Couldn't read detection data");
        }
        $this->detection_data = unserialize($data);
    }

    /**
     * Extrai a face principal e calcula bounds.
     * Aceita caminho de arquivo ou base64 (com/sem cabeçalho data-uri).
     *
     * - Aplica orientação por EXIF (orient()).
     * - Se não encontrar, tenta novamente com rotações 90°, 270° e 180°.
     *
     * @param string $file
     * @return $this
     */
    public function extract($file)
    {
        $base = $this->driver->read($this->normalizeInput($file));

        // Em v3 a auto-orientação costuma ocorrer, mas garantimos explicitamente
        if (method_exists($base, 'orient')) {
            $base = $base->orient();
        }

        // Tenta nas rotações: 0°, 90°, 270°, 180°
        $angles = [0, 90, 270, 180];
        $foundBounds = null;
        $finalImage  = null;

        foreach ($angles as $angle) {
            $img = $angle === 0 ? (clone $base) : (clone $base)->rotate($angle);
            $bounds = $this->detectBoundsFromImage($img);
            if ($bounds && $bounds['w'] > 0) {
                $foundBounds = $bounds;
                $finalImage  = $img;
                break;
            }
        }

        $this->image  = $finalImage ?: $base;
        $this->bounds = $foundBounds;

        if ($this->bounds) {
            $this->found = true;
            // Arredonda como no original
            $this->bounds['x'] = round($this->bounds['x'], 1);
            $this->bounds['y'] = round($this->bounds['y'], 1);
            $this->bounds['w'] = round($this->bounds['w'], 1);
            $this->bounds['h'] = round($this->bounds['h'], 1);
        }

        return $this;
    }

    /**
     * Salva o recorte padrão (sem margem adicional além do padding configurado).
     *
     * @param string $file_name
     * @throws \Exception
     * @return void
     */
    public function save($file_name)
    {
        if (file_exists($file_name)) {
            throw new \Exception("Save File Already Exists ($file_name)");
        }
        if (!$this->found || !$this->bounds) {
            throw new \Exception("No face bounds available to save");
        }

        $to_crop = [
            'x'      => $this->bounds['x'] - ($this->padding_width / 2),
            'y'      => $this->bounds['y'] - ($this->padding_height / 2),
            'width'  => $this->bounds['w'] + $this->padding_width,
            'height' => $this->bounds['w'] + $this->padding_height,
        ];

        $img = (clone $this->image)->crop(
            (int) $to_crop['width'],
            (int) $to_crop['height'],
            (int) $to_crop['x'],
            (int) $to_crop['y']
        );

        $img = $img->encode(new JpegEncoder(quality: 100));
        $img->save($file_name);
    }

    /**
     * Retorna o recorte padrão (como save()), porém em BASE64 (Data URI).
     *
     * @param string $format 'jpg'|'png'|'webp'
     * @param int    $quality
     * @param bool   $dataUri
     * @return string
     * @throws \Exception
     */
    public function toBase64(string $format = 'jpg', int $quality = 90, bool $dataUri = true): string
    {
        if (!$this->found || !$this->bounds) {
            throw new \Exception("No face bounds available to toBase64");
        }

        $to_crop = [
            'x'      => $this->bounds['x'] - ($this->padding_width / 2),
            'y'      => $this->bounds['y'] - ($this->padding_height / 2),
            'width'  => $this->bounds['w'] + $this->padding_width,
            'height' => $this->bounds['w'] + $this->padding_height,
        ];

        $img = (clone $this->image)->crop(
            (int) $to_crop['width'],
            (int) $to_crop['height'],
            (int) $to_crop['x'],
            (int) $to_crop['y']
        );

        [$encoded, $mime] = $this->encodeImage($img, $format, $quality);
        $bin = method_exists($encoded, 'toString') ? $encoded->toString() : (string) $encoded;
        $b64 = base64_encode($bin);

        return $dataUri ? ("{$mime},{$b64}") : $b64;
    }

    /**
     * Retorna o recorte 1:1 com MARGEM AUTOMÁTICA (sem passar fator),
     * em Base64 (Data URI). Garante que o quadrado fica dentro da imagem.
     *
     * @param string   $input   Caminho/base64 (usado se bounds ainda não existirem)
     * @param int|null $resize  Lado final (px). Null mantém.
     * @param string   $format  'jpg'|'png'|'webp'
     * @param int      $quality Qualidade para jpg/webp
     * @param bool     $dataUri Prefixar 'data:image/...;base64,'
     * @return string
     * @throws \Exception
     */
    public function toBase64Auto(
        string $input,
        ?int $resize = 512,
        string $format = 'jpg',
        int $quality = 90,
        bool $dataUri = true
    ): string {
        if (!$this->found || !$this->bounds) {
            $this->extract($input);
            if (!$this->found || !$this->bounds) {
                throw new \Exception("No face bounds available to toBase64Auto");
            }
        }

        $img = $this->cropAutoSquare((clone $this->image));

        if ($resize !== null) {
            $img = $img->scaleDown($resize, $resize);
        }

        [$encoded, $mime] = $this->encodeImage($img, $format, $quality);
        $bin = method_exists($encoded, 'toString') ? $encoded->toString() : (string) $encoded;
        $b64 = base64_encode($bin);

        return $dataUri ? ("{$mime},{$b64}") : $b64;
    }

    /**
     * Salva recorte 1:1 com margem PERCENTUAL (modo antigo, ainda disponível).
     */
    public function saveWithMargin(
        string $input,
        string $file_name,
        float $marginFator = 0.30,
        ?int $resize = 512,
        ?string $forceFormat = 'jpg',
        int $jpgQuality = 90
    ): void {
        if (file_exists($file_name)) {
            throw new \Exception("Save File Already Exists ($file_name)");
        }

        if (!$this->found || !$this->bounds) {
            $this->extract($input);
            if (!$this->found || !$this->bounds) {
                throw new \Exception("No face bounds available to saveWithMargin");
            }
        }

        $img = $this->cropWithMargin((clone $this->image), $marginFator);

        if ($resize !== null) {
            $img = $img->scaleDown($resize, $resize);
        }

        if ($forceFormat) {
            $fmt = strtolower($forceFormat);
            if ($fmt === 'jpg' || $fmt === 'jpeg') {
                $img = $img->encode(new JpegEncoder(quality: $jpgQuality));
            } elseif ($fmt === 'png') {
                $img = $img->encode(new PngEncoder());
            } elseif ($fmt === 'webp') {
                $img = $img->encode(new WebpEncoder(quality: $jpgQuality));
            }
        }

        $img->save($file_name);
    }

    /**
     * Recorte 1:1 com margem percentual (modo antigo).
     */
    private function cropWithMargin(ImageInterface $img, float $marginFator): ImageInterface
    {
        $imgW = $img->width();
        $imgH = $img->height();

        $x = (float) $this->bounds['x'];
        $y = (float) $this->bounds['y'];
        $w = (float) $this->bounds['w'];
        $h = (float) $this->bounds['h'];

        // 1) Expansão por margem
        $expandW = $w * $marginFator;
        $expandH = $h * $marginFator;

        $x1 = $x - $expandW;
        $y1 = $y - $expandH;
        $x2 = $x + $w + $expandW;
        $y2 = $y + $h + $expandH;

        // 2) Quadrado centrado
        $cropW = $x2 - $x1;
        $cropH = $y2 - $y1;
        $side  = max($cropW, $cropH);

        $cx = ($x1 + $x2) / 2.0;
        $cy = ($y1 + $y2) / 2.0;

        $sqX1 = $cx - $side / 2.0;
        $sqY1 = $cy - $side / 2.0;

        // 3) Clamping nas bordas
        if ($sqX1 < 0) $sqX1 = 0;
        if ($sqY1 < 0) $sqY1 = 0;
        if ($sqX1 + $side > $imgW) $sqX1 = max(0, $imgW - $side);
        if ($sqY1 + $side > $imgH) $sqY1 = max(0, $imgH - $side);
        $side = min($side, $imgW, $imgH);

        // 4) Crop
        return $img->crop(
            (int) round($side),
            (int) round($side),
            (int) round($sqX1),
            (int) round($sqY1)
        );
    }

    /**
     * Recorte 1:1 com margem AUTOMÁTICA.
     * Aumenta o lado do quadrado até:
     *  - encostar no limite mais próximo da imagem (sem extrapolar), e
     *  - não ultrapassar auto_expand_cap * max(w,h)
     * Assim, dá o maior “respiro” possível sem cortar topo/queixo/laterais.
     */
    private function cropAutoSquare(ImageInterface $img): ImageInterface
    {
        $imgW = $img->width();
        $imgH = $img->height();

        $x = (float) $this->bounds['x'];
        $y = (float) $this->bounds['y'];
        $w = (float) $this->bounds['w'];
        $h = (float) $this->bounds['h'];

        $faceSide   = max($w, $h);
        $baseHalf   = $faceSide / 2.0;
        $cx         = $x + $w / 2.0;
        $cy         = $y + $h / 2.0;

        // Maior half-side possível mantendo o quadrado centrado e dentro da imagem:
        $halfByEdges = min($cx, $imgW - $cx, $cy, $imgH - $cy);

        // Preferência: expandir até auto_expand_cap * baseHalf, mas sem passar das bordas:
        $halfPreferred = $baseHalf * $this->auto_expand_cap;

        // Half final: não menor que baseHalf (para conter a face), nem maior que os limites
        $half = max($baseHalf, min($halfPreferred, $halfByEdges));

        $side = 2.0 * $half;
        $x1   = $cx - $half;
        $y1   = $cy - $half;

        // (Com half <= halfByEdges, x1/y1 já respeitam bordas, mas arredondamos)
        return $img->crop(
            (int) round($side),
            (int) round($side),
            (int) round($x1),
            (int) round($y1)
        );
    }

    /**
     * Codifica imagem no formato desejado e devolve [encoded, mime-prefix]
     *
     * @param ImageInterface $img
     * @param string $format
     * @param int $quality
     * @return array{0: mixed, 1: string}
     */
    private function encodeImage(ImageInterface $img, string $format, int $quality): array
    {
        $fmt = strtolower($format);
        if ($fmt === 'jpg' || $fmt === 'jpeg') {
            return [$img->encode(new JpegEncoder(quality: $quality)), 'data:image/jpeg;base64'];
        } elseif ($fmt === 'png') {
            return [$img->encode(new PngEncoder()), 'data:image/png;base64'];
        } elseif ($fmt === 'webp') {
            return [$img->encode(new WebpEncoder(quality: $quality)), 'data:image/webp;base64'];
        }
        // default jpg
        return [$img->encode(new JpegEncoder(quality: $quality)), 'data:image/jpeg;base64'];
    }

    /**
     * Detecta bounds a partir de uma imagem (com downscale para acelerar).
     * @param ImageInterface $img
     * @return array{x:float,y:float,w:float,h:float}|null
     */
    private function detectBoundsFromImage(ImageInterface $img): ?array
    {
        $im_width  = $img->width();
        $im_height = $img->height();

        // Reduz para ~320x240 se maior (acelera)
        $ratioW = $im_width  / 320.0;
        $ratioH = $im_height / 240.0;
        $ratio  = max($ratioW, $ratioH);
        $ratio  = ($ratio > 1) ? $ratio : 0;

        if ($ratio > 1) {
            $temp = (clone $img)->resize(
                (int) round($im_width / $ratio),
                (int) round($im_height / $ratio)
            );

            $stats  = $this->get_img_stats($temp);
            $bounds = $this->do_detect_greedy_big_to_small(
                $stats['ii'],
                $stats['ii2'],
                $stats['width'],
                $stats['height']
            );

            if ($bounds) {
                $bounds['h'] = $bounds['w'];
                if ($bounds['w'] > 0) {
                    $bounds['x'] *= $ratio;
                    $bounds['y'] *= $ratio;
                    $bounds['w'] *= $ratio;
                    $bounds['h'] *= $ratio;
                }
            }
            return $bounds;
        }

        $stats  = $this->get_img_stats($img);
        $bounds = $this->do_detect_greedy_big_to_small(
            $stats['ii'],
            $stats['ii2'],
            $stats['width'],
            $stats['height']
        );

        if ($bounds) {
            $bounds['h'] = $bounds['w'];
        }
        return $bounds;
    }

    /**
     * Cria o ImageManager conforme config (facedetection.driver).
     * v3 exige DriverInterface OU 'gd'/'imagick'.
     */
    protected function defaultDriver(): ImageManager
    {
        $driverKey = 'gd';
        if (function_exists('config')) {
            $driverKey = (string) config('facedetection.driver', 'gd');
        }
        $driverKey = strtolower($driverKey);

        return match ($driverKey) {
            'imagick' => new ImageManager(new ImagickDriver()),
            default   => new ImageManager(new GdDriver()),
        };
    }

    /**
     * Integrais e metadados da imagem.
     *
     * @param ImageInterface $image
     * @return array{width:int,height:int,ii:array,ii2:array}
     */
    protected function get_img_stats(ImageInterface $image)
    {
        $image_width  = $image->width();
        $image_height = $image->height();
        $iis = $this->compute_ii($image, $image_width, $image_height);
        return [
            'width'  => $image_width,
            'height' => $image_height,
            'ii'     => $iis['ii'],
            'ii2'    => $iis['ii2'],
        ];
    }

    /**
     * Helper para extrair o valor (int) de um canal de cor (Intervention v3).
     */
    private function channelVal($channel): int
    {
        if (is_object($channel)) {
            if (method_exists($channel, 'value')) return (int) $channel->value();
            if (method_exists($channel, 'toInteger')) return (int) $channel->toInteger();
            if (method_exists($channel, 'toInt')) return (int) $channel->toInt();
            if (method_exists($channel, '__toString')) {
                $v = (string) $channel;
                return (int) (is_numeric($v) ? $v : 0);
            }
            return 0;
        }
        if (is_int($channel) || is_float($channel)) return (int) $channel;
        if (is_string($channel) && is_numeric($channel)) return (int) $channel;
        return 0;
    }

    /**
     * Integrais da imagem (soma e soma dos quadrados).
     * Compatível com pickColor() da v3.
     */
    protected function compute_ii(ImageInterface $image, int $image_width, int $image_height)
    {
        $ii_w = $image_width + 1;
        $ii_h = $image_height + 1;
        $ii   = [];
        $ii2  = [];

        for ($i = 0; $i < $ii_w; $i++) {
            $ii[$i]  = 0;
            $ii2[$i] = 0;
        }

        for ($i = 1; $i < $ii_h - 1; $i++) {
            $ii[$i * $ii_w]  = 0;
            $ii2[$i * $ii_w] = 0;
            $rowsum  = 0;
            $rowsum2 = 0;

            for ($j = 1; $j < $ii_w - 1; $j++) {
                $px = $image->pickColor($j, $i);

                $red = $green = $blue = 0;

                if (is_object($px)) {
                    if (method_exists($px, 'red'))   $red   = $this->channelVal($px->red());
                    if (method_exists($px, 'green')) $green = $this->channelVal($px->green());
                    if (method_exists($px, 'blue'))  $blue  = $this->channelVal($px->blue());

                    if (($red|$green|$blue) === 0 && method_exists($px, 'toArray')) {
                        $arr = $px->toArray();
                        if (is_array($arr) && count($arr) >= 3) {
                            $red   = (int) ($arr[0] ?? 0);
                            $green = (int) ($arr[1] ?? 0);
                            $blue  = (int) ($arr[2] ?? 0);
                        }
                    }
                } elseif (is_array($px) && count($px) >= 3) {
                    $red   = (int) $px[0];
                    $green = (int) $px[1];
                    $blue  = (int) $px[2];
                }

                $grey = (int) floor(0.2989 * $red + 0.587 * $green + 0.114 * $blue);

                $rowsum  += $grey;
                $rowsum2 += $grey * $grey;

                $ii_above = ($i - 1) * $ii_w + $j;
                $ii_this  = $i * $ii_w + $j;

                $ii[$ii_this]  = $ii[$ii_above] + $rowsum;
                $ii2[$ii_this] = $ii2[$ii_above] + $rowsum2;
            }
        }

        return ['ii' => $ii, 'ii2' => $ii2];
    }

    /**
     * Detector greedy (igual ao original).
     */
    protected function do_detect_greedy_big_to_small($ii, $ii2, $width, $height)
    {
        $s_w          = $width / 20.0;
        $s_h          = $height / 20.0;
        $start_scale  = $s_h < $s_w ? $s_h : $s_w;
        $scale_update = 1 / 1.2;

        for ($scale = $start_scale; $scale > 1; $scale *= $scale_update) {
            $w       = (int) floor(20 * $scale);
            $endx    = $width - $w - 1;
            $endy    = $height - $w - 1;
            $step    = (int) floor(max($scale, 2));
            $inv_area = 1 / ($w * $w);

            for ($y = 0; $y < $endy; $y += $step) {
                for ($x = 0; $x < $endx; $x += $step) {
                    $passed = $this->detect_on_sub_image($x, $y, $scale, $ii, $ii2, $w, $width + 1, $inv_area);
                    if ($passed) {
                        return ['x' => (float) $x, 'y' => (float) $y, 'w' => (float) $w];
                    }
                }
            }
        }
        return null;
    }

    /**
     * Etapa de avaliação do classificador.
     */
    protected function detect_on_sub_image($x, $y, $scale, $ii, $ii2, $w, $iiw, $inv_area)
    {
        $mean  = ($ii[($y + $w) * $iiw + $x + $w] + $ii[$y * $iiw + $x] - $ii[($y + $w) * $iiw + $x] - $ii[$y * $iiw + $x + $w]) * $inv_area;
        $vnorm = ($ii2[($y + $w) * $iiw + $x + $w] + $ii2[$y * $iiw + $x] - $ii2[($y + $w) * $iiw + $x] - $ii2[$y * $iiw + $x + $w]) * $inv_area - ($mean * $mean);
        $vnorm = $vnorm > 1 ? sqrt($vnorm) : 1;

        for ($i_stage = 0; $i_stage < count($this->detection_data); $i_stage++) {
            $stage        = $this->detection_data[$i_stage];
            $trees        = $stage[0];
            $stage_thresh = $stage[1];
            $stage_sum    = 0;

            for ($i_tree = 0; $i_tree < count($trees); $i_tree++) {
                $tree         = $trees[$i_tree];
                $current_node = $tree[0];
                $tree_sum     = 0;

                while ($current_node != null) {
                    $vals        = $current_node[0];
                    $node_thresh = $vals[0];
                    $leftval     = $vals[1];
                    $rightval    = $vals[2];
                    $leftidx     = $vals[3];
                    $rightidx    = $vals[4];
                    $rects       = $current_node[1];

                    $rect_sum = 0;
                    for ($i_rect = 0; $i_rect < count($rects); $i_rect++) {
                        $s    = $scale;
                        $rect = $rects[$i_rect];
                        $rx   = (int) floor($rect[0] * $s + $x);
                        $ry   = (int) floor($rect[1] * $s + $y);
                        $rw   = (int) floor($rect[2] * $s);
                        $rh   = (int) floor($rect[3] * $s);
                        $wt   = $rect[4];

                        $r_sum    = ($ii[($ry + $rh) * $iiw + $rx + $rw] + $ii[$ry * $iiw + $rx] - $ii[($ry + $rh) * $iiw + $rx] - $ii[$ry * $iiw + $rx + $rw]) * $wt;
                        $rect_sum += $r_sum;
                    }

                    $rect_sum *= $inv_area;

                    $current_node = null;
                    if ($rect_sum >= $node_thresh * $vnorm) {
                        if ($rightidx == -1) {
                            $tree_sum = $rightval;
                        } else {
                            $current_node = $tree[$rightidx];
                        }
                    } else {
                        if ($leftidx == -1) {
                            $tree_sum = $leftval;
                        } else {
                            $current_node = $tree[$leftidx];
                        }
                    }
                }

                $stage_sum += $tree_sum;
            }

            if ($stage_sum < $stage_thresh) {
                return false;
            }
        }

        return true;
    }

    /**
     * Normaliza o input: caminho, data-uri base64, base64 cru.
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
}
