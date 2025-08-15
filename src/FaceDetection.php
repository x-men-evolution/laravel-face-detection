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

    public function __construct()
    {
        $this->driver = $this->defaultDriver();

        if (function_exists('config')) {
            $this->padding_width  = (int) (config('facedetection.padding_width', 0));
            $this->padding_height = (int) (config('facedetection.padding_height', 0));
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
     * - Aplica oriantação por EXIF (orient()).
     * - Se não encontrar, tenta novamente com rotações 90°, 270° e 180°.
     *
     * @param string $file
     * @return $this
     */
    public function extract($file)
    {
        $base = $this->driver->read($this->normalizeInput($file));

        // Em v3 a auto-orientação é padrão, mas garantimos explicitamente:
        if (method_exists($base, 'orient')) {
            $base = $base->orient();
        }

        // Tenta nas rotações: 0°, 90°, 270°, 180°
        $angles = [0, 90, 270, 180];
        $foundBounds = null;
        $finalImage  = null;

        foreach ($angles as $angle) {
            $img = $angle === 0 ? $base->clone() : $base->clone()->rotate($angle);
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

        $img = $this->image->clone()->crop(
            (int) $to_crop['width'],
            (int) $to_crop['height'],
            (int) $to_crop['x'],
            (int) $to_crop['y']
        );

        $img = $img->encode(new JpegEncoder(quality: 100));
        $img->save($file_name);
    }

    /**
     * Salva recorte 1:1 com margem percentual ao redor da face, com resize/encode opcionais.
     *
     * @param string      $input         Caminho ou base64 (com/sem cabeçalho)
     * @param string      $file_name     Caminho destino
     * @param float       $marginFator   0.30 = 30% de margem
     * @param int|null    $resize        Lado final (px) - null mantém
     * @param string|null $forceFormat   'jpg'|'png'|'webp'|null
     * @param int         $jpgQuality    Qualidade (JPG/WebP)
     * @throws \Exception
     * @return void
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
            // Garante que bounds existem; se não, tenta extrair a partir do input
            $this->extract($input);
            if (!$this->found || !$this->bounds) {
                throw new \Exception("No face bounds available to saveWithMargin");
            }
        }

        $img  = $this->image->clone();
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

        // 2) Quadrado
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
        $img = $img->crop(
            (int) round($side),
            (int) round($side),
            (int) round($sqX1),
            (int) round($sqY1)
        );

        // 5) Resize opcional
        if ($resize !== null) {
            $img = $img->scaleDown($resize, $resize);
        }

        // 6) Encoder/saída
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
            $temp = $img->clone()->resize(
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
     * Integrais da imagem (soma e soma dos quadrados).
     * Compatível com pickColor() da v3 (sem parâmetro de formato).
     *
     * @param ImageInterface $image
     * @param int $image_width
     * @param int $image_height
     * @return array{ii: array, ii2: array}
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
                // v3: pickColor() retorna um objeto de cor; lidamos com array/obj para robustez
                $px = $image->pickColor($j, $i);

                if (is_object($px)) {
                    // Métodos típicos na Color class
                    $red   = (int) (method_exists($px, 'red')   ? $px->red()   : 0);
                    $green = (int) (method_exists($px, 'green') ? $px->green() : 0);
                    $blue  = (int) (method_exists($px, 'blue')  ? $px->blue()  : 0);
                } elseif (is_array($px) && count($px) >= 3) {
                    $red   = (int) $px[0];
                    $green = (int) $px[1];
                    $blue  = (int) $px[2];
                } else {
                    // Fallback conservador
                    $red = $green = $blue = 0;
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
     *
     * @param array $ii
     * @param array $ii2
     * @param int   $width
     * @param int   $height
     * @return array{x:float,y:float,w:float}|null
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
     * Normaliza o input para aceitar:
     * - Caminho de arquivo
     * - Data-URI base64 (data:image/...)
     * - Base64 "cru" (assume JPEG)
     */
    private function normalizeInput(string $input): string
    {
        // Data-URI?
        if (str_starts_with($input, 'data:image/')) {
            return $input;
        }

        // Caminho de arquivo?
        if (is_file($input)) {
            return $input;
        }

        // Provável base64 "cru"? (tolerante a quebras de linha)
        $maybeBase64 = preg_replace('/\s+/', '', $input ?? '');
        if ($maybeBase64 !== null && preg_match('/^[A-Za-z0-9+\/=]+$/', $maybeBase64)) {
            return 'data:image/jpeg;base64,' . $maybeBase64;
        }

        // Fallback
        return $input;
    }
}
