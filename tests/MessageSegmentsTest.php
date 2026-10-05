<?php

namespace Nugsoft\SignalBridge\Tests;

use Nugsoft\SignalBridge\Support\MessageSegments;
use PHPUnit\Framework\TestCase;

/**
 * These cases mirror the gateway's own segment tests. The two must agree
 * character for character: when they diverge, estimateCost() quotes a price
 * the invoice does not match.
 */
class MessageSegmentsTest extends TestCase
{
    public function test_an_empty_message_is_one_segment(): void
    {
        $this->assertSame(1, MessageSegments::count(''));
    }

    public function test_gsm_messages_split_at_160_and_then_153(): void
    {
        $this->assertSame(1, MessageSegments::count(str_repeat('a', 160)));
        $this->assertSame(2, MessageSegments::count(str_repeat('a', 161)));
        $this->assertSame(2, MessageSegments::count(str_repeat('a', 306)));
        $this->assertSame(3, MessageSegments::count(str_repeat('a', 307)));
    }

    public function test_unicode_messages_split_at_70_and_then_67(): void
    {
        $this->assertSame(1, MessageSegments::count(str_repeat('ж', 70)));
        $this->assertSame(2, MessageSegments::count(str_repeat('ж', 71)));
        $this->assertSame(2, MessageSegments::count(str_repeat('ж', 134)));
        $this->assertSame(3, MessageSegments::count(str_repeat('ж', 135)));
    }

    public function test_a_newline_does_not_turn_a_plain_message_into_unicode(): void
    {
        $this->assertSame(1, MessageSegments::count("line one\nline two"));
        $this->assertSame(1, MessageSegments::count(str_repeat('a', 150)."\n".str_repeat('b', 9)));
    }

    public function test_a_carriage_return_is_also_plain_text(): void
    {
        $this->assertSame(1, MessageSegments::count("line one\r\nline two"));
    }

    public function test_template_placeholders_stay_gsm(): void
    {
        $this->assertSame(1, MessageSegments::count('Hello {name}, your code is [1234]'));
        $this->assertSame(1, MessageSegments::count(str_repeat('{', 160)));
    }

    public function test_emoji_count_as_two_units_because_they_sit_outside_the_bmp(): void
    {
        // 35 emoji are 70 UTF-16 code units: exactly one segment.
        $this->assertSame(1, MessageSegments::count(str_repeat('😀', 35)));
        $this->assertSame(2, MessageSegments::count(str_repeat('😀', 36)));
    }

    public function test_characters_inside_the_bmp_count_as_one_unit(): void
    {
        $this->assertSame(1, MessageSegments::count(str_repeat('ä', 70)));
    }

    public function test_the_cost_estimate_multiplies_segments_by_the_rate(): void
    {
        $this->assertSame(150.0, MessageSegments::estimateCost(str_repeat('a', 161), 75.0));
        $this->assertSame(75.0, MessageSegments::estimateCost('Hello', 75.0));
    }

    public function test_the_alphabet_matches_the_gateway_byte_for_byte(): void
    {
        // The gateway's BalanceService::GSM_7BIT_CHARSET, written out here so a
        // change on either side fails this test rather than a client's invoice.
        $gateway = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞ^{}\\[]~€|ÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

        $this->assertSame($gateway, MessageSegments::GSM_7BIT_CHARSET);
    }
}
