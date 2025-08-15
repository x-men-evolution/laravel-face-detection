# Laravel Face Detection

Pacote Laravel para **detecção de face** e **recorte 1:1 com margem** em imagens.  
Compatível com **Laravel 10, 11 e 12** e **Intervention Image v3**.

---

## Requisitos

- **PHP** ≥ 8.1
- **Laravel** 10/11/12
- **Intervention Image v3** com **GD** ou **Imagick**
- Extensões recomendadas:
  - `ext-gd` (ou `ext-imagick`)
  - `ext-fileinfo`, `ext-mbstring`, `ext-exif` (para `orientate()`)

---

## Instalação

### Opção A — Repositório privado (VCS)

No `composer.json` do **seu projeto**, adicione o repositório e exija o pacote (ajuste a _branch_ ou _tag_ conforme seu fork):

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "git@github.com:x-men-evolution/laravel-face-detection.git"
    }
  ],
  "require": {
    "x-men-evolution/laravel-face-detection": "dev-master"
  }
}
```

Depois:

```bash
composer update x-men-evolution/laravel-face-detection
```

> Se o repositório usar `main` em vez de `master`, troque para `dev-main`.  
> Se você criou uma tag (ex.: `v2.0.0`), pode usar `"^2.0"`.

### Opção B — Packagist (se/quando publicado)

```bash
composer require x-men-evolution/laravel-face-detection
```

---

## Autodiscovery

O pacote usa **autodiscovery** do Laravel. **Não é necessário** registrar manualmente.

Se o autodiscovery estiver desativado, registre no `config/app.php`:

```php
'providers' => [
    // ...
    EvolutionTech\FaceDetection\FaceDetectionServiceProvider::class,
],

'aliases' => [
    // ...
    'FaceDetection' => EvolutionTech\FaceDetection\Facades\FaceDetection::class,
],
```

---

## Uso Básico

```php
use EvolutionTech\FaceDetection\Facades\FaceDetection;

// Caminho de arquivo OU data-uri/base64 ('data:image/jpeg;base64,....')
$face = FaceDetection::extract('path/to/image.jpg');

if ($face->found) {
    // Face encontrada
} else {
    // Nenhuma face encontrada
}
```

### Limites (bounding box)

```php
var_dump($face->bounds);

/*
array(4) {
  ["x"]=> float(292)
  ["y"]=> float(167)
  ["w"]=> float(204.8)
  ["h"]=> float(204.8)
}
*/
```

### Salvar a face padrão (sem margem)

```php
$face->save('path/to/output.jpg');
```

---

## Recorte 1:1 com Margem (~30%)

Este fork expõe um método para recortar a face em **formato quadrado (1:1)** adicionando **margem ao redor**, com **padronização de tamanho** e **formato** de saída.

```php
$face->saveWithMargin(
    input:   'path/or/base64',                 // caminho do arquivo OU data-uri/base64
    destino: storage_path('app/public/face.jpg'),
    marginFator: 0.30,                         // 30% de margem ao redor
    resize: 512,                               // redimensiona para 512x512 (opcional; null mantém)
    forceFormat: 'jpg',                        // 'jpg' | 'png' | 'webp' | null
    jpgQuality: 90                             // qualidade para JPG/WebP
);
```

**O que o método faz:**

- Lê a imagem e corrige orientação EXIF (`orientate()`),
- Expande a caixa da face pela margem,
- **Força** o recorte a ser **quadrado**,
- Faz _clamping_ para não sair dos limites da imagem,
- Aplica `crop`, `resize` opcional e `encode` no formato desejado,
- Salva no destino.

> **Regra de negócio sugerida:** se o detector identificar múltiplas faces, você pode rejeitar a imagem ou escolher a **maior** (maior área do _bounding box_) antes de chamar `saveWithMargin()`.

---

## Notas sobre o detector

- O modelo de detecção utilizado (arquivo `src/Data/face.dat`) é um classificador tradicional para **face frontal**.
- Para cenários com ângulos extremos, oclusões ou iluminação adversa, considere integrar um detector mais robusto (via microserviço, CLI, etc.) e apenas repassar os _bounds_ para o método de recorte — o fluxo de _crop_ permanece o mesmo.

---

## Testes

```bash
vendor/bin/phpunit
```

---

## Licença

[MIT](LICENSE)
