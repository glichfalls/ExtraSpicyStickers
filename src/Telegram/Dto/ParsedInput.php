<?php

namespace App\Telegram\Dto;

use App\Service\OpenAiImageService;

readonly class ParsedInput
{
    public function __construct(
        public string $description,
        public string $emoji,
        public string $style,
    ) {
    }

    public static function fromText(string $text, string $defaultEmoji): self
    {
        // Extract --style flag
        $style = 'default';
        $text = preg_replace_callback('/--(\w+)/', function (array $matches) use (&$style) {
            $flag = strtolower($matches[1]);
            if (isset(OpenAiImageService::STYLES[$flag])) {
                $style = $flag;
            }
            return '';
        }, $text);

        $text = trim($text);

        // Extract emoji
        $emojis = \Emoji\detect_emoji($text);
        $emoji = $defaultEmoji;
        if (!empty($emojis)) {
            $emoji = preg_replace('/\x{FE0F}/u', '', $emojis[0]['emoji']);
        }

        $description = trim(\Emoji\remove_emoji($text));

        return new self($description, $emoji, $style);
    }

    public static function styleList(): string
    {
        $lines = [];
        foreach (OpenAiImageService::STYLES as $key => $info) {
            $lines[] = "  --$key — {$info['name']}";
        }
        return implode("\n", $lines);
    }
}
