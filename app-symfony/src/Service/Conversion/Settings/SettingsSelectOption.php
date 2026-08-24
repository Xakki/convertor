<?php

declare(strict_types=1);

namespace App\Service\Conversion\Settings;

/**
 * Один вариант поля типа `select` (CNV-85). Собственный `minPlan` позволяет
 * гейтить ОТДЕЛЬНОЕ значение, а не всё поле целиком (карточка: «отклонять
 * plan-недоступные значения»): напр. пресет качества `ultra` только для `pro`,
 * при том что само поле доступно с `free`.
 */
final class SettingsSelectOption
{
    private function __construct(
        public readonly string $value,
        public readonly string $label,
        public readonly SettingsAccessLevel $minPlan,
    ) {
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @throws \RuntimeException при некорректных данных каталога (громко, не тихий пропуск)
     */
    public static function fromArray(array $raw, string $where): self
    {
        $value = $raw['value'] ?? null;
        if (! is_string($value) || $value === '') {
            throw new \RuntimeException("Settings catalog: {$where} — select option must declare a non-empty string `value`");
        }

        $label = $raw['label'] ?? $value;
        if (! is_string($label)) {
            throw new \RuntimeException("Settings catalog: {$where}.{$value} — `label` must be a string");
        }

        // Guest-политика (см. CLAUDE.md): та же обязательность `minPlan`, что и
        // у поля {@see SettingsField} целиком — вариант select гейтится по
        // стоимости, implicit default тихо ошибается в любую сторону.
        if (! array_key_exists('minPlan', $raw)) {
            throw new \RuntimeException("Settings catalog: {$where}.{$value} — `minPlan` is required (choose by cost, no implicit default)");
        }
        $minPlanRaw = $raw['minPlan'];
        if (! is_string($minPlanRaw)) {
            throw new \RuntimeException("Settings catalog: {$where}.{$value} — `minPlan` must be a string");
        }
        $minPlan = SettingsAccessLevel::tryFrom($minPlanRaw);
        if ($minPlan === null) {
            throw new \RuntimeException("Settings catalog: {$where}.{$value} — unknown `minPlan` \"{$minPlanRaw}\"");
        }

        $unknown = array_diff(array_keys($raw), ['value', 'label', 'minPlan']);
        if ($unknown !== []) {
            throw new \RuntimeException("Settings catalog: {$where}.{$value} — unknown select-option keys: " . implode(', ', $unknown));
        }

        return new self($value, $label, $minPlan);
    }

    public function isEditableFor(SettingsAccessLevel $level): bool
    {
        return $level->isAtLeast($this->minPlan);
    }

    /** @return array{value: string, label: string, minPlan: string, editable: bool} */
    public function toArray(SettingsAccessLevel $level): array
    {
        return [
            'value'    => $this->value,
            'label'    => $this->label,
            'minPlan'  => $this->minPlan->value,
            'editable' => $this->isEditableFor($level),
        ];
    }
}
