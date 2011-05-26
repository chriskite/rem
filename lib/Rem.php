<?

class RemId {
    public function __construct($id) {
        $this->id = $id;
    }
}

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
     * String to prefix each key with in Redis. Defaults to 'rem'.
     * @access protected
     * @var string 
     */
    protected static $_key_prefix = 'rem';

    /**
     * Set the Redis client instance to use.
     * @param Predis\Client $predis
     */
    public static function remSetRedis($predis) {
        self::$_redis = $predis;
    }

    /**
     * Set the expiration time in seconds to use on new
     * cached values by default.
     * @param int $seconds
     */
    public static function remSetDefaultExpiry($seconds) {
        self::$_expiry = $seconds;
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
    public function __call($method, $args) {
        $rem_method = "_rem_$method";
        $value = self::remGetCached($this->remId(), $method, $args);

        if(null === $value) {
            // this method was not cached for this instance, so run the method
            $value = call_user_func_array(array($this, $rem_method), $args);

            // cache the value
            self::remCache($this->remId(), $method, $args, $value);
        }

        return $value;
    }

    public static function remGetCached($id, $method, $args) {
        $key = self::remGetKey($id, $method, $args);
        $value = self::$_redis->hget($key, 'val');
        if(null !== $value && "" !== $value) {
            $value = unserialize($value);
        }
        return $value;
    }

    public static function remCache($id, $method, $args, $value) {
        $key = self::remGetKey($id, $method, $args);
        self::$_redis->hset($key, 'args', serialize($args));
        self::$_redis->hset($key, 'val', serialize($value));
    }

    private static function remGetKey($id, $method, $args) {
        $hash = substr(sha1(self::remSerializeArgs($args)), 9);
        return self::$_key_prefix . ":$id:$method:$hash";
    }

    public static function remSerializeArgs($args) {
        $stringify = function(&$value, $index) {
            if(is_array($value)) {
                $value = self::remSerializeArgs($value);
            } elseif(method_exists($value, 'remId')) {
                $value = new RemId($value->remId());
            }
        };

        $args_copy = $args;
        array_walk_recursive($args_copy, $stringify);
        return serialize($args_copy);
    }

    /**
     * Get the ID string of this instance.
     * This method should be overridden in classes that inherit
     * from Rem.
     * The ID string must uniquely represent a given instance,
     * and must be valid as part of a Redis key.
     * @return string $id the Rem ID string
     */
    public function remId() {
        throw new Exception('remId() called on a class that did not implement it. Classes that inherit from Rem must implement remId().');
    }

}
