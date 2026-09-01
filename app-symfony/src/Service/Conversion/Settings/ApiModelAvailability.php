<?php

declare(strict_types=1);

namespace App\Service\Conversion\Settings;

use App\Entity\WorkerCapability;
use App\Repository\WorkerCapabilityRepository;

/** Resolves the public model contract shared by /formats and POST validation. */
final class ApiModelAvailability
{
    public function __construct(
        private readonly WorkerCapabilityRepository $repository,
        private readonly ApiCapabilityContract $contract = new ApiCapabilityContract(),
    ) {
    }

    /** @return array{default: string, choices: list<array{value: string, label: string}>}|null */
    public function current(): ?array
    {
        $capabilities = $this->repository->findLiveForWorkerType('api');
        usort(
            $capabilities,
            static fn (WorkerCapability $left, WorkerCapability $right): int => $left->getInstanceId() <=> $right->getInstanceId(),
        );

        $choiceMaps = [];
        $defaults   = [];
        foreach ($capabilities as $capability) {
            $model = $this->contract->model($capability->getCapabilities());
            if ($model === null) {
                return null;
            }

            $choices = [];
            foreach ($model['choices'] as $choice) {
                $choices[$choice['value']] = $choice['label'];
            }

            $choiceMaps[] = $choices;
            $defaults[]   = $model['default'];
        }

        if ($choiceMaps === []) {
            return null;
        }

        $common = array_shift($choiceMaps);
        foreach ($choiceMaps as $choices) {
            $common = array_intersect_key($common, $choices);
        }
        if ($common === []) {
            return null;
        }

        $default = null;
        foreach ($defaults as $candidate) {
            if (isset($common[$candidate])) {
                $default = $candidate;
                break;
            }
        }
        if ($default === null) {
            return null;
        }

        $choices = [];
        foreach ($common as $value => $label) {
            $choices[] = ['value' => $value, 'label' => $label];
        }

        return ['default' => $default, 'choices' => $choices];
    }
}
