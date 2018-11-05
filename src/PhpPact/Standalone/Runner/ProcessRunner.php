<?php

namespace PhpPact\Standalone\Runner;

use Amp\ByteStream\Payload;
use Amp\ByteStream\ResourceOutputStream;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Loop;
use Amp\Process\Process;
use Amp\Process\ProcessException;
use Monolog\Logger;

/**
 * Wrapper around Process with Amp
 */
class ProcessRunner
{
    /** @var Process */
    private $process;

    /** @var string command output */
    private $output;

    /** @var int command exit code */
    private $exitCode;

    /** @var string */
    private $stderr;

    /**
     * @param string $command
     * @param array  $arguments
     */
    public function __construct(string $command, array $arguments)
    {
        $this->exitCode  = -1;
        $this->output    = null;
        $this->stderr    = null;
        $this->process   = new Process($command . ' ' . \implode(' ', $arguments));
    }

    /**
     * @return string
     */
    public function getOutput(): string
    {
        return $this->output;
    }

    /**
     * @param string $output
     */
    public function setOutput(string $output): void
    {
        $this->output = $output;
    }

    /**
     * @return int
     */
    public function getExitCode(): int
    {
        return $this->exitCode;
    }

    /**
     * @param int $exitCode
     */
    public function setExitCode(int $exitCode): void
    {
        $this->exitCode = $exitCode;
    }

    public function getCommand(): string
    {
        return $this->process->getCommand();
    }

    /**
     * @return string
     */
    public function getStderr(): string
    {
        return $this->stderr;
    }

    /**
     * @param string $stderr
     */
    public function setStderr(string $stderr): void
    {
        $this->stderr = $stderr;
    }

    /**
     * Run the process and set output
     *
     * @param bool $blocking
     *
     * @return int
     */
    public function run($blocking = false): int
    {
        $logHandler = new StreamHandler(new ResourceOutputStream(\STDOUT));
        $logHandler->setFormatter(new ConsoleFormatter);
        $logger = new Logger('server');
        $logger->pushHandler($logHandler);

        $self       = &$this; // goofiness to get the output values out

        $pid        = null;
        $lambdaLoop = function () use (&$self, $blocking, $logger, &$pid) {
            $logger->debug("Process command: {$self->process->getCommand()}");

            $self->process->start();

            if ($blocking) {
                $payload = new Payload($self->process->getStdout());
                $output  = yield $payload->buffer();
                $self->setOutput($output);

                $stderrPayload = new Payload($self->process->getStderr());
                $self->setStderr(yield $stderrPayload->buffer());

                $logger->debug("Process Output: {$self->getOutput()}");
                $exitCode = yield $self->process->join();
                $self->setExitCode($exitCode);
                $logger->debug("Exit code: {$self->getExitCode()}");
            }

            $pid = yield $this->process->getPid();

            Loop::stop();

            if ($blocking) {
                if ($self->getExitCode() !== 0) {
                    throw new \Exception("PactPHP Process returned non-zero exit code: {$self->getExitCode()}");
                }
            }
        };

        Loop::run($lambdaLoop);

        return $pid;
    }

    public function stop()
    {
        $this->process->getPid()->onResolve(function ($error, $pid) {
            if ($error) {
                throw new ProcessException($error);
            }

            print "\nStopping Process Id: {$pid}\n";

            if ('\\' === \DIRECTORY_SEPARATOR) {
                \exec(\sprintf('taskkill /F /T /PID %d 2>&1', $pid), $output, $exitCode);
                if ($exitCode) {
                    throw new ProcessException(\sprintf('Unable to kill the process (%s).', \implode(' ', $output)));
                }
            } else {
                $this->process->signal(15);

                if ($ok = \proc_open("kill -9 $pid", [2 => ['pipe', 'w']], $pipes)) {
                    $ok = false === \fgets($pipes[2]);
                }

                if (!$ok) {
                    throw new ProcessException(\sprintf('Error while killing process "%s".', $pid));
                }
            }

            $this->process->kill();
        });

        return true;
    }
}
