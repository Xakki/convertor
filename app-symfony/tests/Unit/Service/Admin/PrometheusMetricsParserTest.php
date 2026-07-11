<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Admin;

use App\Service\Admin\PrometheusMetricsParser;
use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты чистого парсера текстового формата Prometheus (без живого
 * exporter'а). Проверяет: пропуск # HELP/# TYPE-строк и default-коллекторов,
 * порядок-независимый разбор меток, безлейбловую метрику, отсев NaN/Inf.
 */
final class PrometheusMetricsParserTest extends TestCase
{
    private PrometheusMetricsParser $parser;

    protected function setUp(): void
    {
        $this->parser = new PrometheusMetricsParser();
    }

    public function testParsesLabeledAndUnlabeledSamplesSkippingComments(): void
    {
        $text = <<<'PROM'
            # HELP convertor_stream_length Total number of entries in a conv.* stream (XLEN).
            # TYPE convertor_stream_length gauge
            convertor_stream_length{stream="conv.document"} 5.0
            convertor_stream_length{stream="conv.dead"} 2.0
            # TYPE python_gc_objects_collected_total counter
            python_gc_objects_collected_total{generation="0"} 123.0
            convertor_dead_letter_messages 2.0
            convertor_exporter_up 1.0

            PROM;

        $result = $this->parser->parse($text);

        // Обе строки одной метрики собраны в список.
        self::assertCount(2, $result['convertor_stream_length']);
        self::assertSame('conv.document', $result['convertor_stream_length'][0]['labels']['stream']);
        self::assertSame(5.0, $result['convertor_stream_length'][0]['value']);

        // Безлейбловые метрики: labels = [].
        self::assertSame([], $result['convertor_dead_letter_messages'][0]['labels']);
        self::assertSame(2.0, $result['convertor_dead_letter_messages'][0]['value']);
        self::assertSame(1.0, $result['convertor_exporter_up'][0]['value']);

        // Default-коллектор всё же парсится (парсер общий), но потребитель его игнорит.
        self::assertArrayHasKey('python_gc_objects_collected_total', $result);
    }

    public function testLabelsAreParsedOrderIndependently(): void
    {
        // group идёт ПЕРЕД stream — порядок не должен ломать разбор.
        $text = 'convertor_stream_group_pending{group="convertor",stream="conv.image"} 3.0';

        $result = $this->parser->parse($text);
        $labels = $result['convertor_stream_group_pending'][0]['labels'];

        self::assertSame('conv.image', $labels['stream']);
        self::assertSame('convertor', $labels['group']);
    }

    public function testSkipsNonNumericValues(): void
    {
        $text = <<<'PROM'
            some_metric NaN
            another_metric +Inf
            good_metric 7
            PROM;

        $result = $this->parser->parse($text);

        self::assertArrayNotHasKey('some_metric', $result);
        self::assertArrayNotHasKey('another_metric', $result);
        self::assertSame(7.0, $result['good_metric'][0]['value']);
    }
}
