<?php

namespace iqbalmalik89\GuzzleApplicationCacheSubscriber;

use Doctrine\Common\Cache\Cache;
use GuzzleHttp\Message\MessageInterface;
use GuzzleHttp\Message\Request;
use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Message\Response;
use GuzzleHttp\Message\ResponseInterface;
use GuzzleHttp\Stream\StreamInterface;
use GuzzleHttp\Stream\Utils;


/**
 * Default cache storage implementation.
 * @see https://github.com/guzzle/cache-subscriber/blob/0.1.0/src/CacheStorage.php
 */
class CacheStorage
{
    /** @var string */
    private $keyPrefix;

    /** @var Cache */
    private $cache;

    /**
     * @param Cache $cache Cache backend.
     * @param string $keyPrefix Key prefix to add to each key.
     */
    public function __construct(Cache $cache, $keyPrefix = null)
    {
        $this->cache = $cache;
        $this->keyPrefix = $keyPrefix;
    }

    public function cache(
        RequestInterface $request,
        ResponseInterface $response
    )
    {
        $ctime = time();
        $key = $this->getCacheKey($request);
        $headers = $this->getHeaders($request);
        $entries = $this->getManifestEntries($key, $ctime, $response, $headers);
        $bodyDigest = null;

        // Persist the response body if needed
        if ($response->getBody() && $response->getBody()->getSize() > 0) {
            $body = $response->getBody();
            $bodyDigest = $this->getBodyKey($request->getUrl(), $body);
            $this->cache->save($bodyDigest, (string)$body);
        }

        array_unshift($entries, [
            $headers,
            $this->getHeaders($response),
            $response->getStatusCode(),
            $bodyDigest,
        ]);

        $this->cache->save($key, serialize($entries));
    }

    public function delete(RequestInterface $request)
    {
        $key = $this->getCacheKey($request);
        $entries = $this->cache->fetch($key);

        if (!$entries) {
            return;
        }

        // Delete each cached body
        foreach (unserialize($entries) as $entry) {
            if ($entry[3]) {
                $this->cache->delete($entry[3]);
            }
        }

        $this->cache->delete($key);
    }

    public function purge($url)
    {
        foreach (['GET', 'HEAD', 'POST', 'PUT', 'DELETE', 'OPTIONS'] as $m) {
            $this->delete(new Request($m, $url));
        }
    }

    public function fetch(RequestInterface $request)
    {
        $key = $this->getCacheKey($request);
        $entries = $this->cache->fetch($key);

        if (!$entries) {
            return null;
        }

        $match = $matchIndex = null;
        $headers = $this->getHeaders($request);
        $entries = unserialize($entries);

        foreach ($entries as $index => $entry) {
            $vary = isset($entry[1]['vary']) ? $entry[1]['vary'] : '';
            if ($this->requestsMatch($vary, $headers, $entry[0])) {
                $match = $entry;
                $matchIndex = $index;
                break;
            }
        }

        if (!$match) {
            return null;
        }

        // Ensure that the response is not expired
        $response = new Response($match[2], $match[1]);
        if ($match[3]) {
            if ($body = $this->cache->fetch($match[3])) {
                $response->setBody(Utils::create($body));
            } else {
                // The response is not valid because the body was somehow
                // deleted
                $response = -1;
            }
        }

        if ($response === -1) {
            // Remove the entry from the metadata and update the cache
            unset($entries[$matchIndex]);
            if ($entries) {
                $this->cache->save($key, serialize($entries));
            } else {
                $this->cache->delete($key);
            }
            return null;
        }

        return $response;
    }

    /**
     * Hash a request URL into a string that returns cache metadata
     *
     * @param RequestInterface $request
     *
     * @return string
     */
    private function getCacheKey(RequestInterface $request)
    {
        return $this->keyPrefix
        . md5($request->getMethod() . ' ' . $request->getUrl());
    }

    /**
     * Create a cache key for a response's body
     *
     * @param string $url URL of the entry
     * @param StreamInterface $body Response body
     *
     * @return string
     */
    private function getBodyKey($url, StreamInterface $body)
    {
        return $this->keyPrefix . md5($url) . Utils::hash($body, 'md5');
    }

    /**
     * Determines whether two Request HTTP header sets are non-varying
     *
     * @param string $vary Response vary header
     * @param array $r1 HTTP header array
     * @param array $r2 HTTP header array
     *
     * @return bool
     */
    private function requestsMatch($vary, $r1, $r2)
    {
        if ($vary) {
            foreach (explode(',', $vary) as $header) {
                $key = trim(strtolower($header));
                $v1 = isset($r1[$key]) ? $r1[$key] : null;
                $v2 = isset($r2[$key]) ? $r2[$key] : null;
                if ($v1 !== $v2) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Creates an array of cacheable and normalized message headers
     *
     * @param MessageInterface $message
     *
     * @return array
     */
    private function getHeaders(MessageInterface $message)
    {
        // Clone the response to not destroy any necessary headers when caching
        $headers = $message->getHeaders();

        // Cast the headers to a string
        foreach ($headers as &$value) {
            $value = implode(', ', $value);
        }

        return $headers;
    }


    private function getManifestEntries(
        $key,
        $currentTime,
        ResponseInterface $response,
        $persistedRequest
    )
    {
        $entries = [];
        $manifest = $this->cache->fetch($key);

        if (!$manifest) {
            return $entries;
        }

        // Determine which cache entries should still be in the cache
        $vary = $response->getHeader('Vary');

        foreach (unserialize($manifest) as $entry) {
            // Check if the entry is expired
            if ($entry[4] < $currentTime) {
                continue;
            }

            $varyCmp = isset($entry[1]['vary']) ? $entries[1]['vary'] : '';

            if ($vary != $varyCmp ||
                !$this->requestsMatch($vary, $entry[0], $persistedRequest)
            ) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }
}