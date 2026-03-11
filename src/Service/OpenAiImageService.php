<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class OpenAiImageService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    public function generateImage(string $prompt): string
    {
        $response = $this->httpClient->request('POST', 'images/generations', [
            'json' => [
                'model' => 'gpt-image-1-mini',
                'prompt' => $this->buildStickerPrompt($prompt),
                'size' => '1024x1024',
                'output_format' => 'png',
            ],
        ]);

        $data = $response->toArray();

        if (!isset($data['data'][0]['b64_json'])) {
            throw new \RuntimeException('No image data received from OpenAI');
        }

        return base64_decode($data['data'][0]['b64_json']);
    }

    private function buildStickerPrompt(string $userPrompt): string
    {
        return sprintf(
            'Create a sticker-style illustration: %s. ' .
            'The image should have a clean, simple design suitable for a messaging sticker. ' .
            'Use bold outlines, vibrant colors, and a transparent or solid background. ' .
            'The style should be cute, expressive, and easily recognizable at small sizes.',
            $userPrompt
        );
    }
}
