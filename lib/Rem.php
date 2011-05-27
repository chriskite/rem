<?

class RemId {
    public function __construct($id) {
        $this->id = $id;
    }
}

class Rem {
    /**
     * Whether to actually do caching or just pass methods calls through.
     * @access protected
     * @var bool
     */
    protected static $_enabled = true;

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
     * Disable caching, passing calls to the normal instance method instead.
     */
    public static function remDisable() {
        self::$_enabled = false;
    }

    /**
     * Enable caching (the default).
     */
    public static function remEnable() {
        self::$_enabled = true;
    }

    /**
     * Recalculate all cached methods for this instance and
     * overwrite the existing values in the cache.
     */
    public function remRecache() {
       //@TODO implement this 
    }

    /**
     * Remove all cached methods for this instance from the cache.
     */
    public function remInvalidate() {
        $id = $this->remId();
        $key_pattern = self::$_key_prefix . ":$id:*";
        $keys = $this->redis->keys($key_pattern);
        $this->redis->pipeline(function($pipe) {
            foreach($keys as $key) {
                $pipe->del($key);      
            }
        });
    }

    /**
     * Intercept calls to undefined instance methods. Call the
     * corresponding _rem_ method if it exists, and cache the result.
     */
    public function __call($method, $args) {
        return self::remHandleCall($method, $args, $this);
    }

    /**
     * Intercept calls to undefined static methods. Call the
     * corresponding _rem_ method if it exists, and cache the result.
     */
    public static function __callStatic($method, $args) {
        return self::remHandleCall($method, $args);
    }

    private static function remHandleCall($method, $args, $object = null) {
        if(null !== $object && !method_exists($object, 'remId')) {
            throw new Exception("Undefined method '$method' called on class that inherits from Rem, but does not implement remId().");
        }

        $rem_method = "_rem_$method";
        $id = (null === $object) ? get_called_class() : $object->remId(); 

        if(self::$_enabled) {
            $value = self::remGetCached($id, $method, $args);
        }

        if(null === $value) {
            // this method was not cached for this instance, so run the method
            $value = call_user_func_array(array($object ? $object : $id, $rem_method), $args);

            // cache the value
            self::remCache($id, $method, $args, $value);
        }

        return $value;
    }

    private static function remGetCached($id, $method, $args) {
        $key = self::remGetKey($id, $method, $args);
        $value = self::$_redis->hget($key, 'val');
        if(null !== $value && "" !== $value) {
            $value = unserialize($value);
        }
        return $value;
    }

    private static function remCache($id, $method, $args, $value) {
        $key = self::remGetKey($id, $method, $args);
        self::$_redis->hset($key, 'args', serialize($args));
        self::$_redis->hset($key, 'val', serialize($value));
        self::$_redis->expire($key, self::$_expiry);
    }

    private static function remGetKey($id, $method, $args) {
        $hash = substr(sha1(self::remSerializeArgs($args)), 16);
        return self::$_key_prefix . ":$id:$method:$hash";
    }

    private static function remSerializeArgs($args) {
        $stringify = function(&$value, $index) {
            if(is_array($value)) {
                $value = self::remSerializeArgs($value);
            } elseif(method_exists($value, 'remId')) {
                $id = $value->remId();
                if(null === $id || "" === $id) {
                    throw new Exception("remId() must be non-null and non-empty.");
                }
                $value = new RemId($value->remId());
            }
        };

        $args_copy = $args;
        array_walk_recursive($args_copy, $stringify);
        return serialize($args_copy);
    }

}
