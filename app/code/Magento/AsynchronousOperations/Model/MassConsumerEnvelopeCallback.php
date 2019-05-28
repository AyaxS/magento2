<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Magento\AsynchronousOperations\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Registry;
use Psr\Log\LoggerInterface;
use Magento\Framework\MessageQueue\MessageLockException;
use Magento\Framework\MessageQueue\ConnectionLostException;
use Magento\Framework\Exception\NotFoundException;
use Magento\Framework\MessageQueue\ConsumerConfigurationInterface;
use Magento\Framework\MessageQueue\EnvelopeInterface;
use Magento\Framework\MessageQueue\QueueInterface;
use Magento\Framework\MessageQueue\LockInterface;
use Magento\Framework\MessageQueue\MessageController;

/**
 * Class used by \Magento\AsynchronousOperations\Model\MassConsumer as public callback function.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class MassConsumerEnvelopeCallback
{
    /**
     * @var QueueInterface
     */
    private $queue;

    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @var ConsumerConfigurationInterface
     */
    private $configuration;

    /**
     * @var MessageController
     */
    private $messageController;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var OperationProcessor
     */
    private $operationProcessor;

    /**
     * @var Registry
     */
    private $registry;

    /**
     * @param ResourceConnection $resource
     * @param MessageController $messageController
     * @param ConsumerConfigurationInterface $configuration
     * @param OperationProcessorFactory $operationProcessorFactory
     * @param LoggerInterface $logger
     * @param QueueInterface $queue
     * @param Registry|null $registry
     */
    public function __construct(
        ResourceConnection $resource,
        MessageController $messageController,
        ConsumerConfigurationInterface $configuration,
        OperationProcessorFactory $operationProcessorFactory,
        LoggerInterface $logger,
        QueueInterface $queue,
        Registry $registry = null
    ) {
        $this->resource = $resource;
        $this->messageController = $messageController;
        $this->configuration = $configuration;
        $this->operationProcessor = $operationProcessorFactory->create([
            'configuration' => $configuration
        ]);
        $this->logger = $logger;
        $this->registry = $registry ?? \Magento\Framework\App\ObjectManager::getInstance()
            ->get(Registry::class);
        $this->queue = $queue;
    }

    /**
     * Get transaction callback. This handles the case of async.
     *
     * @param EnvelopeInterface $message
     */
    public function execute(EnvelopeInterface $message)
    {
        $queue = $this->queue;
        /** @var LockInterface $lock */
        $lock = null;
        try {
            $topicName = $message->getProperties()['topic_name'];
            $lock = $this->messageController->lock($message, $this->configuration->getConsumerName());

            $allowedTopics = $this->configuration->getTopicNames();
            if (in_array($topicName, $allowedTopics)) {
                $this->operationProcessor->process($message->getBody());
            } else {
                $queue->reject($message);
                return;
            }
            $queue->acknowledge($message);
        } catch (MessageLockException $exception) {
            $queue->acknowledge($message);
        } catch (ConnectionLostException $e) {
            if ($lock) {
                $this->resource->getConnection()
                    ->delete($this->resource->getTableName('queue_lock'), ['id = ?' => $lock->getId()]);
            }
        } catch (NotFoundException $e) {
            $queue->acknowledge($message);
            $this->logger->warning($e->getMessage());
        } catch (\Exception $e) {
            $queue->reject($message, false, $e->getMessage());
            if ($lock) {
                $this->resource->getConnection()
                    ->delete($this->resource->getTableName('queue_lock'), ['id = ?' => $lock->getId()]);
            }
        }
    }
}
