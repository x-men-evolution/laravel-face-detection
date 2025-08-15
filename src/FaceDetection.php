<?php

namespace Arhey\FaceDetection;

use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Encoders\WebpEncoder;

class FaceDetection
{
    /** @var array{x: float, y: float, w: float, h: float}|null */
    public $bounds;

    /** @var bool */
    public $found = false;

    /** @var ImageManager */
    public $driver;

    /** @var \Intervention\Image\Interfaces\ImageInterface */
    private $image;

    /** @var int */
    private $padding_width = 0;

    /** @var int */
    private $padding_height = 0;

    /** @var array<int, mixed> */
    private $detection_data;

    public function __construct()
    {
        // Driver da Intervention v3: precisa ser DriverInterface (GD/Imagick) ou string ('gd'|'imagick')
        $this->driver = $this->defaultDriver();

        // Carrega paddings de configuração (com defaults seguros)
        if (function_exists('config')) {
            $this->padding_width  = (int) (config('facedetection.padding_width', 0));
            $this->padding_height = (int) (config('facedetection.padding_height', 0));
        }

        // Carrega o modelo de detecção (haar) via caminho relativo seguro
        $detection_file = __DIR__ . '/Data/face.dat';
        if (is_file($detection_file)) {
            $data = file_get_contents($detection_file);
            if ($data === false) {
                throw new \Exception("Couldn't read detection data");
            }
            $this->detection_data = unserialize($data);
        } else {
            throw new \Exception("Couldn't load detection data at {$detection_file}");
        }
    }

    /**
     * Extrai a face principal e calcula bounds.
     * Aceita caminho de arquivo ou base64 (com/sem cabeçalho data-uri).
     *
     * @param string $file
     * @return $this
     */
    public function extract($file)
    {
        // Leitura + correção EXIF
        $this->image = $this->driver
            ->read($this->normalizeInput($file))
            ->orientate();

        $im_width  = $this->image->width();
        $im_height = $this->image->height();

        // Redução opcional para acelerar detecção (alvo ~320x240) — apenas se for maior
        $ratioW = $im_width  / 320.0;
        $ratioH = $im_height / 240.0;
        $ratio  = max($ratioW, $ratioH);
        if ($ratio <= 1) {
            $ratio = 0; // não reduz se já for pequeno
        }

        if ($ratio > 1) {
            // Clona, redimensiona para detecção e converte bounds de volta
            $temp = $this->image->clone();
            $temp->resize(
                (int) round($im_width / $ratio),
                (int) round($im_height / $ratio)
            );

            $stats       = $this->get_img_stats($temp);
            $this->bounds = $this->do_detect_greedy_big_to_small(
                $stats['ii'],
                $stats['ii2'],
                $stats['width'],
                $stats['height']
            );

            if ($this->bounds) {
                $this->bounds['h'] = $this->bounds['w'];
                if ($this->bounds['w'] > 0) {
                    // Converte de volta para as dimensões originais
                    $this->bounds['x'] *= $ratio;
                    $this->bounds['y'] *= $ratio;
                    $this->bounds['w'] *= $ratio;
                    $this->bounds['h'] *= $ratio;
                }
            }
        } else {
            $stats        = $this->get_img_stats($this->image);
            $this->bounds = $this->do_detect_greedy_big_to_small(
                $stats['ii'],
                $stats['ii2'],
                $stats['width'],
                $stats['height']
            );
        }

        if ($this->bounds) {
            if ($this->bounds['w'] > 0) {
                $this->found = true;
            }

            // Arredonda para 1 casa decimal como o original
            $this->bounds['x'] = round($this->bounds['x'], 1);
            $this->bounds['y'] = round($this->bounds['y'], 1);
            $this->bounds['w'] = round($this->bounds['w'], 1);
            $this->bounds['h'] = round($this->bounds['h'], 1);
        }

        return $this;
    }

    /**
     * Salva o recorte padrão (sem margem adicional além do padding configurado).
     * Mantém compat com a API original.
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

        // Clona a imagem original
        $cropped_image = $this->image->clone();

        // Crop
        $cropped_image->crop(
            (int) $to_crop['width'],
            (int) $to_crop['height'],
            (int) $to_crop['x'],
            (int) $to_crop['y']
        );

        // Força JPG (compat com v2: $quality=100, 'jpg')
        $cropped_image = $cropped_image->encode(new JpegEncoder(quality: 100));
        $cropped_image->save($file_name);
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

        // Trabalha sempre na imagem já carregada + orientada
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

        // 2) Quadrado (lado = maior dimensão)
        $cropW = $x2 - $x1;
        $cropH = $y2 - $y1;
        $side  = max($cropW, $cropH);

        // Centro do recorte
        $cx = ($x1 + $x2) / 2.0;
        $cy = ($y1 + $y2) / 2.0;

        $sqX1 = $cx - $side / 2.0;
        $sqY1 = $cy - $side / 2.0;

        // 3) Clamping nas bordas da imagem
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
     * Cria o ImageManager conforme config (facedetection.driver).
     * v3 exige DriverInterface OU 'gd'/'imagick'.
     *
     * @return ImageManager
     */
    protected function defaultDriver()
    {
        $driverKey = 'gd';
        if (function_exists('config')) {
            // mantém nome do config original do pacote
            $driverKey = (string) config('facedetection.driver', 'gd');
        }
        $driverKey = strtolower($driverKey);

        return match ($driverKey) {
            'imagick' => new ImageManager(new ImagickDriver()),
            default   => new ImageManager(new GdDriver()),
        };
    }

    /**
     * Constrói integrais da imagem (usadas no detector).
     *
     * @param \Intervention\Image\Interfaces\ImageInterface $image
     * @return array{width:int,height:int,ii:array,ii2:array}
     */
    protected function get_img_stats($image)
    {
        $image_width  = $image->width();
        $image_height = $image->height();
        $iis = $this->compute_ii($image, $image_width, $image_height);
        return array(
            'width'  => $image_width,
            'height' => $image_height,
            'ii'     => $iis['ii'],
            'ii2'    => $iis['ii2'],
        );
    }

    /**
     * Integrais da imagem (soma e soma dos quadrados).
     *
     * @param \Intervention\Image\Interfaces\ImageInterface $image
     * @param int $image_width
     * @param int $image_height
     * @return array{ii: array, ii2: array}
     */
    protected function compute_ii($image, $image_width, $image_height)
    {
        $ii_w = $image_width + 1;
        $ii_h = $image_height + 1;
        $ii   = array();
        $ii2  = array();

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
                // Mantém a mesma estratégia do pacote original (formato int)
                $rgb   = $image->pickColor($j, $i, 'int');
                $red   = ($rgb >> 16) & 0xFF;
                $green = ($rgb >> 8) & 0xFF;
                $blue  = $rgb & 0xFF;
                $grey  = (int) floor(0.2989 * $red + 0.587 * $green + 0.114 * $blue);

                $rowsum  += $grey;
                $rowsum2 += $grey * $grey;

                $ii_above = ($i - 1) * $ii_w + $j;
                $ii_this  = $i * $ii_w + $j;

                $ii[$ii_this]  = $ii[$ii_above] + $rowsum;
                $ii2[$ii_this] = $ii2[$ii_above] + $rowsum2;
            }
        }
        return array('ii' => $ii, 'ii2' => $ii2);
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
        $s_w         = $width / 20.0;
        $s_h         = $height / 20.0;
        $start_scale = $s_h < $s_w ? $s_h : $s_w;
        $scale_update = 1 / 1.2;

        for ($scale = $start_scale; $scale > 1; $scale *= $scale_update) {
            $w     = floor(20 * $scale);
            $endx  = $width - $w - 1;
            $endy  = $height - $w - 1;
            $step  = floor(max($scale, 2));
            $inv_area = 1 / ($w * $w);

            for ($y = 0; $y < $endy; $y += $step) {
                for ($x = 0; $x < $endx; $x += $step) {
                    $passed = $this->detect_on_sub_image($x, $y, $scale, $ii, $ii2, $w, $width + 1, $inv_area);
                    if ($passed) {
                        return array('x' => (float) $x, 'y' => (float) $y, 'w' => (float) $w);
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

        $passed = true;
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
                    $vals       = $current_node[0];
                    $node_thresh = $vals[0];
                    $leftval    = $vals[1];
                    $rightval   = $vals[2];
                    $leftidx    = $vals[3];
                    $rightidx   = $vals[4];
                    $rects      = $current_node[1];

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
        $maybeBase64 = preg_replace('/\s+/', '', $input);
        if ($maybeBase64 !== null && preg_match('/^[A-Za-z0-9+\/=]+$/', $maybeBase64)) {
            return 'data:image/jpeg;base64,' . $maybeBase64;
        }

        // Fallback: retorna como veio
        return $input;
    }
}
