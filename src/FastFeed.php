<?php

/**
 * This file is part of the FastFeed package.
 *
 * Copyright (c) Daniel González
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @author Daniel González <daniel@desarrolla2.com>
 */
namespace FastFeed;

use Guzzle\Http\ClientInterface;
use Ivory\HttpAdapter\HttpAdapterInterface;
use Ivory\HttpAdapter\Message\Request;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use FastFeed\Exception\LogicException;
use FastFeed\Parser\ParserInterface;
use FastFeed\Processor\ProcessorInterface;

/**
 * FastFeed
 */
class FastFeed implements FastFeedInterface
{
    /**
     * @const VERSION
     */
    const VERSION = '1.0';

    /**
     * @const USER_AGENT
     */
    const USER_AGENT = 'FastFeed/FastFeed';

    /**
     * @var ClientInterface;
     */
    protected $http;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var array
     */
    protected $parsers = [];

    /**
     * @var array
     */
    protected $processors = [];

    /**
     * @var array
     */
    protected $feeds = [];

    /**
     * @param HttpAdapterInterface $http
     * @param LoggerInterface      $logger
     */
    public function __construct(HttpAdapterInterface $http, LoggerInterface $logger)
    {
        $this->http = $http;
        $this->logger = $logger;
    }

    /**
     * Add feed to channel
     *
     * @param string $channel
     * @param string $feed
     *
     * @throws LogicException
     */
    public function addFeed($channel, $feed)
    {
        if (!filter_var($feed, FILTER_VALIDATE_URL)) {
            throw new LogicException('You tried to add a invalid url.');
        }
        $this->feeds[$channel][] = $feed;
    }

    /**
     * @param string $channel
     *
     * @return Item[]
     * @throws Exception\LogicException
     */
    public function fetch($channel = 'default')
    {
        if (!is_string($channel)) {
            throw new LogicException('You tried to add a invalid channel.');
        }

        $items = $this->retrieve($channel);

        foreach ($this->processors as $processor) {
            $items = $processor->process($items);
        }

        return $items;
    }

    /**
     * Retrieve a channel
     *
     * @param string $channel
     *
     * @return string
     * @throws LogicException
     */
    public function getFeed($channel)
    {
        if (!isset($this->feeds[$channel])) {
            throw new LogicException('You tried to get a not existent channel');
        }

        return $this->feeds[$channel];
    }

    /**
     * @return ParserInterface
     * @throws Exception\LogicException
     */
    public function popParser()
    {
        if (!$this->parsers) {
            throw new LogicException('You tried to pop from an empty parsers stack.');
        }

        return array_shift($this->parsers);
    }

    /**
     * @param ParserInterface $parser
     */
    public function pushParser(ParserInterface $parser)
    {
        $this->parsers[] = $parser;
    }

    /**
     * @return ProcessorInterface
     * @throws Exception\LogicException
     */
    public function popProcessor()
    {
        if (!$this->processors) {
            throw new LogicException('You tried to pop from an empty Processor stack.');
        }

        return array_shift($this->processors);
    }

    /**
     * @param ProcessorInterface $processor
     */
    public function pushProcessor(ProcessorInterface $processor)
    {
        $this->processors[] = $processor;
    }

    /**
     * Retrieve all channels
     *
     * @return array
     */
    public function getFeeds()
    {
        return $this->feeds;
    }

    /**
     * Set Guzzle
     *
     * @param ClientInterface $guzzle
     */
    public function setHttpClient(ClientInterface $guzzle)
    {
        $this->http = $guzzle;
    }

    /**
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Set a channel
     *
     * @param string $channel
     * @param string $feed
     *
     * @throws LogicException
     */
    public function setFeed($channel, $feed)
    {
        if (!is_string($channel)) {
            throw new LogicException('You tried to add a invalid channel.');
        }
        $this->feeds[$channel] = [];
        $this->addFeed($channel, $feed);
    }

    /**
     * Retrieve content from a resource
     *
     * @param $url
     *
     * @return \Guzzle\Http\EntityBodyInterface|string
     */
    protected function get($url)
    {
        $request = $this->http->get(
            $url,
            ['User-Agent' => self::USER_AGENT.' v.'.self::VERSION]
        );

        $response = $request->send();

        if (!$response->isSuccessful()) {
            $this->log('fail with '.$response->getStatusCode().' http code in url "'.$url.'" ');

            return;
        }
        $this->logger->log(LogLevel::INFO, 'retrieved url "'.$url.'" ');

        return $response->getBody();
    }

    /**
     * @param string $channel
     *
     * @return array
     */
    protected function retrieve($channel)
    {
        $result = [];

        foreach ($this->feeds[$channel] as $feed) {
            $content = $this->get($feed);
            if (!$content) {
                continue;
            }
            $result = array_merge($result, $this->parse($content));
        }

        return $result;
    }

    /**
     * @param string $content
     *
     * @return array
     */
    protected function parse($content)
    {
        $result = [];
        foreach ($this->parsers as $parser) {
            $nodes = $parser->getNodes($content);
            if (!$nodes) {
                continue;
            }

            foreach ($nodes as $node) {
                $result[] = $node;
            }
        }

        return $result;
    }

    /**
     * @param string $message
     */
    protected function log($message)
    {
        $this->logger->log(
            LogLevel::INFO,
            '['.self::USER_AGENT.' v.'.self::VERSION.'] - '.$message
        );
    }
}
