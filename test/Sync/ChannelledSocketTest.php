<?php

namespace Amp\Parallel\Test\Sync;

use Amp\CancelledException;
use Amp\Parallel\Sync\ChannelException;
use Amp\Parallel\Sync\ChannelledSocket;
use Amp\Parallel\Sync\SerializationException;
use Amp\PHPUnit\AsyncTestCase;
use Amp\TimeoutCancellationToken;
use Revolt\EventLoop;

class ChannelledSocketTest extends AsyncTestCase
{
    public function testSendReceive(): void
    {
        [$left, $right] = $this->createSockets();
        $a = new ChannelledSocket($left, $left);
        $b = new ChannelledSocket($right, $right);

        $message = 'hello';

        $a->send($message);
        $data = $b->receive();
        self::assertSame($message, $data);
    }

    /**
     * @depends testSendReceive
     */
    public function testSendReceiveLongData(): void
    {
        [$left, $right] = $this->createSockets();
        $a = new ChannelledSocket($left, $left);
        $b = new ChannelledSocket($right, $right);

        $length = 0xffff;
        $message = '';
        for ($i = 0; $i < $length; ++$i) {
            $message .= \chr(\mt_rand(0, 255));
        }

        $a->send($message);
        $data = $b->receive();
        self::assertSame($message, $data);
    }

    /**
     * @depends testSendReceive
     */
    public function testInvalidDataReceived(): void
    {
        $this->expectException(ChannelException::class);

        [$left, $right] = $this->createSockets();
        $a = new ChannelledSocket($left, $left);
        $b = new ChannelledSocket($right, $right);

        \fwrite($left, \pack('L', 10) . '1234567890');
        $data = $b->receive();
    }

    /**
     * @depends testSendReceive
     */
    public function testSendUnserializableData(): void
    {
        $this->expectException(SerializationException::class);

        [$left, $right] = $this->createSockets();
        $a = new ChannelledSocket($left, $left);
        $b = new ChannelledSocket($right, $right);

        // Close $a. $b should close on next read...
        $a->send(fn () => null)->await();
        $data = $b->receive();
    }

    /**
     * @depends testSendReceive
     */
    public function testSendAfterClose(): void
    {
        $this->expectException(ChannelException::class);

        [$left, $right] = $this->createSockets();
        $a = new ChannelledSocket($left, $left);
        $a->close();

        $a->send('hello')->await();
    }

    /**
     * @depends testSendReceive
     */
    public function testReceiveAfterClose(): void
    {
        $this->expectException(ChannelException::class);

        [$left, $right] = $this->createSockets();
        $a = new ChannelledSocket($left, $left);
        $a->close();

        $data = $a->receive();
    }

    public function testCancelThenReceive()
    {
        [$left, $right] = $this->createSockets();
        $a = new ChannelledSocket($left, $left);
        $b = new ChannelledSocket($right, $right);

        try {
            $a->receive(new TimeoutCancellationToken(0.001));
            $this->fail('Receive should have been cancelled');
        } catch (CancelledException) {
        }

        $data = 'test';
        $b->send($data)->await();
        self::assertSame($data, $a->receive());
    }

    /**
     * @return resource[]
     */
    protected function createSockets(): array
    {
        if (($sockets = @\stream_socket_pair(
                \stripos(PHP_OS, "win") === 0 ? STREAM_PF_INET : STREAM_PF_UNIX,
                STREAM_SOCK_STREAM,
                STREAM_IPPROTO_IP
            )) === false) {
            $message = "Failed to create socket pair";
            if ($error = \error_get_last()) {
                $message .= \sprintf(" Errno: %d; %s", $error["type"], $error["message"]);
            }
            self::fail($message);
        }
        return $sockets;
    }
}
