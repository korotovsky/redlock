<?php

namespace Everlution\Redlock\Manager;

use Everlution\Redlock\Quorum\QuorumInterface;
use Everlution\Redlock\KeyGenerator\KeyGeneratorInterface;
use Everlution\Redlock\Model\LockInterface;
use Everlution\Redlock\Model\Lock;
use Everlution\Redlock\Adapter\AdapterInterface;
use Everlution\Redlock\Exception\InvalidLockTypeException;

class LockManager
{
    const CLOCK_DRIFT_FACTOR = 0.01;

    /**
     * @var array[\Everlution\Redlock\Adapter\AdapterInterface]
     */
    private $adapters;

    /**
     * @var \Everlution\Redlock\Quorum\QuorumInterface
     */
    private $quorum;

    /**
     * @var \Everlution\Redlock\KeyGenerator\KeyGeneratorInterface
     */
    private $keyGenerator;

    /**
     * @var \Everlution\Redlock\Manager\LockTypeManager
     */
    private $lockTypeManager;

    /**
     * defaultLockValidityTime.
     *
     * The default lock validity time if the lock's one is null
     *
     * @var int
     */
    private $defaultLockValidityTime;

    /**
     * retryCount.
     *
     * The number of times to retry to acquire a lock on failure.
     *
     * @var int
     */
    private $retryCount;

    /**
     * retryMaxDelay.
     *
     * The Max time to wait before to retry on failure.
     *
     * @var int
     */
    private $retryMaxDelay;

    public function __construct(
        QuorumInterface $quorum,
        KeyGeneratorInterface $keyGenerator,
        LockTypeManager $lockTypeManager,
        $defaultLockValidityTime,
        $retryCount,
        $retryMaxDelay
    ) {
        $this->adapters                 = array();
        $this->quorum                   = $quorum;
        $this->keyGenerator             = $keyGenerator;
        $this->lockTypeManager          = $lockTypeManager;
        $this->defaultLockValidityTime  = (int) $defaultLockValidityTime;
        $this->retryCount               = (int) $retryCount;
        $this->retryMaxDelay            = (int) $retryMaxDelay;
    }

    public function addAdapter(AdapterInterface $adapter)
    {
        $this->adapters[] = $adapter;

        $this->quorum->setTotal(count($this->adapters));

        return $this;
    }

    public function getAdapters($connected = true)
    {
        if (!$connected) {
            return $this->adapters;
        }

        $adapters = array();
        foreach ($this->adapters as $adapter) {
            if ($adapter->isConnected()) {
                $adapters[] = $adapter;
            }
        }

        return $adapters;
    }

    public function generateKey(Lock $lock)
    {
        return $this
            ->keyGenerator
            ->generate($lock)
        ;
    }

    /**
     * countKeyHits.
     *
     * Counts the number of adapters containing the specified key.
     *
     * @param string $key
     *
     * @return int
     */
    public function countKeyHits($key)
    {
        $hits = 0;
        foreach ($this->adapters as $adapter) {
            if ($adapter->isConnected()) {
                foreach ($adapter->keys($key) as $k) {
                    $hits++;
                }
            }
        }

        return $hits;
    }

    /**
     * getKeysHits.
     *
     * Generates a key => value array having as keys all the keys in every
     * adapter and as value the number of adapters in which the key has been
     * found.
     *
     * @return array
     */
    public function getKeysHits($pattern)
    {
        $result = array();
        foreach ($this->adapters as $adapter) {
            if (!$adapter->isConnected()) {
                continue;
            }
            foreach ($adapter->keys($pattern) as $key) {
                $result[$key] = $this->countKeyHits($key);
            }
        }

        return $result;
    }

    /**
     * getCurrentLocks.
     *
     * Returns the current locks defined in redis.
     *
     * @return array[\Everlution\Redlock\Model\Lock]
     */
    public function getCurrentLocks($pattern)
    {
        $keysHits = $this->getKeysHits($pattern);

        $locks = array();
        foreach ($keysHits as $key => $hits) {
            if ($this->quorum->isApproved($hits)) {
                $locks[] = $this
                    ->keyGenerator
                    ->ungenerate($key, new Lock())
                ;
            }
        }

        return $locks;
    }

    /**
     * canAcquireLock.
     *
     * Verifies whether a lock can be acquired depending by the standing locks.
     *
     * @param LockInterface $lock
     *
     * @return bool
     *
     * @throws InvalidLockTypeException
     */
    public function canAcquireLock(LockInterface $lock)
    {
        if ($this->hasLock($lock)) {
            return true;
        }

        if (!in_array($lock->getType(), $this->lockTypeManager->getAll())) {
            throw new InvalidLockTypeException($lock->getType());
        }

        $pattern = $this
            ->keyGenerator
            ->generate(
                new Lock($lock->getResourceName(), '*', '*')
            )
        ;

        /* @var $currentLock \Everlution\Redlock\Model\Lock */
        foreach ($this->getCurrentLocks($pattern) as $currentLock) {
            $allowedLocks = $this
                ->lockTypeManager
                ->getConcurrentAllowedLocks($currentLock->getType())
            ;
            if (!in_array($lock->getType(), $allowedLocks)) {
                #dump($allowedLocks);die;
                return false;
            }
        }

        return true;
    }

    /**
     * hasLock.
     *
     * Checks whether the lock has already been assigned. The token allowes to
     * differenciate between clients.
     *
     * @param LockInterface $lock
     *
     * @return bool
     */
    public function hasLock(LockInterface $lock)
    {
        $pattern = $this
            ->keyGenerator
            ->generate($lock)
        ;

        return count($this->getCurrentLocks($pattern)) == 1;
    }

    /**
     * getClockDrift.
     *
     * Calculates the clock drift.
     *
     * @return int
     */
    private function getClockDrift()
    {
        return ($this->defaultLockValidityTime * self::CLOCK_DRIFT_FACTOR) + 2;
    }

    private function acquireLockNoRetry(LockInterface $lock, $lockValidityTime)
    {
        $n = 0;

        $key = $this->generateKey($lock);

        /*
         * It's really important for this loop to be as fast as possible
         * as consists in the lock acquisition. The shorter it takes, the
         * less chances we have to be in a situation in which multiple
         * clients lock the same resource.
         */
        foreach ($this->adapters as $adapter) {
            if ($adapter->set($key, $lock->getToken(), $lockValidityTime)) {
                $n++;
            }
        }

        if ($this->quorum->isApproved($n) && $lockValidityTime > 0) {
            return true;
        }

        $this->releaseLock($lock);

        return false;
    }

    /**
     * acquireLock.
     *
     * Acquires a lock or extends the validity time if already acquired.
     *
     * @param LockInterface $lock
     *
     * @return bool
     */
    public function acquireLock(LockInterface $lock)
    {
        if (count($this->adapters) == 0) {
            return false;
        }

        if (!$this->canAcquireLock($lock)) {
            return false;
        }

        $lockValidityTime = $this->defaultLockValidityTime;
        if ($lock->getValidityTime()) {
            $lockValidityTime = $lock->getValidityTime();
        }

        $retries = $this->retryCount;

        do {
            $startTime = microtime(true) * 1000;

            $isAcquired = $this->acquireLockNoRetry($lock, $lockValidityTime);

            if ($isAcquired) {
                return true;
            }

            /*
             * Add 2 milliseconds to the drift to account for Redis expires
             * precision, which is 1 millisecond, plus 1 millisecond min drift
             * for small TTLs.
             */
            $drift = $this->getClockDrift();
            $lockValidityTime = $lockValidityTime - (microtime(true) * 1000 - $startTime) - $drift;

            $this->waitRandomTime();

            $retries--;
        } while ($retries > 0);

        return false;
    }

    /**
     * waitRandomDelay.
     *
     * Makes the script sleep for a random time but using the max delay specified.
     */
    private function waitRandomTime()
    {
        $time = mt_rand(floor($this->retryMaxDelay / 2), $this->retryMaxDelay) * 1000;
        usleep($time);
    }

    /**
     * releaseLock.
     *
     * Releases the specified lock. This method also works as a cleanup when the
     * lock has not been acquired because of the quorum.
     * The return value specifies if the locks was actually acquired (true) or
     * not (false), but this does not influence the removal of the keys in the
     * adapters.
     *
     * @param LockInterface $lock
     *
     * @return bool
     */
    public function releaseLock(LockInterface $lock)
    {
        $key = $this->generateKey($lock);

        $i = 0;
        foreach ($this->adapters as $adapter) {
            if ($adapter->isConnected() && $adapter->del($key)) {
                $i++;
            }
        }

        return $this
            ->quorum
            ->isApproved($i)
        ;
    }

    /**
     * releaseAllLocks.
     *
     * Releases all the locks in every adapter.
     */
    public function releaseAllLocks()
    {
        foreach ($this->getCurrentLocks('*') as $lock) {
            $this->releaseLock($lock);
        }
    }

    /**
     * clearAllLocks.
     *
     * Clears every lock (even the not acquired ones) in every adapter.
     */
    public function clearAllLocks()
    {
        foreach ($this->adapters as $adapter) {
            foreach ($adapter->keys('*') as $key) {
                $adapter->del($key);
            }
        }
    }
}
