<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\QueueResultConsumerCommand;
use App\Entity\Conversion;
use App\Enum\ConversionStatus;
use App\Service\Queue\ConversionResultPersister;
use App\Service\Queue\RedisConnectionFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Verifies that a single poison message does not wedge the long-running consumer:
 * the bad entry is dead-lettered, ACK'd, the EM is reset if it closed, and the
 * next message is processed successfully.
 *
 * Discriminating check: without the fix the second message also fails (stale EM),
 * so xAdd would be called twice. With the fix it is called exactly once.
 */
final class QueueResultConsumerCommandTest extends TestCase
{
    public function testPoisonMessageIsDlqdAndNextMessageSucceeds(): void
    {
        // -- Entities / EMs -------------------------------------------------------

        // Reusable non-terminal conversion stub for both messages.
        $conversion = $this->createStub(Conversion::class);
        $conversion->method('getStatus')->willReturn(ConversionStatus::Processing);

        // em1: find() succeeds once; flush() throws → EM closes.
        $em1       = $this->createStub(EntityManagerInterface::class);
        $findCalls = 0;
        $em1->method('find')->willReturnCallback(function () use ($conversion, &$findCalls) {
            if (++$findCalls > 1) {
                // Simulate closed-EM behaviour on any attempt after the error.
                throw new \RuntimeException('EntityManager is closed');
            }

            return $conversion;
        });
        $em1->method('flush')->willThrowException(new \RuntimeException('DB connection lost'));
        $em1->method('isOpen')->willReturn(false);

        // em2: returned after resetManager(). find() + flush() both succeed (void, no willReturn).
        $em2 = $this->createStub(EntityManagerInterface::class);
        $em2->method('find')->willReturn($conversion);
        // flush() is void — no willReturn; stub returns null by default, which is correct.

        // -- Registry -------------------------------------------------------------
        // Calls: 1 (persist msg1) → em1, 2 (resetEmIfClosed check) → em1 (isOpen=false → reset),
        //        3+ (persist msg2) → em2.
        $getManagerCount = 0;
        $registry        = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturnCallback(
            function () use ($em1, $em2, &$getManagerCount) {
                return ++$getManagerCount <= 2 ? $em1 : $em2;
            }
        );
        $registry->expects($this->once())->method('resetManager');

        // -- Persister (real, driven by mocked registry) --------------------------
        $persister = new ConversionResultPersister($registry, 'test-results', new NullLogger());

        // -- Redis mock -----------------------------------------------------------
        $redis = $this->createMock(\Redis::class);
        $redis->method('xGroup')->willReturn(true);

        // Both messages use the state:"failed" path — shortest route to flush().
        $firstRead = true;
        $redis->method('xReadGroup')->willReturnCallback(function () use (&$firstRead) {
            if ($firstRead) {
                $firstRead = false;

                return [
                    'conv.result' => [
                        '1-0' => ['data' => '{"conversionId":1,"state":"failed","error":"worker crash","processingMs":500}'],
                        '2-0' => ['data' => '{"conversionId":2,"state":"failed","error":"worker crash","processingMs":400}'],
                    ],
                ];
            }

            return false;
        });

        // Both messages must be ACK'd — even the bad one.
        $redis->expects($this->exactly(2))->method('xAck');

        // Exactly one DLQ write (message 1 only — message 2 succeeds, no DLQ).
        $redis->expects($this->once())->method('xAdd')
            ->with(
                'conv.result.dead',
                '*',
                $this->callback(fn (array $f) => ($f['_original_id'] ?? '') === '1-0'),
            );

        // -- Factory (anonymous subclass — avoids mocking a non-final class unnecessarily) --
        $factory = new class ($redis) extends RedisConnectionFactory {
            public function __construct(private \Redis $mockRedis)
            {
                parent::__construct('redis://localhost:6379?dbindex=0');
            }

            public function create(): \Redis
            {
                return $this->mockRedis;
            }
        };

        // -- Run ------------------------------------------------------------------
        $command = new QueueResultConsumerCommand($factory, $persister, $registry, new NullLogger());
        $tester  = new CommandTester($command);
        $tester->execute(['--time-limit' => '1']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }
}
