<?php

namespace EvolutionTech\FaceDetection;

use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
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
     * Expansão automática (lado do quadrado) relativa à face: lado <= max(w,h) * cap
     * e parâmetros de “headroom” para não cortar topo/queixo.
     */
    private float $auto_expand_cap       = 1.6; // até +60% no lado, se couber
    private float $auto_top_margin_factor= 0.35; // +35% do lado da face acima do topo da face
    private float $auto_vertical_bias    = 0.15; // desloca centro para cima em 15% do lado da face

    public function __construct()
    {
        $this->driver = $this->defaultDriver();

        if (function_exists('config')) {
            $this->padding_width   = (int) (config('facedetection.padding_width', 0));
            $this->padding_height  = (int) (config('facedetection.padding_height', 0));
            $this->auto_expand_cap = (float) (config('facedetection.auto_expand_cap', $this->auto_expand_cap));
            $this->auto_top_margin_factor = (float) (config('facedetection.auto_top_margin_factor', $this->auto_top_margin_factor));
            $this->auto_vertical_bias     = (float) (config('facedetection.auto_vertical_bias', $this->auto_vertical_bias));
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
     */
    public function extract($file)
    {
        $base = $this->driver->read($this->normalizeInput($file));

        if (method_exists($base, 'orient')) {
            $base = $base->orient();
        }

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
            $this->bounds['x'] = round($this->bounds['x'], 1);
            $this->bounds['y'] = round($this->bounds['y'], 1);
            $this->bounds['w'] = round($this->bounds['w'], 1);
            $this->bounds['h'] = round($this->bounds['h'], 1);
        }

        return $this;
    }

    /** Salva recorte padrão (padding configurado). */
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

    /** Retorna recorte padrão em Base64 (Data-URI opcional). */
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

    /** Base64 1:1 com margem automática (sem informar fator). */
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

    /** Salva 1:1 com margem percentual (modo antigo, ainda disponível). */
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

    /** Recorte 1:1 com margem percentual (modo antigo). */
    private function cropWithMargin(ImageInterface $img, float $marginFator): ImageInterface
    {
        $imgW = $img->width();
        $imgH = $img->height();

        $x = (float) $this->bounds['x'];
        $y = (float) $this->bounds['y'];
        $w = (float) $this->bounds['w'];
        $h = (float) $this->bounds['h'];

        $expandW = $w * $marginFator;
        $expandH = $h * $marginFator;

        $x1 = $x - $expandW;
        $y1 = $y - $expandH;
        $x2 = $x + $w + $expandW;
        $y2 = $y + $h + $expandH;

        $cropW = $x2 - $x1;
        $cropH = $y2 - $y1;
        $side  = max($cropW, $cropH);

        $cx = ($x1 + $x2) / 2.0;
        $cy = ($y1 + $y2) / 2.0;

        $sqX1 = $cx - $side / 2.0;
        $sqY1 = $cy - $side / 2.0;

        if ($sqX1 < 0) $sqX1 = 0;
        if ($sqY1 < 0) $sqY1 = 0;
        if ($sqX1 + $side > $imgW) $sqX1 = max(0, $imgW - $side);
        if ($sqY1 + $side > $imgH) $sqY1 = max(0, $imgH - $side);
        $side = min($side, $imgW, $imgH);

        return $img->crop(
            (int) round($side),
            (int) round($side),
            (int) round($sqX1),
            (int) round($sqY1)
        );
    }

    /**
     * Recorte 1:1 com MARGEM AUTOMÁTICA + viés para o topo.
     * - aumenta o lado do quadrado até encostar nas bordas (sem ultrapassar),
     *   limitado por auto_expand_cap;
     * - exige um headroom mínimo acima do topo da face (auto_top_margin_factor);
     * - desloca o centro verticalmente para cima (auto_vertical_bias) para
     *   priorizar cabelo/enfeites sem cortar queixo.
     */
    private function cropAutoSquare(ImageInterface $img): ImageInterface
    {
        $imgW = $img->width();
        $imgH = $img->height();

        $x = (float) $this->bounds['x'];
        $y = (float) $this->bounds['y'];
        $w = (float) $this->bounds['w'];
        $h = (float) $this->bounds['h'];

        $faceSide = max($w, $h);
        $baseHalf = $faceSide / 2.0;
        $cx       = $x + $w / 2.0;
        $cy       = $y + $h / 2.0;

        $topGoal  = $this->auto_top_margin_factor * $faceSide;   // headroom desejado
        $capHalf  = $baseHalf * $this->auto_expand_cap;

        // maior half respeitando bordas atuais do centro:
        $halfByEdges = min($cx, $imgW - $cx, $cy, $imgH - $cy);

        // half que atende headroom (se couber), sem ultrapassar cap/bordas
        $half = max($baseHalf + $topGoal, $baseHalf);
        $half = min($half, $capHalf, $halfByEdges);

        // deslocamento vertical para cima (viés)
        $biasPix = $this->auto_vertical_bias * $faceSide;

        // para garantir o headroom, centro deve obedecer: (cy' - half) <= (y - topGoal)
        $maxCyForTopGoal = $y - $topGoal + $half; // centro máximo (mais alto possível) pra manter headroom
        $cyDesired       = min($cy - $biasPix, $maxCyForTopGoal);

        // limites de centro para ficar dentro da imagem
        $cyLow  = $half;
        $cyHigh = $imgH - $half;
        $cyNew  = max($cyLow, min($cyDesired, $cyHigh));

        // coordenadas finais do quadrado
        $side = 2.0 * $half;
        $x1   = $cx - $half;
        $y1   = $cyNew - $half;

        // proteção pós-arredondamento
        $x1 = max(0.0, min($x1, $imgW - $side));
        $y1 = max(0.0, min($y1, $imgH - $side));

        return $img->crop(
            (int) round($side),
            (int) round($side),
            (int) round($x1),
            (int) round($y1)
        );
    }

    /** Codifica imagem e retorna [encoded, 'data:image/...;base64'] */
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
        return [$img->encode(new JpegEncoder(quality: $quality)), 'data:image/jpeg;base64'];
    }

    /** Detecta bounds (faz downscale pra acelerar). */
    private function detectBoundsFromImage(ImageInterface $img): ?array
    {
        $im_width  = $img->width();
        $im_height = $img->height();

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

    /** Cria ImageManager conforme config (facedetection.driver). */
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

    /** Integrais e metadados. */
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

    /** Valor inteiro de um canal (Intervention v3). */
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

    /** Integrais da imagem (soma e soma dos quadrados). */
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

    /** Detector greedy (igual ao original). */
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

    /** Avaliação do classificador em subimagem. */
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

    /** Normaliza input: caminho, data-uri base64, base64 cru. */
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
     * Faz o auto-crop 1x1, valida o enquadramento do rosto e, se necessário,
     * busca recursivamente entre 40 offsets (10⇢, 10⇠, 10⇡, 10⇣) até encontrar
     * um crop "perfeito" para reconhecimento.
     *
     * @param string      $input       base64/data-uri/arquivo de origem (usado se ainda não houver $this->image/$this->bounds)
     * @param int|null    $resize      lado final do thumbnail (px). Null = mantém
     * @param string      $format      'jpg' | 'png' | 'webp'
     * @param int         $quality     qualidade do encoder
     * @param bool        $dataUri     true => retorna "data:image/..;base64,..."
     * @param int         $steps       passos por direção (10 => 40 tentativas)
     * @param float       $stepFrac    fração do lado da FACE por passo (ex.: 0.03 = 3%)
     * @param float|null  $minMargin   margem mínima (fração do lado do crop) em todos os lados (padrão: 0.08)
     * @param float|null  $minScale    fração mínima do lado do crop ocupada pela face (padrão: 0.35)
     * @param float|null  $maxScale    fração máxima do lado do crop ocupada pela face (padrão: 0.85)
     * @return string                  imagem final em base64 (com ou sem data-uri)
     * @throws \Exception
     */
    public function toBase64AutoRecursive(
        string $input,
        ?int $resize = 512,
        string $format = 'jpg',
        int $quality = 90,
        bool $dataUri = true,
        int $steps = 10,
        float $stepFrac = 0.03,
        ?float $minMargin = null,
        ?float $minScale = null,
        ?float $maxScale = null
    ): string {
        // 1) Garante detecção inicial
        if (!$this->found || !$this->bounds) {
            $this->extract($input);
            if (!$this->found || !$this->bounds) {
                throw new \Exception('Face não encontrada na imagem de entrada.');
            }
        }

        // 2) Parâmetros de validação (com fallback para config)
        $minMargin = $minMargin ?? (function_exists('config') ? (float) config('facedetection.validate_min_margin', 0.08) : 0.08);
        $minScale  = $minScale  ?? (function_exists('config') ? (float) config('facedetection.validate_min_scale', 0.35) : 0.35);
        $maxScale  = $maxScale  ?? (function_exists('config') ? (float) config('facedetection.validate_max_scale', 0.85) : 0.85);

        // 3) Tenta o crop padrão (sem offset)
        $baseCrop = $this->cropAutoSquare((clone $this->image));
        if ($this->validateFaceCrop($baseCrop, $minMargin, $minScale, $maxScale)) {
            return $this->encodeOut($baseCrop, $resize, $format, $quality, $dataUri);
        }

        // 4) Gera offsets (40 no total com $steps=10)
        $offsets = $this->generateDirectionalOffsets($steps, $stepFrac);

        // 5) Busca recursiva
        $result = $this->recursiveTryOffsets($offsets, 0, $minMargin, $minScale, $maxScale, $resize, $format, $quality, $dataUri);
        if ($result !== null) {
            return $result;
        }

        // 6) Fallback: retorna o melhor esforço mesmo sem passar na validação
        // (ou lance exceção se preferir falhar estritamente)
        // throw new \Exception('Não foi possível gerar um crop validado após testar todos os offsets.');
        return $this->encodeOut($baseCrop, $resize, $format, $quality, $dataUri);
    }

    /**
     * Recursão: tenta offsets em sequência até validar um crop.
     *
     * @param array<int, array{dx:float, dy:float}> $offsets
     * @return string|null base64 pronto ou null se nenhum offset servir
     */
    private function recursiveTryOffsets(
        array $offsets,
        int $index,
        float $minMargin,
        float $minScale,
        float $maxScale,
        ?int $resize,
        string $format,
        int $quality,
        bool $dataUri
    ): ?string {
        if ($index >= count($offsets)) {
            return null;
        }

        $dx = $offsets[$index]['dx'];
        $dy = $offsets[$index]['dy'];

        $candidate = $this->cropAutoSquareWithOffset((clone $this->image), $dx, $dy);

        if ($this->validateFaceCrop($candidate, $minMargin, $minScale, $maxScale)) {
            return $this->encodeOut($candidate, $resize, $format, $quality, $dataUri);
        }

        // próximo offset (recursivo)
        return $this->recursiveTryOffsets($offsets, $index + 1, $minMargin, $minScale, $maxScale, $resize, $format, $quality, $dataUri);
    }

    /**
     * Gera offsets direcionais relativos ao TAMANHO DA FACE.
     * Ex.: stepFrac=0.03 e steps=10 => deslocamentos de 3% a 30% do lado da face.
     *
     * Ordem: direita+, esquerda-, cima-, baixo+  (total 4 * steps)
     *
     * @return array<int, array{dx:float, dy:float}>
     */
    private function generateDirectionalOffsets(int $steps = 10, float $stepFrac = 0.03): array
    {
        $list = [];
        for ($i = 1; $i <= $steps; $i++) {
            $f = $i * $stepFrac;
            // direita
            $list[] = ['dx' => +$f, 'dy' => 0.0];
            // esquerda
            $list[] = ['dx' => -$f, 'dy' => 0.0];
            // cima (y negativo)
            $list[] = ['dx' => 0.0, 'dy' => -$f];
            // baixo (y positivo)
            $list[] = ['dx' => 0.0, 'dy' => +$f];
        }
        return $list;
    }

    /**
     * Valida se o crop contém um rosto BEM ENQUADRADO.
     * Critérios:
     *  - face detectável (usa o mesmo detector no crop);
     *  - margem mínima em todos os lados (fração do lado do crop);
     *  - escala da face dentro de [minScale, maxScale] do lado do crop.
     */
    private function validateFaceCrop(
        ImageInterface $crop,
        float $minMargin = 0.08,
        float $minScale = 0.35,
        float $maxScale = 0.85
    ): bool {
        $side = $crop->width(); // é quadrado
        if ($side <= 0) return false;

        $stats  = $this->get_img_stats($crop);
        $bounds = $this->do_detect_greedy_big_to_small(
            $stats['ii'],
            $stats['ii2'],
            $stats['width'],
            $stats['height']
        );

        if (!$bounds || $bounds['w'] <= 0) {
            return false; // não achou face no crop
        }

        // escala (tamanho relativo da face)
        $faceFrac = $bounds['w'] / $side;
        if ($faceFrac < $minScale || $faceFrac > $maxScale) {
            return false;
        }

        // margens normalizadas
        $left   = $bounds['x'];
        $top    = $bounds['y'];
        $right  = $side - ($bounds['x'] + $bounds['w']);
        $bottom = $side - ($bounds['y'] + $bounds['w']);

        $minPix = $minMargin * $side;

        if ($left < $minPix || $right < $minPix || $top < $minPix || $bottom < $minPix) {
            return false;
        }

        return true;
    }

    /**
     * Variação do auto-crop com deslocamento manual do centro do quadrado.
     * $dx e $dy são FRAÇÕES do lado da FACE (não do crop). Positivo: direita/baixo.
     */
    private function cropAutoSquareWithOffset(ImageInterface $img, float $dx, float $dy): ImageInterface
    {
        $imgW = $img->width();
        $imgH = $img->height();

        $x = (float) $this->bounds['x'];
        $y = (float) $this->bounds['y'];
        $w = (float) $this->bounds['w'];
        $h = (float) $this->bounds['h'];

        $faceSide = max($w, $h);
        $baseHalf = $faceSide / 2.0;
        $cx       = $x + $w / 2.0;
        $cy       = $y + $h / 2.0;

        // parâmetros herdados da classe
        $topGoal  = $this->auto_top_margin_factor * $faceSide;
        $capHalf  = $baseHalf * $this->auto_expand_cap;
        $biasPix  = $this->auto_vertical_bias * $faceSide;

        // half por bordas
        $halfByEdges = min($cx, $imgW - $cx, $cy, $imgH - $cy);

        // half base (respeitando headroom/cap/bordas)
        $half = max($baseHalf + $topGoal, $baseHalf);
        $half = min($half, $capHalf, $halfByEdges);

        // centro desejado (aplicar viés + deslocamentos dx/dy relativos à FACE)
        $cxDesired = $cx + $dx * $faceSide;

        // headroom: (cy' - half) <= (y - topGoal)
        $maxCyForTopGoal = $y - $topGoal + $half;
        $cyDesired = min($cy - $biasPix + ($dy * $faceSide), $maxCyForTopGoal);

        // clamping do centro para ficar dentro da imagem
        $cxLow  = $half;
        $cxHigh = $imgW - $half;
        $cyLow  = $half;
        $cyHigh = $imgH - $half;

        $cxNew = max($cxLow, min($cxDesired, $cxHigh));
        $cyNew = max($cyLow, min($cyDesired, $cyHigh));

        // coordenadas finais
        $side = 2.0 * $half;
        $x1   = $cxNew - $half;
        $y1   = $cyNew - $half;

        // proteção final
        $x1 = max(0.0, min($x1, $imgW - $side));
        $y1 = max(0.0, min($y1, $imgH - $side));

        return $img->crop(
            (int) round($side),
            (int) round($side),
            (int) round($x1),
            (int) round($y1)
        );
    }

    /**
     * Encoda + (opcional) redimensiona e devolve base64/data-uri.
     */
    private function encodeOut(
        ImageInterface $img,
        ?int $resize,
        string $format,
        int $quality,
        bool $dataUri
    ): string {
        if ($resize !== null) {
            $img = $img->scaleDown($resize, $resize);
        }
        [$encoded, $mime] = $this->encodeImage($img, $format, $quality);
        $bin = method_exists($encoded, 'toString') ? $encoded->toString() : (string) $encoded;
        $b64 = base64_encode($bin);
        return $dataUri ? ("{$mime},{$b64}") : $b64;
    }

        /**
     * Gera base64 1:1 expandindo FIXO +40% ao redor da face antes do corte.
     * - Se ainda não houver bounds (this->found = false), chama extract($input).
     * - Clampa nas bordas da imagem para não estourar.
     * - Opcionalmente redimensiona para $resize x $resize (mantendo 1:1).
     *
     * @param string   $input    Caminho ou base64 (com/sem data-uri), caso ainda não tenha extract().
     * @param int|null $resize   Lado final (px). Null mantém o tamanho do recorte.
     * @param string   $format   'jpg'|'png'|'webp'
     * @param int      $quality  Qualidade (JPG/WEBP)
     * @param bool     $dataUri  true para retornar "data:image/...;base64,XXXX"
     * @param float    $marginFator Fator de margem (padrão: 0.40, 40% do lado da face)
     * @return string            Base64 (com ou sem data-uri)
     * @throws \Exception
     */
    public function toBase64WithMargin(
        string $input,
        ?int $resize = 512,
        string $format = 'jpg',
        int $quality = 95,
        bool $dataUri = true,
        $marginFator = 0.40
    ): string {
        // garante bounds
        if (!$this->found || !$this->bounds) {
            $this->extract($input);
            if (!$this->found || !$this->bounds) {
                throw new \Exception("No face bounds available to toBase64WithMargin40");
            }
        }

        // recorta com margem fixa de +40%
        $img = $this->cropWithMargin((clone $this->image), $marginFator);

        // resize opcional
        if ($resize !== null) {
            $img = $img->scaleDown($resize, $resize);
        }

        // encode e devolve base64
        [$encoded, $mime] = $this->encodeImage($img, $format, $quality);
        $bin = method_exists($encoded, 'toString') ? $encoded->toString() : (string) $encoded;
        $b64 = base64_encode($bin);

        return $dataUri ? ("{$mime},{$b64}") : $b64;
    }

    //** MÉTODOS AUXILIARES PARA INVESTIGAÇÃO DE FUNCIONAMENTO DO PROCESSO */

    /**
     * Retorna apenas o boundary da face detectada (x, y, w, h) ou null.
     * - Aceita caminho de arquivo ou base64 (com/sem data-uri).
     * - Faz auto-orientação por EXIF e tenta rotações [0, 90, 270, 180].
     * - Se houver rotação aplicada (além do EXIF), retorna também
     *   a imagem rotacionada em base64 na chave 'imagemRotateBase64'.
     *
     * @param string $input
     * @return array{x:float,y:float,w:float,h:float,imagemRotateBase64?:string}|null
     */
    public function getBoundary(string $input): ?array
    {
        // carrega imagem
        $base = $this->driver->read($this->normalizeInput($input));
        if (method_exists($base, 'orient')) {
            $base = $base->orient(); // ajusta rotação via EXIF
        }

        // tenta múltiplas rotações
        foreach ([0, 90, 270, 180] as $angle) {
            $img = $angle === 0 ? (clone $base) : (clone $base)->rotate($angle);

            $bounds = $this->detectBoundsFromImage($img);
            if (!$bounds || ($bounds['w'] ?? 0) <= 0) {
                continue;
            }

            // detector retorna quadrado; mantém compatível
            $bounds['h'] = $bounds['w'];

            // retorno básico
            $result = [
                'x' => round((float) $bounds['x'], 1),
                'y' => round((float) $bounds['y'], 1),
                'w' => round((float) $bounds['w'], 1),
                'h' => round((float) $bounds['h'], 1),
            ];

            // se houve rotação além de 0°, adiciona base64 da imagem rotacionada
            if ($angle !== 0) {
                $result['imagemRotateBase64'] = 'data:image/jpeg;base64,' .
                    base64_encode((string) $img->toJpeg(90));
            }

            return $result;
        }

        return null;
    }


    /**
     * Recorta a imagem com base em um boundary e retorna em Base64.
     *
     * @param array  $boundary  ['x'=>float,'y'=>float,'w'=>float,'h'=>float, 'imagemRotateBase64'?:string]
     * @param string $input     data-uri, base64 cru ou caminho de arquivo
     * @param string $format    'jpg'|'png'|'webp'
     * @param int    $quality   qualidade (JPG/WEBP)
     * @param bool   $dataUri   true => retorna "data:image/...;base64,...."
     *
     * @return string Base64 (data-uri se $dataUri=true)
     *
     * @throws \Exception
     */
    public function cropByBoundaryToBase64(
        array $boundary,
        string $input,
        string $format = 'jpg',
        int $quality = 95,
        bool $dataUri = true
    ): string {
        
        // 1) Se o boundary já trouxe a imagem rotacionada, use-a para manter o mesmo referencial
        $source = $boundary['imagemRotateBase64'] ?? $input;

        // 2) Carrega imagem (sem aplicar orient extra; boundary já está nesse referencial)
        $img = $this->driver->read($this->normalizeInput($source));

        // 3) Lê e normaliza o retângulo
        $x = isset($boundary['x']) ? (float)$boundary['x'] : 0.0;
        $y = isset($boundary['y']) ? (float)$boundary['y'] : 0.0;
        $w = isset($boundary['w']) ? (float)$boundary['w'] : 0.0;
        $h = isset($boundary['h']) ? (float)$boundary['h'] : (float)$w; // compatível com detector (quadrado)

        if ($w <= 0 || $h <= 0) {
            throw new \Exception('Boundary inválido: largura/altura não podem ser <= 0.');
        }

        // 4) Clamping para não sair da imagem
        $imgW = $img->width();
        $imgH = $img->height();

        // força X/Y dentro
        $x = max(0.0, min($x, (float)($imgW - 1)));
        $y = max(0.0, min($y, (float)($imgH - 1)));

        // ajusta W/H se ultrapassar borda
        if ($x + $w > $imgW) { $w = (float)($imgW - $x); }
        if ($y + $h > $imgH) { $h = (float)($imgH - $y); }

        // arredonda para inteiros de corte
        $cropW = max(1, (int)round($w));
        $cropH = max(1, (int)round($h));
        $cropX = max(0, (int)round($x));
        $cropY = max(0, (int)round($y));

        // 5) Aplica o crop
        $out = (clone $img)->crop($cropW, $cropH, $cropX, $cropY);

        // 6) Encode + retorno em base64 (data-uri opcional)
        [$encoded, $mime] = $this->encodeImage($out, $format, $quality);
        $bin = method_exists($encoded, 'toString') ? $encoded->toString() : (string)$encoded;
        $b64 = base64_encode($bin);

        return $dataUri ? ("{$mime},{$b64}") : $b64;
    }

}
