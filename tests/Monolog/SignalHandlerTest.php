<?php declare(strict_types=1);

/*
 * This file is part of the Monolog package.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Monolog;

use Monolog\Handler\StreamHandler;
use Monolog\Handler\TestHandler;
use Monolog\Test\MonologTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LogLevel;

/**
 * @author Robert Gust-Bardon <robert@gust-bardon.org>
 * @covers Monolog\SignalHandler
 */
class SignalHandlerTest extends MonologTestCase
{
    private bool $asyncSignalHandling;
    private array $blockedSignals = [];
    private array $signalHandlers = [];

    protected function setUp(): void
    {
        $this->signalHandlers = [];
        if (\extension_loaded('pcntl')) {
            if (\function_exists('pcntl_async_signals')) {
                $this->asyncSignalHandling = pcntl_async_signals();
            }
            if (\function_exists('pcntl_sigprocmask')) {
                pcntl_sigprocmask(SIG_SETMASK, [], $this->blockedSignals);
            }
        }
    }

    public function tearDown(): void
    {
        parent::tearDown();

        if ($this->asyncSignalHandling !== null) {
            pcntl_async_signals($this->asyncSignalHandling);
        }
        if ($this->blockedSignals !== null) {
            pcntl_sigprocmask(SIG_SETMASK, $this->blockedSignals);
        }
        if ($this->signalHandlers) {
            pcntl_signal_dispatch();
            foreach ($this->signalHandlers as $signo => $handler) {
                pcntl_signal($signo, $handler);
            }
        }

        unset($this->signalHandlers, $this->blockedSignals, $this->asyncSignalHandling);
    }

    private function setSignalHandler($signo, $handler = SIG_DFL)
    {
        if (\function_exists('pcntl_signal_get_handler')) {
            $this->signalHandlers[$signo] = pcntl_signal_get_handler($signo);
        } else {
            $this->signalHandlers[$signo] = SIG_DFL;
        }
        $this->assertTrue(pcntl_signal($signo, $handler));
    }

    public function testHandleSignal()
    {
        $logger = new Logger('test', [$handler = new TestHandler]);
        $errHandler = new SignalHandler($logger);
        $signo = 2;  // SIGINT.
        $siginfo = ['signo' => $signo, 'errno' => 0, 'code' => 0];
        $errHandler->handleSignal($signo, $siginfo);
        $this->assertCount(1, $handler->getRecords());
        $this->assertTrue($handler->hasCriticalRecords());
        $records = $handler->getRecords();
        $this->assertSame($siginfo, $records[0]['context']);
    }

    /**
     * @depends testHandleSignal
     * @requires extension pcntl
     * @requires extension posix
     * @requires function pcntl_signal
     * @requires function pcntl_signal_dispatch
     * @requires function posix_getpid
     * @requires function posix_kill
     */
    public function testRegisterSignalHandler()
    {
        // SIGCONT and SIGURG should be ignored by default.
        if (!\defined('SIGCONT') || !\defined('SIGURG')) {
            $this->markTestSkipped('This test requires the SIGCONT and SIGURG pcntl constants.');
        }

        $this->setSignalHandler(SIGCONT, SIG_IGN);
        $this->setSignalHandler(SIGURG, SIG_IGN);

        $logger = new Logger('test', [$handler = new TestHandler]);
        $errHandler = new SignalHandler($logger);
        $pid = posix_getpid();

        $this->assertTrue(posix_kill($pid, SIGURG));
        $this->assertTrue(pcntl_signal_dispatch());
        $this->assertCount(0, $handler->getRecords());

        $errHandler->registerSignalHandler(SIGURG, LogLevel::INFO, false, false, false);

        $this->assertTrue(posix_kill($pid, SIGCONT));
        $this->assertTrue(pcntl_signal_dispatch());
        $this->assertCount(0, $handler->getRecords());

        $this->assertTrue(posix_kill($pid, SIGURG));
        $this->assertTrue(pcntl_signal_dispatch());
        $this->assertCount(1, $handler->getRecords());
        $this->assertTrue($handler->hasInfoThatContains('SIGURG'));
    }

    /**
     * @depends testRegisterSignalHandler
     * @requires function pcntl_fork
     * @requires function pcntl_sigprocmask
     * @requires function pcntl_waitpid
     */
    #[DataProvider('defaultPreviousProvider')]
    public function testRegisterDefaultPreviousSignalHandler($signo, $callPrevious, $expected)
    {
        $this->setSignalHandler($signo, SIG_DFL);

        $path = tempnam(sys_get_temp_dir(), 'monolog-');
        $this->assertNotFalse($path);

        $pid = pcntl_fork();
        if ($pid === 0) {  // Child.
            $streamHandler = new StreamHandler($path);
            $streamHandler->setFormatter($this->getIdentityFormatter());
            $logger = new Logger('test', [$streamHandler]);
            $errHandler = new SignalHandler($logger);
            $errHandler->registerSignalHandler($signo, LogLevel::INFO, $callPrevious, false, false);
            pcntl_sigprocmask(SIG_SETMASK, [SIGCONT]);
            posix_kill(posix_getpid(), $signo);
            pcntl_signal_dispatch();
            // If $callPrevious is true, SIGINT should terminate by this line.
            pcntl_sigprocmask(SIG_SETMASK, [], $oldset);
            file_put_contents($path, implode(' ', $oldset), FILE_APPEND);
            posix_kill(posix_getpid(), $signo);
            pcntl_signal_dispatch();
            exit();
        }

        $this->assertNotSame(-1, $pid);
        $this->assertNotSame(-1, pcntl_waitpid($pid, $status));
        $this->assertNotSame(-1, $status);
        $this->assertSame($expected, file_get_contents($path));
    }

    public static function defaultPreviousProvider()
    {
        if (!\defined('SIGCONT') || !\defined('SIGINT') || !\defined('SIGURG')) {
            return [];
        }

        return [
            [SIGINT, false, 'Program received signal SIGINT'.SIGCONT.'Program received signal SIGINT'],
            [SIGINT, true, 'Program received signal SIGINT'],
            [SIGURG, false, 'Program received signal SIGURG'.SIGCONT.'Program received signal SIGURG'],
            [SIGURG, true, 'Program received signal SIGURG'.SIGCONT.'Program received signal SIGURG'],
        ];
    }

    /**
     * @depends testRegisterSignalHandler
     * @requires function pcntl_signal_get_handler
     */
    #[DataProvider('callablePreviousProvider')]
    public function testRegisterCallablePreviousSignalHandler($callPrevious)
    {
        $this->setSignalHandler(SIGURG, SIG_IGN);

        $logger = new Logger('test', [$handler = new TestHandler]);
        $errHandler = new SignalHandler($logger);
        $previousCalled = 0;
        pcntl_signal(SIGURG, function ($signo, ?array $siginfo = null) use (&$previousCalled) {
            ++$previousCalled;
        });
        $errHandler->registerSignalHandler(SIGURG, LogLevel::INFO, $callPrevious, false, false);
        $this->assertTrue(posix_kill(posix_getpid(), SIGURG));
        $this->assertTrue(pcntl_signal_dispatch());
        $this->assertCount(1, $handler->getRecords());
        $this->assertTrue($handler->hasInfoThatContains('SIGURG'));
        $this->assertSame($callPrevious ? 1 : 0, $previousCalled);
    }

    public static function callablePreviousProvider()
    {
        return [
            [false],
            [true],
        ];
    }

    /**
     * @depends testRegisterDefaultPreviousSignalHandler
     * @requires function pcntl_fork
     * @requires function pcntl_waitpid
     */
    #[DataProvider('restartSyscallsProvider')]
    public function testRegisterSyscallRestartingSignalHandler($restartSyscalls)
    {
        $this->setSignalHandler(SIGURG, SIG_IGN);

        $parentPid = posix_getpid();
        $microtime = microtime(true);

        $pid = pcntl_fork();
        if ($pid === 0) {  // Child.
            usleep(100000);
            posix_kill($parentPid, SIGURG);
            usleep(100000);
            exit();
        }

        $this->assertNotSame(-1, $pid);
        $logger = new Logger('test', [$handler = new TestHandler]);
        $errHandler = new SignalHandler($logger);
        $errHandler->registerSignalHandler(SIGURG, LogLevel::INFO, false, $restartSyscalls, false);
        if ($restartSyscalls) {
            // pcntl_wait is expected to be restarted after the signal handler.
            $this->assertNotSame(-1, pcntl_waitpid($pid, $status));
        } else {
            // pcntl_wait is expected to be interrupted when the signal handler is invoked.
            $this->assertSame(-1, pcntl_waitpid($pid, $status));
        }
        $this->assertSame($restartSyscalls, microtime(true) - $microtime > 0.15);
        $this->assertTrue(pcntl_signal_dispatch());
        $this->assertCount(1, $handler->getRecords());
        if ($restartSyscalls) {
            // The child has already exited.
            $this->assertSame(-1, pcntl_waitpid($pid, $status));
        } else {
            // The child has not exited yet.
            $this->assertNotSame(-1, pcntl_waitpid($pid, $status));
        }
    }

    public static function restartSyscallsProvider()
    {
        return [
            [false],
            [true],
            [false],
            [true],
        ];
    }

    /**
     * @depends testRegisterDefaultPreviousSignalHandler
     * @requires function pcntl_async_signals
     */
    #[DataProvider('asyncProvider')]
    public function testRegisterAsyncSignalHandler($initialAsync, $desiredAsync, $expectedBefore, $expectedAfter)
    {
        $this->setSignalHandler(SIGURG, SIG_IGN);
        pcntl_async_signals($initialAsync);

        $logger = new Logger('test', [$handler = new TestHandler]);
        $errHandler = new SignalHandler($logger);
        $errHandler->registerSignalHandler(SIGURG, LogLevel::INFO, false, false, $desiredAsync);
        $this->assertTrue(posix_kill(posix_getpid(), SIGURG));
        $this->assertCount($expectedBefore, $handler->getRecords());
        $this->assertTrue(pcntl_signal_dispatch());
        $this->assertCount($expectedAfter, $handler->getRecords());
    }

    public static function asyncProvider()
    {
        return [
            [false, false, 0, 1],
            [false, null, 0, 1],
            [false, true, 1, 1],
            [true, false, 0, 1],
            [true, null, 1, 1],
            [true, true, 1, 1],
        ];
    }
}
