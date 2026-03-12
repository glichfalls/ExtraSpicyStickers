<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class OpenAiImageService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    public const STYLES = [
        'default' => ['name' => 'Default', 'prompt' => 'Bold outlines, vibrant colors, transparent background, simple.'],
        'pixel' => ['name' => 'Pixel Art', 'prompt' => 'Pixel art style, 16-bit retro game aesthetic, crisp pixels, transparent background.'],
        'watercolor' => ['name' => 'Watercolor', 'prompt' => 'Soft watercolor painting style, gentle colors, artistic brush strokes, transparent background.'],
        'cartoon' => ['name' => 'Cartoon', 'prompt' => 'Exaggerated cartoon style, thick outlines, bright saturated colors, fun and playful, transparent background.'],
        '3d' => ['name' => '3D', 'prompt' => '3D rendered style, smooth shading, soft lighting, clay-like material, transparent background.'],
        'sketch' => ['name' => 'Sketch', 'prompt' => 'Hand-drawn pencil sketch style, crosshatching, rough lines, artistic, transparent background.'],
        'flat' => ['name' => 'Flat', 'prompt' => 'Flat design, minimal, geometric shapes, no gradients, clean vector look, transparent background.'],
    ];

    public function generateImage(string $prompt, string $style = 'default'): string
    {
        $response = $this->httpClient->request('POST', 'images/generations', [
            'json' => [
                'model' => 'gpt-image-1-mini',
                'prompt' => $this->buildStickerPrompt($prompt, $style),
                'size' => '512x512',
                'output_format' => 'png',
            ],
        ]);

        $statusCode = $response->getStatusCode();

        if ($statusCode !== 200) {
            $body = $response->getContent(false);
            $error = json_decode($body, true);
            $message = $error['error']['message'] ?? "OpenAI returned HTTP $statusCode";
            throw new \RuntimeException($message);
        }

        $data = $response->toArray();

        if (!isset($data['data'][0]['b64_json'])) {
            throw new \RuntimeException('No image data received from OpenAI');
        }

        return base64_decode($data['data'][0]['b64_json']);
    }

    public function remixImage(string $imageData, string $prompt, string $style = 'default'): string
    {
        $stylePrompt = self::STYLES[$style]['prompt'] ?? self::STYLES['default']['prompt'];
        $fullPrompt = "Turn this into a sticker: $prompt. $stylePrompt";

        // Convert to RGBA PNG (Telegram sends JPEG, dall-e-2 requires RGBA PNG < 4MB)
        $src = imagecreatefromstring($imageData);
        if ($src === false) {
            throw new \RuntimeException('Failed to read uploaded image');
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = ($w > 1024 || $h > 1024) ? min(1024 / $w, 1024 / $h) : 1.0;
        $nw = (int) ($w * $scale);
        $nh = (int) ($h * $scale);

        $rgba = imagecreatetruecolor($nw, $nh);
        imagealphablending($rgba, false);
        imagesavealpha($rgba, true);
        $transparent = imagecolorallocatealpha($rgba, 0, 0, 0, 127);
        imagefill($rgba, 0, 0, $transparent);
        imagecopyresampled($rgba, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);

        ob_start();
        imagepng($rgba);
        $pngData = ob_get_clean();
        imagedestroy($rgba);

        $boundary = bin2hex(random_bytes(16));

        $body = "--$boundary\r\n";
        $body .= "Content-Disposition: form-data; name=\"model\"\r\n\r\ndall-e-2\r\n";
        $body .= "--$boundary\r\n";
        $body .= "Content-Disposition: form-data; name=\"prompt\"\r\n\r\n$fullPrompt\r\n";
        $body .= "--$boundary\r\n";
        $body .= "Content-Disposition: form-data; name=\"size\"\r\n\r\n512x512\r\n";
        $body .= "--$boundary\r\n";
        $body .= "Content-Disposition: form-data; name=\"image\"; filename=\"image.png\"\r\n";
        $body .= "Content-Type: image/png\r\n\r\n";
        $body .= $pngData . "\r\n";
        $body .= "--$boundary--\r\n";

        $response = $this->httpClient->request('POST', 'images/edits', [
            'headers' => [
                'Content-Type' => "multipart/form-data; boundary=$boundary",
            ],
            'body' => $body,
        ]);

        $statusCode = $response->getStatusCode();

        if ($statusCode !== 200) {
            $responseBody = $response->getContent(false);
            $error = json_decode($responseBody, true);
            $message = $error['error']['message'] ?? "OpenAI returned HTTP $statusCode";
            throw new \RuntimeException($message);
        }

        $data = $response->toArray();

        if (!isset($data['data'][0]['b64_json'])) {
            throw new \RuntimeException('No image data received from OpenAI');
        }

        return base64_decode($data['data'][0]['b64_json']);
    }

    private function buildStickerPrompt(string $userPrompt, string $style): string
    {
        $stylePrompt = self::STYLES[$style]['prompt'] ?? self::STYLES['default']['prompt'];
        return "Sticker: $userPrompt. $stylePrompt";
    }
}
