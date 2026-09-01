<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Conversion\Settings;

use App\Entity\WorkerCapability;
use App\Repository\WorkerCapabilityRepository;
use App\Service\Conversion\Settings\ApiModelAvailability;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApiModelAvailabilityTest extends TestCase
{
    public function testIntersectsChoicesAndUsesAValidatedLiveDefaultDeterministically(): void
    {
        $repo = $this->createMock(WorkerCapabilityRepository::class);
        $repo->expects(self::once())->method('findLiveForWorkerType')->with('api')->willReturn([
            $this->capability('api-b', 'balanced', [
                ['value' => 'fast', 'label' => 'Fast from B'],
                ['value' => 'balanced', 'label' => 'Balanced'],
            ]),
            $this->capability('api-a', 'fast', [
                ['value' => 'fast', 'label' => 'Fast'],
                ['value' => 'other', 'label' => 'Other'],
            ]),
        ]);

        self::assertSame(
            ['default' => 'fast', 'choices' => [['value' => 'fast', 'label' => 'Fast']]],
            (new ApiModelAvailability($repo))->current(),
        );
    }

    public function testReturnsNullWithoutLiveValidatedApiRegistration(): void
    {
        $repo = $this->createStub(WorkerCapabilityRepository::class);
        $repo->method('findLiveForWorkerType')->willReturn([]);

        self::assertNull((new ApiModelAvailability($repo))->current());
    }

    public function testReturnsNullWhenAnyFreshAliveRegistrationHasInvalidModelContract(): void
    {
        $repo = $this->createStub(WorkerCapabilityRepository::class);
        $repo->method('findLiveForWorkerType')->willReturn([
            $this->capability('api-valid', 'fast', [['value' => 'fast', 'label' => 'Fast']]),
            new WorkerCapability('api', 'api-invalid', [
                'executionKind' => 'api',
                'routingKeys'   => ['api'],
                'streams'       => ['api'],
                'settings'      => [
                    'model' => [
                        'default' => 'fast',
                        'choices' => [['value' => 'fast']],
                    ],
                ],
            ]),
        ]);

        self::assertNull((new ApiModelAvailability($repo))->current());
    }

    /** @param array<string, mixed> $invalidCapabilities */
    #[DataProvider('invalidCompleteContractProvider')]
    public function testReturnsNullWhenAnyFreshAliveRegistrationViolatesCompleteContract(array $invalidCapabilities): void
    {
        $repo = $this->createStub(WorkerCapabilityRepository::class);
        $repo->method('findLiveForWorkerType')->willReturn([
            $this->capability('api-valid', 'fast', [['value' => 'fast', 'label' => 'Fast']]),
            new WorkerCapability('api', 'api-invalid', $invalidCapabilities),
        ]);

        self::assertNull((new ApiModelAvailability($repo))->current());
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function invalidCompleteContractProvider(): iterable
    {
        $valid = self::completeCapabilities();

        yield 'executionKind missing' => [array_diff_key($valid, ['executionKind' => true])];
        yield 'executionKind wrong' => [array_replace($valid, ['executionKind' => 'worker'])];
        yield 'routingKeys missing' => [array_diff_key($valid, ['routingKeys' => true])];
        yield 'routingKeys not exclusive' => [array_replace($valid, ['routingKeys' => ['api', 'ai']])];
        yield 'streams missing' => [array_diff_key($valid, ['streams' => true])];
        yield 'streams not exclusive' => [array_replace($valid, ['streams' => ['ai']])];
        yield 'settings model missing' => [array_replace($valid, ['settings' => []])];
        yield 'model default missing' => [array_replace_recursive($valid, ['settings' => ['model' => ['default' => null]]])];

        $emptyChoices                                 = $valid;
        $emptyChoices['settings']['model']['choices'] = [];
        yield 'model choices empty' => [$emptyChoices];

        $associativeChoices                                 = $valid;
        $associativeChoices['settings']['model']['choices'] = [
            'fast' => ['value' => 'fast', 'label' => 'Fast'],
        ];
        yield 'model choices associative' => [$associativeChoices];

        $malformedChoice                                 = $valid;
        $malformedChoice['settings']['model']['choices'] = [['value' => 'fast']];
        yield 'model choice malformed' => [$malformedChoice];

        yield 'model default undeclared' => [array_replace_recursive($valid, ['settings' => ['model' => ['default' => 'other']]])];
    }

    /** @param list<array{value: string, label: string}> $choices */
    private function capability(string $instanceId, string $default, array $choices): WorkerCapability
    {
        $capabilities                      = self::completeCapabilities();
        $capabilities['settings']['model'] = ['default' => $default, 'choices' => $choices];

        return new WorkerCapability('api', $instanceId, $capabilities);
    }

    /** @return array<string, mixed> */
    private static function completeCapabilities(): array
    {
        return [
            'executionKind' => 'api',
            'routingKeys'   => ['api'],
            'streams'       => ['api'],
            'settings'      => ['model' => [
                'default' => 'fast',
                'choices' => [['value' => 'fast', 'label' => 'Fast']],
            ]],
        ];
    }
}
