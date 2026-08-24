<?php

declare(strict_types=1);

namespace App\Service\Conversion\Settings;

/**
 * Профиль настроек — ИМЕНОВАННЫЙ набор полей, на который ссылается любое число
 * пар `from→to` (CNV-85). Дедупликация каталога держится именно на этом: в
 * `GET /formats` профиль сериализуется ОДИН раз, а 398 пар несут только его id.
 */
final class SettingsProfile
{
    /**
     * @param list<SettingsField> $fields
     */
    private function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly array $fields,
    ) {
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @throws \RuntimeException при некорректном описании профиля в каталоге
     */
    public static function fromArray(string $id, array $raw): self
    {
        if (preg_match('/^[a-z][a-z0-9]*(?:\.[a-z0-9]+)*$/', $id) !== 1) {
            throw new \RuntimeException("Settings catalog: invalid profile id \"{$id}\" (expected dot.separated.lowercase)");
        }

        $unknown = array_diff(array_keys($raw), ['label', 'fields']);
        if ($unknown !== []) {
            throw new \RuntimeException("Settings catalog: profile \"{$id}\" — unknown keys: " . implode(', ', $unknown));
        }

        $label = $raw['label'] ?? null;
        if (! is_string($label) || $label === '') {
            throw new \RuntimeException("Settings catalog: profile \"{$id}\" — `label` must be a non-empty string");
        }

        $fieldsRaw = $raw['fields'] ?? null;
        if (! is_array($fieldsRaw) || $fieldsRaw === []) {
            throw new \RuntimeException("Settings catalog: profile \"{$id}\" — `fields` must be a non-empty list");
        }

        $fields = [];
        $seen   = [];
        foreach ($fieldsRaw as $fieldRaw) {
            if (! is_array($fieldRaw)) {
                throw new \RuntimeException("Settings catalog: profile \"{$id}\" — every field must be an object");
            }
            /** @var array<string, mixed> $fieldRaw */
            $field = SettingsField::fromArray($fieldRaw, $id);
            if (isset($seen[$field->key])) {
                throw new \RuntimeException("Settings catalog: profile \"{$id}\" — duplicate field key \"{$field->key}\"");
            }
            $seen[$field->key] = true;
            $fields[]          = $field;
        }

        return new self($id, $label, $fields);
    }

    public function field(string $key): ?SettingsField
    {
        foreach ($this->fields as $field) {
            if ($field->key === $key) {
                return $field;
            }
        }

        return null;
    }

    /** @return array{id: string, label: string, fields: list<array<string, mixed>>} */
    public function toArray(SettingsAccessLevel $level): array
    {
        return [
            'id'     => $this->id,
            'label'  => $this->label,
            'fields' => array_map(
                static fn (SettingsField $f): array => $f->toArray($level),
                $this->fields,
            ),
        ];
    }
}
