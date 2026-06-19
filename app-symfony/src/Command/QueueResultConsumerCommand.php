<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Queue\ConversionResultPersister;
use App\Service\Queue\RedisConnectionFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Long-running consumer of the `conv.result` stream (group `convertor`). Workers
 * emit results with `XADD conv.result * data <json>` — a single field `data`
 * holding the raw §5 result body (NOT a Messenger envelope), so we decode once.
 * Each result is persisted to MariaDB then XACK'd. Crash/claim recovery
 * (XAUTOCLAIM, delivery counts) is intentionally deferred to Phase 5 (card M).
 */
#[AsCommand(
    name: 'app:queue:result-consumer',
    description: 'Consume worker result events from conv.result and persist them to MariaDB',
)]
final class QueueResultConsumerCommand extends Command
{
    private const STREAM = 'conv.result';
    private const GROUP = 'convertor';

    public function __construct(
        private readonly RedisConnectionFactory $redisFactory,
        private readonly ConversionResultPersister $persister,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('consumer', null, InputOption::VALUE_REQUIRED, 'Consumer name within the group', 'php-result-1')
            ->addOption('time-limit', null, InputOption::VALUE_REQUIRED, 'Exit gracefully after N seconds (0 = forever)', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $redis = $this->redisFactory->create();
        $consumer = (string) $input->getOption('consumer');
        $timeLimit = (int) $input->getOption('time-limit');
        $deadline = $timeLimit > 0 ? time() + $timeLimit : null;

        $this->ensureGroup($redis);
        $output->writeln(sprintf('<info>Consuming %s as %s/%s</info>', self::STREAM, self::GROUP, $consumer));

        while ($deadline === null || time() < $deadline) {
            /** @var array<string, array<string, array<string, string>>>|false $messages */
            $messages = $redis->xReadGroup(self::GROUP, $consumer, [self::STREAM => '>'], 10, 5000);

            if (!is_array($messages) || !isset($messages[self::STREAM])) {
                continue;
            }

            foreach ($messages[self::STREAM] as $id => $fields) {
                try {
                    $this->handle($fields);
                    $redis->xAck(self::STREAM, self::GROUP, [$id]);
                } catch (\Throwable $e) {
                    // Leave the entry pending; Phase 5 adds claim-based recovery.
                    $this->logger->error('Failed to persist result event', [
                        'stream_id' => $id,
                        'error'     => $e->getMessage(),
                    ]);
                }
            }
        }

        return Command::SUCCESS;
    }

    private function ensureGroup(\Redis $redis): void
    {
        try {
            // MKSTREAM so the group exists even before the first worker writes.
            $redis->xGroup('CREATE', self::STREAM, self::GROUP, '0', true);
        } catch (\RedisException $e) {
            if (!str_contains($e->getMessage(), 'BUSYGROUP')) {
                throw $e;
            }
        }
    }

    /**
     * @param array<string, string> $fields
     */
    private function handle(array $fields): void
    {
        $raw = $fields['data'] ?? null;
        if ($raw === null || $raw === '') {
            throw new \RuntimeException('Result entry missing "data" field');
        }

        /** @var array<string, mixed> $body */
        $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        $this->persister->persist($body);
    }
}
