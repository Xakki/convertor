<?php

declare(strict_types=1);

namespace App\Service\Conversion\Settings;

/** Validates and parses the complete capability contract for API workers. */
final class ApiCapabilityContract
{
    /** @param array<string, mixed> $capabilities */
    public function validationError(array $capabilities): ?string
    {
        if (($capabilities['executionKind'] ?? null) !== 'api') {
            return 'executionKind must be "api" for api workers';
        }
        if (($capabilities['routingKeys'] ?? null) !== ['api']) {
            return 'routingKeys must contain only "api" for api workers';
        }
        if (($capabilities['streams'] ?? null) !== ['api']) {
            return 'streams must contain only "api" for api workers';
        }

        $model = $capabilities['settings']['model'] ?? null;
        if (! is_array($model)) {
            return 'settings.model must be an object for api workers';
        }
        if (! is_string($model['default'] ?? null) || $model['default'] === '') {
            return 'settings.model.default must be a non-empty string for api workers';
        }
        if (! is_array($model['choices'] ?? null)
            || ! array_is_list($model['choices'])
            || $model['choices'] === []) {
            return 'settings.model.choices must be a non-empty list for api workers';
        }

        $choiceValues = [];
        foreach ($model['choices'] as $choice) {
            if (! is_array($choice)
                || ! is_string($choice['value'] ?? null)
                || $choice['value'] === ''
                || ! is_string($choice['label'] ?? null)
                || $choice['label'] === '') {
                return 'settings.model.choices entries must have non-empty string value and label';
            }
            $choiceValues[$choice['value']] = true;
        }
        if (! isset($choiceValues[$model['default']])) {
            return 'settings.model.default must match a declared choice';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $capabilities
     *
     * @return array{default: string, choices: list<array{value: string, label: string}>}|null
     */
    public function model(array $capabilities): ?array
    {
        if ($this->validationError($capabilities) !== null) {
            return null;
        }

        /** @var array{default: string, choices: list<array{value: string, label: string}>} $model */
        $model = $capabilities['settings']['model'];

        return $model;
    }
}
