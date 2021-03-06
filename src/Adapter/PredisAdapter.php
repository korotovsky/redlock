<?php

namespace Everlution\Redlock\Adapter;

use Predis\Client as PredisClient;
use Predis\Collection\Iterator;
use Everlution\Redlock\Exception\Adapter\InvalidTtlException;

class PredisAdapter implements AdapterInterface
{
    private $predis;

    public function __construct(PredisClient $predis)
    {
        $this->predis = $predis;
    }

    public function isConnected()
    {
        try {
            /* @var $status \Predis\Response\Status */
            $status = $this->predis->ping();

            return $status->getPayload() == 'PONG';
        } catch (\Exception $e) {
            return false;
        }
    }

    public function del($key)
    {
        if (!$this->isConnected()) {
            return false;
        }

        return (bool) $this
            ->predis
            ->del($key)
        ;
    }

    public function exists($key)
    {
        if (!$this->isConnected()) {
            return false;
        }

        return (bool) $this
            ->predis
            ->exists($key)
        ;
    }

    public function get($key)
    {
        return $this
            ->predis
            ->get($key)
        ;
    }

    public function keys($pattern)
    {
        if (!$this->isConnected()) {
            return array();
        }

        /*
         * As specified in the Redis doc it's not a good idea to use keys() in
         * production as it might become a performance killer. For this reason
         * we are using SCAN() with the MATCH() option.
         */

        $keys = array();
        foreach (new Iterator\Keyspace($this->predis, $pattern) as $key) {
            $keys[] = $key;
        }

        return $keys;
    }

    public function set($key, $value, $ttl = null)
    {
        if (!$this->isConnected()) {
            return false;
        }

        $status = $this->predis->set($key, $value);

        if ($status->getPayload() == 'OK' && $ttl) {
            $this->predis->expire($key, $ttl);
        }

        /* @var $status \Predis\Response\Status */
        return $status->getPayload() == 'OK';
    }

    public function setTTL($key, $ttl)
    {
        if (!$this->isConnected()) {
            return false;
        }

        if (!$this->exists($key)) {
            return false;
        }

        if (!is_numeric($ttl)) {
            throw new InvalidTtlException($ttl);
        }

        return $this
            ->predis
            ->expire($key, (int) $ttl)
        ;
    }

    public function getTTL($key)
    {
        if (!$this->isConnected()) {
            return false;
        }

        return $this
            ->predis
            ->ttl($key)
        ;
    }
}
