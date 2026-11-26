<?php

namespace Tests\Unit;

use App\Pixelforge\Pricing;
use PHPUnit\Framework\TestCase;

class PricingTest extends TestCase
{
    public function test_each_usage_category_has_its_own_rate(): void
    {
        $usage = array_combine(Pricing::CATEGORIES, [7, 765, 1100, 201]);
        $rates = array_combine(Pricing::CATEGORIES, ['5', '10', '40', '12.50']);
        $this->assertSame([
            'provider_cost' => '0.054197500000', 'customer_charge' => '0.067746875000', 'profit' => '0.013549375000',
        ], (new Pricing)->bill($usage, $rates));
        $rates['output_tokens'] = '80';
        $this->assertSame('0.122746875000', (new Pricing)->bill($usage, $rates)['customer_charge']);
    }

    public function test_published_maximum_counts_unicode_characters(): void
    {
        $pricing = json_decode(file_get_contents(__DIR__.'/../Fixtures/pricing.json'), true, flags: JSON_THROW_ON_ERROR);
        $input = ['prompt' => str_repeat('🌊', 400), 'size' => '1536x1024', 'quality' => 'high', 'reference_images' => ['https://a.example', 'https://b.example']];
        $calculator = new Pricing;
        $this->assertSame('0.467343750000', $calculator->quote($input, $pricing)['max']);
        $this->assertSame('0.373875000000', $calculator->bill(array_combine(Pricing::CATEGORIES, [115, 1530, 6450, 8000]), $pricing['rates'])['provider_cost']);
    }
}
