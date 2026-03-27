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
                'size' => '1024x1024',
                'output_format' => 'png',
            ],
        ]);

        $statusCode = $response->getStatusCode();

        if (200 !== $statusCode) {
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

        $imageBase64 = base64_encode($imageData);

        $response = $this->httpClient->request('POST', 'images/edits', [
            'json' => [
                'model' => 'gpt-image-1-mini',
                'prompt' => $fullPrompt,
                'images' => [
                    ['image_url' => 'data:image/jpeg;base64,'.$imageBase64],
                ],
                'background' => 'transparent',
                'output_format' => 'png',
                'size' => '1024x1024',
            ],
        ]);

        $statusCode = $response->getStatusCode();

        if (200 !== $statusCode) {
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
