<?php

declare(strict_types=1);

namespace App\Service\Admin;

/**
 * Разбор текстового формата экспозиции Prometheus (`/metrics`) в структуру
 * «имя метрики → список сэмплов». Чистый парсер без I/O — тестируется на
 * фикстуре без живого exporter'а (эпик admin-panel, подзадача queues).
 *
 * Осознанно НЕ строит полноценную модель Prometheus: игнорирует строки
 * `# HELP`/`# TYPE`, необязательный timestamp и `NaN`/`Inf`-значения; парсит
 * метки порядок-независимо. Достаточно для gauge-метрик `convertor_stream_*`.
 */
final class PrometheusMetricsParser
{
    /**
     * `metric_name{label="v",...} value [timestamp]` или `metric_name value`.
     */
    private const string SAMPLE_RE = '/^(?<name>[a-zA-Z_:][a-zA-Z0-9_:]*)(?:\{(?<labels>[^}]*)\})?\s+(?<value>\S+)/';

    /** Одна пара `key="value"` внутри фигурных скобок (значение может быть экранировано). */
    private const string LABEL_RE = '/([a-zA-Z_][a-zA-Z0-9_]*)="((?:\\\\.|[^"\\\\])*)"/';

    /**
     * @return array<string, list<array{labels: array<string, string>, value: float}>>
     */
    public function parse(string $text): array
    {
        $out = [];
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            // Пропускаем пустые строки и комментарии (# HELP / # TYPE).
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (preg_match(self::SAMPLE_RE, $line, $m) !== 1) {
                continue;
            }
            $value = $this->parseValue($m['value']);
            if ($value === null) {
                continue; // NaN / +Inf / -Inf / мусор — не число.
            }
            $name         = $m['name'];
            $out[$name][] = [
                // 'labels' — необязательная именованная группа; за ней всегда идёт
                // сматчившаяся 'value', поэтому ключ всегда присутствует ('' без скобок).
                'labels' => $this->parseLabels($m['labels']),
                'value'  => $value,
            ];
        }

        return $out;
    }

    private function parseValue(string $raw): ?float
    {
        if (! is_numeric($raw)) {
            return null;
        }

        return (float) $raw;
    }

    /**
     * @return array<string, string>
     */
    private function parseLabels(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $labels = [];
        if (preg_match_all(self::LABEL_RE, $raw, $matches, PREG_SET_ORDER) !== false) {
            foreach ($matches as $pair) {
                $labels[$pair[1]] = $this->unescape($pair[2]);
            }
        }

        return $labels;
    }

    /** Разэкранировать значение метки по правилам Prometheus (\\, \", \n). */
    private function unescape(string $value): string
    {
        return strtr($value, ['\\\\' => '\\', '\\"' => '"', '\\n' => "\n"]);
    }
}
