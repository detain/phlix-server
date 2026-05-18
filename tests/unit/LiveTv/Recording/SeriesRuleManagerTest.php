<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\LiveTv\Recording;

use PHPUnit\Framework\TestCase;
use Phlex\LiveTv\Recording\SeriesRuleManager;
use Phlex\LiveTv\Recorder;
use Phlex\Common\Logger\StructuredLogger;
use Workerman\MySQL\Connection;

class SeriesRuleManagerTest extends TestCase
{
    private SeriesRuleManager $ruleManager;
    private $mockDb;
    private $mockRecorder;
    private $mockLogger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockDb = $this->createMock(Connection::class);
        $this->mockRecorder = $this->createMock(Recorder::class);
        $this->mockLogger = $this->createMock(StructuredLogger::class);

        $this->ruleManager = new SeriesRuleManager(
            $this->mockDb,
            $this->mockRecorder,
            $this->mockLogger
        );
    }

    public function testCanCreateRuleManager(): void
    {
        $this->assertInstanceOf(SeriesRuleManager::class, $this->ruleManager);
    }

    public function testCreateRuleCallsDbInsert(): void
    {
        $queryCount = 0;
        $mockResult = new class {
            public $num_rows = 1;
            public function fetch() {
                return [
                    'rule_id' => 'newly-created-rule',
                    'series_id' => 'series_123',
                    'channel_id' => 'ch_1',
                    'title' => 'Test Series',
                    'priority' => 5,
                    'pre_padding_seconds' => 60,
                    'post_padding_seconds' => 60,
                    'max_recordings' => null,
                    'days_ahead' => 14,
                    'is_active' => true,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ];
            }
        };

        $this->mockDb->expects($this->exactly(2))
            ->method('query')
            ->willReturn($mockResult);

        $rule = $this->ruleManager->createRule('series_123', 'ch_1', ['title' => 'Test Series']);

        $this->assertIsArray($rule);
        $this->assertEquals('newly-created-rule', $rule['rule_id']);
    }

    public function testGetRulesReturnsActiveRules(): void
    {
        // Mock empty result
        $mockResult = new class {
            public $num_rows = 0;
            public function fetch() { return false; }
        };

        $this->mockDb->expects($this->once())
            ->method('query')
            ->with($this->stringContains('WHERE is_active = 1'))
            ->willReturn($mockResult);

        $rules = $this->ruleManager->getRules();

        $this->assertIsArray($rules);
        $this->assertEmpty($rules);
    }

    public function testGetRuleBySeriesReturnsNullWhenNotFound(): void
    {
        $mockResult = new class {
            public $num_rows = 0;
            public function fetch() { return false; }
        };

        $this->mockDb->expects($this->once())
            ->method('query')
            ->willReturn($mockResult);

        $rule = $this->ruleManager->getRuleBySeries('nonexistent');

        $this->assertNull($rule);
    }

    public function testDeleteRuleReturnsFalseWhenNotFound(): void
    {
        $mockResult = new class {
            public $num_rows = 0;
            public function fetch() { return false; }
        };

        $this->mockDb->expects($this->once())
            ->method('query')
            ->willReturn($mockResult);

        $deleted = $this->ruleManager->deleteRule('nonexistent');

        $this->assertFalse($deleted);
    }

    public function testDeleteRuleCallsDbDelete(): void
    {
        $mockResultGet = new class {
            public $num_rows = 1;
            public function fetch() {
                return [
                    'rule_id' => 'rule_1',
                    'series_id' => 'series_123',
                    'channel_id' => 'ch_1',
                    'title' => 'Test Series',
                    'priority' => 5,
                    'pre_padding_seconds' => 60,
                    'post_padding_seconds' => 60,
                    'max_recordings' => null,
                    'days_ahead' => 14,
                    'is_active' => true,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ];
            }
        };

        $this->mockDb->expects($this->any())
            ->method('query')
            ->willReturn($mockResultGet);

        $deleted = $this->ruleManager->deleteRule('rule_1');

        $this->assertTrue($deleted);
    }

    public function testUpdateRuleReturnsNullWhenNotFound(): void
    {
        $mockResult = new class {
            public $num_rows = 0;
            public function fetch() { return false; }
        };

        $this->mockDb->expects($this->once())
            ->method('query')
            ->willReturn($mockResult);

        $updated = $this->ruleManager->updateRule('nonexistent', ['title' => 'New']);

        $this->assertNull($updated);
    }
}
