<?php

declare(strict_types=1);

namespace BGalati\MonologSentryHandler\Tests;

use BGalati\MonologSentryHandler\SentryHandler;
use Coduo\PHPMatcher\PHPUnit\PHPMatcherAssertions;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use Monolog\Formatter\LineFormatter;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Sentry\Breadcrumb;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\Integration\EnvironmentIntegration;
use Sentry\Options;
use Sentry\Response;
use Sentry\ResponseStatus;
use Sentry\SentrySdk;
use Sentry\Severity;
use Sentry\State\Hub;
use Sentry\State\Scope;
use Sentry\Transport\TransportFactoryInterface;
use Sentry\Transport\TransportInterface;

class SentryHandlerTest extends TestCase
{
    use PHPMatcherAssertions;

    /**
     * @var Hub
     */
    private $hub;

    /**
     * @var SpyTransport
     */
    private $transport;

    protected function setUp(): void
    {
        $this->transport = new SpyTransport();

        $clientBuilder = ClientBuilder::create(
            [
                'default_integrations' => false,
                'integrations' => [
                    // In order to get OS and runtime context we must enable this
                    new EnvironmentIntegration(),
                ],
            ]
        );
        $clientBuilder->setTransportFactory(new FakeTransportFactory($this->transport));

        $client = $clientBuilder->getClient();

        $this->hub = SentrySdk::getCurrentHub();
        $this->hub->bindClient($client);
    }

    protected function tearDown(): void
    {
        unset($this->hub, $this->transport);
    }

    public function testHandle(): void
    {
        $handler = $this->createSentryHandler();

        $record = [
            'message'    => 'My info message',
            'context'    => [],
            'level'      => Logger::INFO,
            'level_name' => Logger::getLevelName(Logger::INFO),
            'channel'    => 'app',
            'extra'      => [],
        ];

        $handler->handle($record);

        $this->assertTrue($handler->afterWriteCalled);
        $this->assertCapturedEvent(
            Severity::info(),
            'app.INFO: My info message',
            ['monolog.formatted' => 'app.INFO: My info message []']
        );
    }

    public function testHandleCaptureException(): void
    {
        $handler = $this->createSentryHandler();

        $record = [
            'message'    => 'My info message',
            'context'    => ['exception' => $exception = new \LogicException('Test logic exception')],
            'level'      => Logger::INFO,
            'level_name' => Logger::getLevelName(Logger::INFO),
            'channel'    => 'app',
            'extra'      => [],
        ];

        $handler->handle($record);

        $this->assertTrue($handler->afterWriteCalled);
        $this->assertCapturedEvent(
            Severity::info(),
            'app.INFO: My info message',
            ['monolog.formatted' => 'app.INFO: My info message []'],
            $exception
        );
    }

    public function testHandleBatchDoesNotCallSentryIfNoRecordsAreProvided(): void
    {
        $handler = $this->createSentryHandler();
        $handler->handleBatch([]);

        $this->assertFalse($handler->afterWriteCalled);
        $this->assertNull($this->transport->spiedEvent);
    }

    public function testHandleBatch(): void
    {
        $handler = $this->createSentryHandler();

        $records = [
            [
                'message'    => 'Info message',
                'context'    => ['exception' => new \LogicException()],
                'level'      => $level = Logger::INFO,
                'level_name' => Logger::getLevelName($level),
                'channel'    => 'chan-info',
                'extra'      => ['extra-info'],
            ],
            [
                'message'    => 'Error Message',
                'context'    => [],
                'level'      => $level = Logger::ERROR,
                'level_name' => Logger::getLevelName($level),
                'channel'    => 'chan-error',
                'extra'      => ['extra-error'],
            ],
            [
                'message'    => 'Debug message',
                'context'    => [],
                'level'      => $level = Logger::DEBUG,
                'level_name' => Logger::getLevelName($level),
                'channel'    => 'chan-debug',
                'extra'      => ['extra-debug'],
            ],
            [
                'message'    => 'Emergency message',
                'context'    => [],
                'level'      => $level = Logger::EMERGENCY,
                'level_name' => Logger::getLevelName($level),
                'channel'    => 'chan-emerg',
                'extra'      => ['extra-emerg'],
            ],
            [
                'message'    => 'Warning message',
                'context'    => [],
                'level'      => $level = Logger::WARNING,
                'level_name' => Logger::getLevelName($level),
                'channel'    => 'chan-warn',
                'extra'      => ['extra-warn'],
            ],
            [
                'message'    => 'Notice message',
                'context'    => [],
                'level'      => $level = Logger::NOTICE,
                'level_name' => Logger::getLevelName($level),
                'channel'    => 'chan-notice',
                'extra'      => ['extra-notice'],
            ],
            [
                'message'    => 'Alert message',
                'context'    => [],
                'level'      => $level = Logger::ALERT,
                'level_name' => Logger::getLevelName($level),
                'channel'    => 'chan-alert',
                'extra'      => ['extra-alert'],
            ],
            [
                'message'    => 'Critical message',
                'context'    => ['exception' => new \LogicException()],
                'level'      => $level = Logger::CRITICAL,
                'level_name' => Logger::getLevelName($level),
                'channel'    => 'chan-critical',
                'extra'      => ['extra-critical'],
            ],
        ];

        $handler->handleBatch($records);

        $this->assertTrue($handler->afterWriteCalled);
        $this->assertCapturedEvent(
            Severity::fatal(),
            'chan-emerg.EMERGENCY: Emergency message',
            ['monolog.formatted' => 'chan-emerg.EMERGENCY: Emergency message ["extra-emerg"]'],
            null,
            [
                [
                    'type'      => 'default',
                    'category'  => 'chan-info',
                    'level'     => 'info',
                    'message'   => 'Info message',
                    'timestamp' => '@double@',
                    'data'      => [
                        'exception' => '@*@',
                        "extra-info"
                    ],
                ],
                [
                    'type'      => 'error',
                    'category'  => 'chan-error',
                    'level'     => 'error',
                    'message'   => 'Error Message',
                    'timestamp' => '@double@',
                    'data'      => ["extra-error"],
                ],
                [
                    'type'      => 'default',
                    'category'  => 'chan-debug',
                    'level'     => 'debug',
                    'message'   => 'Debug message',
                    'timestamp' => '@double@',
                    'data'      => ["extra-debug"],
                ],
                [
                    'type'      => 'error',
                    'category'  => 'chan-emerg',
                    'level'     => 'fatal',
                    'message'   => 'Emergency message',
                    'timestamp' => '@double@',
                    'data'      => ["extra-emerg"],
                ],
                [
                    'type'      => 'default',
                    'category'  => 'chan-warn',
                    'level'     => 'warning',
                    'message'   => 'Warning message',
                    'timestamp' => '@double@',
                    'data'      => ["extra-warn"],
                ],
                [
                    'type'      => 'default',
                    'category'  => 'chan-notice',
                    'level'     => 'info',
                    'message'   => 'Notice message',
                    'timestamp' => '@double@',
                    'data'      => ["extra-notice"],
                ],
                [
                    'type'      => 'error',
                    'category'  => 'chan-alert',
                    'level'     => 'fatal',
                    'message'   => 'Alert message',
                    'timestamp' => '@double@',
                    'data'      => ["extra-alert"],
                ],
                [
                    'type'      => 'error',
                    'category'  => 'chan-critical',
                    'level'     => 'fatal',
                    'message'   => 'Critical message',
                    'timestamp' => '@double@',
                    'data'      => [
                        'exception' => '@*@',
                        "extra-critical"
                    ],
                ],
            ]
        );
    }

    public function testHandleBatchFiltersRecordsByLevel(): void
    {
        $handler = $this->createSentryHandler(Logger::WARNING);

        $records = [
            [
                'message'    => 'Info message',
                'context'    => ['exception' => new \LogicException()],
                'level'      => $level = Logger::INFO,
                'level_name' => Logger::getLevelName($level),
                'channel'    => 'test',
                'extra'      => [],
            ],
            [
                'message'    => 'Error Message',
                'context'    => [],
                'level'      => $level = Logger::ERROR,
                'level_name' => Logger::getLevelName($level),
                'channel'    => 'test',
                'extra'      => [],
            ],
            [
                'message'    => 'Debug message',
                'context'    => [],
                'level'      => $level = Logger::DEBUG,
                'level_name' => Logger::getLevelName($level),
                'channel'    => 'test',
                'extra'      => [],
            ],
            [
                'message'    => 'Warning message',
                'context'    => [],
                'level'      => $level = Logger::WARNING,
                'level_name' => Logger::getLevelName($level),
                'channel'    => 'test',
                'extra'      => [],
            ],
            [
                'message'    => 'Notice message',
                'context'    => [],
                'level'      => $level = Logger::NOTICE,
                'level_name' => Logger::getLevelName($level),
                'channel'    => 'test',
                'extra'      => [],
            ],
            [
                'message'    => 'Critical message',
                'context'    => ['exception' => $exception = new \LogicException('Exception of critical level')],
                'level'      => $level = Logger::CRITICAL,
                'level_name' => Logger::getLevelName($level),
                'channel'    => 'test',
                'extra'      => [],
            ],
        ];

        $handler->handleBatch($records);

        $this->assertTrue($handler->afterWriteCalled);
        $this->assertCapturedEvent(
            Severity::fatal(),
            'test.CRITICAL: Critical message',
            ['monolog.formatted' => 'test.CRITICAL: Critical message []'],
            $exception,
            [
                [
                    'type'      => 'error',
                    'category'  => 'test',
                    'level'     => 'error',
                    'message'   => 'Error Message',
                    'timestamp' => '@double@',
                    'data'      => [],
                ],
                [
                    'type'      => 'default',
                    'category'  => 'test',
                    'level'     => 'warning',
                    'message'   => 'Warning message',
                    'timestamp' => '@double@',
                    'data'      => [],
                ],
                [
                    'type'      => 'error',
                    'category'  => 'test',
                    'level'     => 'fatal',
                    'message'   => 'Critical message',
                    'timestamp' => '@double@',
                    'data'      => [
                        'exception' => '@*@'
                    ],
                ],
            ]
        );
    }

    public function testHandleBatchCanBeCalledTwiceWithoutSideEffects(): void
    {
        $handler = $this->createSentryHandler();

        $records = [
            [
                'message'    => 'Info message',
                'context'    => [],
                'level'      => $level = Logger::INFO,
                'level_name' => Logger::getLevelName($level),
                'channel'    => 'test',
                'extra'      => [],
            ],
        ];

        $handler->handleBatch($records);

        $this->assertTrue($handler->afterWriteCalled);

        $handler->resetSpy();
        $this->transport->resetSpy();

        $handler->handleBatch($records);

        $this->assertTrue($handler->afterWriteCalled);
        $this->assertCapturedEvent(
            Severity::info(),
            'test.INFO: Info message',
            ['monolog.formatted' => 'test.INFO: Info message []'],
            null,
            [
                [
                    'type'      => 'default',
                    'category'  => 'test',
                    'level'     => 'info',
                    'message'   => 'Info message',
                    'timestamp' => '@double@',
                    'data'      => [],
                ],
            ]
        );
    }

    public function testHandleBatchWithHighLevelOfFilteringDoesNotCrash(): void
    {
        $handler = $this->createSentryHandler(Logger::EMERGENCY);

        $records = [
            [
                'message'    => 'Info message',
                'context'    => ['exception' => new \LogicException()],
                'level'      => $level = Logger::INFO,
                'level_name' => Logger::getLevelName($level),
                'channel'    => 'test',
                'extra'      => [],
            ],
            [
                'message'    => 'Critical message',
                'context'    => ['exception' => $exception = new \LogicException('Exception of critical level')],
                'level'      => $level = Logger::CRITICAL,
                'level_name' => Logger::getLevelName($level),
                'channel'    => 'test',
                'extra'      => [],
            ],
        ];

        $handler->handleBatch($records);

        $this->assertFalse($handler->afterWriteCalled);
        $this->assertNull($this->transport->spiedEvent);
    }

    private function assertCapturedEvent(Severity $severity, string $message, array $extra, \Exception $exception = null, array $breadcrumbs = []): void
    {
        $event = $this->transport->getSpiedEvent();

        if (null !== $exception) {
            $this->assertCount(1, $event->getExceptions());
            $exceptionDataBag = $event->getExceptions()[0];
            $this->assertSame(\get_class($exception), $exceptionDataBag->getType());
            $this->assertSame($exception->getMessage(), $exceptionDataBag->getValue());
        } else {
            $this->assertCount(0, $event->getExceptions());
        }

        $this->assertTrue($this->transport->isFlushed);
        $this->assertCount(0, $event->getTags());
        $this->assertNull($event->getUser());
        $this->assertSame($message, $event->getMessage());
        $this->assertSame((string) $severity, (string) $event->getLevel());
        $this->assertMatchesPattern('@string@', (string) $event->getId());
        $this->assertMatchesPattern('@string@', (string) $event->getTimestamp());
        $this->assertMatchesPattern('@string@', $event->getServerName());
        $this->assertMatchesPattern(['processScope' => 'called'] + $extra, $event->getExtra());

        if ($breadcrumbs) {
            $this->assertMatchesPattern(
                json_encode($breadcrumbs),
                json_encode(
                    array_map(
                        static function (Breadcrumb $breadcrumb) {
                            return [
                                'type' => $breadcrumb->getType(),
                                'category' => $breadcrumb->getCategory(),
                                'level' => $breadcrumb->getLevel(),
                                'message' => $breadcrumb->getMessage(),
                                'timestamp' => (double) $breadcrumb->getTimestamp(),
                                'data' => $breadcrumb->getMetadata(),
                            ];
                        },
                        $event->getBreadcrumbs()
                    )
                )
            );
        } else {
            $this->assertCount(0, $event->getBreadcrumbs());
        }

        $this->assertSame('sentry.php', $event->getSdkIdentifier());
        $this->assertMatchesPattern('@string@', $event->getSdkVersion());

        $this->assertMatchesPattern('@string@', $event->getOsContext()->getName());
        $this->assertMatchesPattern('@string@', $event->getOsContext()->getVersion());
        $this->assertMatchesPattern('@string@', $event->getOsContext()->getBuild());
        $this->assertMatchesPattern('@string@', $event->getOsContext()->getKernelVersion());
        $this->assertMatchesPattern('php', $event->getRuntimeContext()->getName());
        $this->assertMatchesPattern('@string@', $event->getRuntimeContext()->getVersion());
    }

    private function createSentryHandler(int $level = null): SpySentryHandler
    {
        if (null === $level) {
            $handler = new SpySentryHandler($this->hub);
        } else {
            $handler = new SpySentryHandler($this->hub, $level);
        }

        $handler->setFormatter(new LineFormatter('%channel%.%level_name%: %message% %extra%'));

        return $handler;
    }
}

class SpySentryHandler extends SentryHandler
{
    /**
     * @var bool
     */
    public $afterWriteCalled = false;

    /** {@inheritdoc} */
    protected function processScope(Scope $scope, array $record, Event $sentryEvent): void
    {
        $scope->setExtra('processScope', 'called');
    }

    /** {@inheritdoc} */
    protected function afterWrite(): void
    {
        $this->afterWriteCalled = true;

        parent::afterWrite();
    }

    public function resetSpy(): void
    {
        $this->afterWriteCalled = false;
    }
}

class SpyTransport implements TransportInterface
{
    /**
     * @var Event|null
     */
    public $spiedEvent;

    /**
     * @var bool
     */
    public $isFlushed = false;

    public function send(Event $event): PromiseInterface
    {
        $this->spiedEvent = $event;

        return new FulfilledPromise(new Response(ResponseStatus::skipped(), $event));
    }

    public function resetSpy(): void
    {
        $this->spiedEvent = null;
        $this->isFlushed  = false;
    }

    public function getSpiedEvent(): Event
    {
        if (null === $this->spiedEvent) {
            throw new \RuntimeException('No spied scope');
        }

        return $this->spiedEvent;
    }

    public function close(?int $timeout = null): PromiseInterface
    {
        $this->isFlushed = true;

        return new FulfilledPromise(true);
    }
}

class FakeTransportFactory implements TransportFactoryInterface
{
    /**
     * @var SpyTransport
     */
    private $transport;

    public function __construct(SpyTransport $transport)
    {
        $this->transport = $transport;
    }

    public function create(Options $options): TransportInterface
    {
        return $this->transport;
    }
}
