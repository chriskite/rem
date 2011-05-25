<?

class Rem {
    /**
     * Expiration time in seconds for new cached values.
     * By default set to one day.
     * @access protected
     * @var integer
     */
    protected static $_expiry = 86400;

    /**
     * Redis client used to access Redis.
     * @access protected
     * @var Predis\Client
     */
    protected static $_redis;
    
    /**
     * Set the Redis client instance to use.
     * @param Predis\Client $predis
     */
    public static function setRedis($predis) {
        self::$_redis = $predis;
    }

    /**
     * Set the expiration time in seconds to use on new
     * cached values by default.
     * @param int $seconds
     */
    public static function setDefaultExpiry($seconds) {

    }

    /**
     * Recalculate all cached methods for this instance and
     * overwrite the existing values in the cache.
     */
    public function remRecache() {

    }

    /**
     * Remove all cached methods for this instance from the cache.
     */
    public function remInvalidate() {

    }

    /**
     * Intercept calls to undefined functions. Call the
     * corresponding _rem_ method if it exists, and cache the result.
     */
    public function __call() {

    }

}
