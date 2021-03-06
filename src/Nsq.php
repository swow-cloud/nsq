<?php
/**
 * This file is part of Swow
 * @license  https://github.com/swow-cloud/nsq/blob/main/LICENSE
 */

declare(strict_types=1);

namespace SwowCloud\Nsq;

use Closure;
use Psr\Container\ContainerInterface;
use SwowCloud\Job\Logger\Logger;
use SwowCloud\Job\Logger\LoggerFactory;
use SwowCloud\Nsq\Exceptions\ConnectionException;
use SwowCloud\Socket\Exceptions\WriteStreamException;
use SwowCloud\Socket\Streams\Socket;
use Throwable;

class Nsq
{
    protected Socket $socket;

    protected ?Connection $connection;

    protected array $nsqConfig = [];

    protected ContainerInterface $container;

    /**
     * @var MessageBuilder
     */
    protected mixed $builder;

    protected Logger $logger;

    public function __construct(ContainerInterface $container, array $nsqConfig)
    {
        $this->container = $container;
        $this->builder = $container->get(MessageBuilder::class);
        $this->logger = $container->get(LoggerFactory::class)
            ->get();
        $this->nsqConfig = $nsqConfig ?? throw new \InvalidArgumentException('Nsq Config Unknow#');
    }

    /**
     * @param string|string[] $message
     *
     * @throws Throwable
     */
    public function publish(string $topic, array | string $message, float $deferTime = 0.0): bool
    {
        if (is_array($message)) {
            if ($deferTime > 0) {
                foreach ($message as $value) {
                    $this->sendDPub($topic, $value, $deferTime);
                }

                return true;
            }

            return $this->sendMPub($topic, $message);
        }

        if ($deferTime > 0) {
            return $this->sendDPub($topic, $message, $deferTime);
        }

        return $this->sendPub($topic, $message);
    }

    public function subscribe(string $topic, string $channel, callable $callback): void
    {
        $this->call(function (Socket $socket) use ($topic, $channel, $callback) {
            $this->sendSub($socket, $topic, $channel);
            while ($this->sendRdy($socket)) {
                $reader = new Subscriber($socket);
                $reader->recv();

                if ($reader->isMessage()) {
                    if ($reader->isHeartbeat()) {
                        $socket->write($this->builder->buildNop());
                    } else {
                        $message = $reader->getMessage();
                        $result = null;
                        try {
                            $result = $callback($message);
                        } catch (Throwable $throwable) {
                            $result = Result::DROP;
                            $this->logger->error('Subscribe failed, ' . $throwable);
                        }

                        if ($result === Result::REQUEUE) {
                            $socket->write($this->builder->buildTouch($message->getMessageId()));
                            $socket->write($this->builder->buildReq($message->getMessageId()));
                            continue;
                        }

                        $socket->write($this->builder->buildFin($message->getMessageId()));
                    }
                }
            }
        });
    }

    protected function sendMPub(string $topic, array $messages): bool
    {
        $payload = $this->builder->buildMPub($topic, $messages);

        return $this->call(function (Socket $socket) use ($payload) {
            try {
                $socket->write($payload);

                return true;
            } catch (Throwable $exception) {
                throw new ConnectionException('Payload send failed, the errorMsg is ' . $exception->getMessage() . ',line:' . $exception->getLine() . ',file:' . $exception->getFile());
            }
        });
    }

    protected function sendPub(string $topic, string $message): bool
    {
        $payload = $this->builder->buildPub($topic, $message);

        return $this->call(function (Socket $socket) use ($payload) {
            try {
                $socket->write($payload);

                return true;
            } catch (Throwable $exception) {
                throw new ConnectionException('Payload send failed, the errorMsg is ' . $exception->getMessage() . ',line:' . $exception->getLine() . ',file:' . $exception->getFile());
            }
        });
    }

    protected function sendDPub(string $topic, string $message, float $deferTime = 0.0): bool
    {
        $payload = $this->builder->buildDPub($topic, $message, (int) ($deferTime * 1000));

        return $this->call(function (Socket $socket) use ($payload) {
            try {
                $socket->write($payload);

                return true;
            } catch (\Exception $e) {
                throw new ConnectionException('Payload send failed, the errorMsg is ' . $e->getMessage());
            }
        });
    }

    protected function call(Closure $closure)
    {
        $connection = $this->connection ?? $this->makeConnection();
        try {
            return $connection->call($closure);
        } catch (Throwable $throwable) {
            $connection->close();
            throw $throwable;
        } finally {
            $connection->release();
        }
    }

    protected function makeConnection(): Connection
    {
        $this->connection = new Connection($this->container, $this->nsqConfig);

        return $this->connection;
    }

    protected function sendSub(Socket $socket, string $topic, string $channel): void
    {
        try {
            $socket->write($this->builder->buildSub($topic, $channel));
            $socket->readChar();
        } catch (\Throwable $exception) {
            throw new WriteStreamException('SUB send failed, the errorMsg:' . $exception->getMessage() . ',line: ' . $exception->getLine() . ',file:' . $exception->getFile);
        }
    }

    protected function sendRdy(Socket $socket): bool
    {
        try {
            $socket->write($this->builder->buildRdy(1));

            return true;
        } catch (\Throwable $exception) {
            throw new WriteStreamException('RDY send failed, the errorMsg errorMsg:' . $exception->getMessage() . ',line: ' . $exception->getLine() . ',file:' . $exception->getFile);
        }
    }
}
