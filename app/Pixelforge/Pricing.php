<?php

namespace App\Pixelforge;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

final class Pricing
{
    public const CATEGORIES = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

    public function bill(array $usage, array $rates): array
    {
        $weighted = BigDecimal::zero();
        foreach (self::CATEGORIES as $category) {
            $count = $usage[$category] ?? null;
            $rate = $rates[$category] ?? null;
            if (! is_int($count) || $count < 0 || ! is_string($rate) || ! preg_match('/^\d+(?:\.\d+)?$/D', $rate)) {
                throw new InvalidArgumentException('Incomplete provider usage or rates.');
            }
            $weighted = $weighted->plus(BigDecimal::of($rate)->multipliedBy($count));
        }
        $cost = $weighted->dividedByExact(1000000);
        $charge = $cost->multipliedBy('1.25');

        return [
            'provider_cost' => (string) $cost->toScale(12),
            'customer_charge' => (string) $charge->toScale(12),
            'profit' => (string) $charge->minus($cost)->toScale(12),
        ];
    }

    public function quote(array $input, array $pricing): array
    {
        $tokens = $pricing['tokens'];
        $quality = $input['quality'];
        $base = BigDecimal::of(mb_strlen($input['prompt'], 'UTF-8'))
            ->dividedBy($tokens['characters_per_text_token'], 0, RoundingMode::Ceiling);
        $variance = BigDecimal::of((string) $tokens['text_token_variance']);
        $minText = $base->multipliedBy(BigDecimal::one()->minus($variance))->toScale(0, RoundingMode::Floor)->toInt();
        $maxText = $base->multipliedBy(BigDecimal::one()->plus($variance))->toScale(0, RoundingMode::Ceiling)->toInt();
        $fixed = [
            'input_image_tokens' => count($input['reference_images']) * $tokens['per_reference_image'],
            'output_tokens' => $tokens['output'][$quality][$input['size']],
        ];
        $bounds = [];
        foreach (['min' => $minText, 'max' => $maxText] as $bound => $text) {
            $bill = $this->bill($fixed + ['input_text_tokens' => $text, 'reasoning_tokens' => $tokens['reasoning'][$quality][$bound]], $pricing['rates']);
            $bounds[$bound] = $bill['customer_charge'];
        }

        return $bounds;
    }
}
