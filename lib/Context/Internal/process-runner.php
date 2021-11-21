<?php

namespace Amp\Parallel\Context\Internal;

use Amp\Future;
use Amp\Parallel\Context\Process;
use Amp\Parallel\Sync;
use function Amp\delay;

\define("AMP_CONTEXT", "process");
\define("AMP_CONTEXT_ID", \getmypid());

// Doesn't exist in phpdbg...
if (\function_exists("cli_set_process_title")) {
    @\cli_set_process_title("amp-process");
}

(function (): void {
    $paths = [
        \dirname(__DIR__, 5) . "/autoload.php",
        \dirname(__DIR__, 3) . "/vendor/autoload.php",
    ];

    foreach ($paths as $path) {
        if (\file_exists($path)) {
            $autoloadPath = $path;
            break;
        }
    }

    if (!isset($autoloadPath)) {
        \trigger_error("Could not locate autoload.php in any of the following files: " . \implode(", ", $paths), E_USER_ERROR);
    }

    require $autoloadPath;
})();

(function () use ($argc, $argv): void {
    // Remove this scripts path from process arguments.
    --$argc;
    \array_shift($argv);

    if (!isset($argv[0])) {
        \trigger_error("No socket path provided", E_USER_ERROR);
    }

    // Remove socket path from process arguments.
    --$argc;
    $uri = \array_shift($argv);

    $key = "";

    // Read random key from STDIN and send back to parent over IPC socket to authenticate.
    do {
        if (($chunk = \fread(\STDIN, Process::KEY_LENGTH)) === false || \feof(\STDIN)) {
            \trigger_error("Could not read key from parent", E_USER_ERROR);
        }
        $key .= $chunk;
    } while (\strlen($key) < Process::KEY_LENGTH);

    $connectStart = microtime(true);

    while (!$socket = \stream_socket_client($uri, $errno, $errstr, 5, \STREAM_CLIENT_CONNECT)) {
        if (microtime(true) > $connectStart + 5) { // try for 5 seconds, after that the parent times out anyway
            \trigger_error("Could not connect to IPC socket", \E_USER_ERROR);
        }

        delay(0.01);
    }

    $channel = new Sync\ChannelledSocket($socket, $socket);

    try {
        $channel->send($key)->await();
    } catch (\Throwable) {
        \trigger_error("Could not send key to parent", E_USER_ERROR);
    }

    try {
        if (!isset($argv[0])) {
            throw new \Error("No script path given");
        }

        if (!\is_file($argv[0])) {
            throw new \Error(\sprintf("No script found at '%s' (be sure to provide the full path to the script)", $argv[0]));
        }

        try {
            // Protect current scope by requiring script within another function.
            $callable = (function () use ($argc, $argv): callable { // Using $argc so it is available to the required script.
                return require $argv[0];
            })();
        } catch (\TypeError $exception) {
            throw new \Error(\sprintf("Script '%s' did not return a callable function", $argv[0]), 0, $exception);
        } catch (\ParseError $exception) {
            throw new \Error(\sprintf("Script '%s' contains a parse error: " . $exception->getMessage(), $argv[0]), 0, $exception);
        }

        $returnValue = $callable($channel);
        $result = new Sync\ExitSuccess($returnValue instanceof Future ? $returnValue->await() : $returnValue);
    } catch (\Throwable $exception) {
        $result = new Sync\ExitFailure($exception);
    }

    try {
        try {
            $channel->send($result)->await();
        } catch (Sync\SerializationException $exception) {
            // Serializing the result failed. Send the reason why.
            $channel->send(new Sync\ExitFailure($exception))->await();
        }
    } catch (\Throwable $exception) {
        \trigger_error(sprintf(
            "Could not send result to parent: '%s'; be sure to shutdown the child before ending the parent",
            $exception->getMessage(),
        ), E_USER_ERROR);
    }
})();
