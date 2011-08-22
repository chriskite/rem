<?

/**
 * RemSingleton is provided for convenience when you have
 * multiple classes for which each instance should have the
 * same RemId. For example, any singleton class could inherit
 * from RemSingleton to avoid having to specify a remId() method.
 */
class RemSingleton extends Rem {
    public function remGetId() {
        return new RemId(get_called_class());
    }
    public function remId() {
        return '';
    }
}

class RemId {
    public $class;
    public $id;

    public function __construct($class, $id = null) {
        $this->class = $class;
        $this->id = $id;
    }

    public function __toString() {
        return RemKey::getKeyNamespace() . ':' . "{$this->class}:{$this->id}";
    }
}

class RemKey {
    protected static $_key_namespace = 'rem';
    public $rem_id;
    public $method;
    public $arg_hash;

    public function __construct($rem_id, $method = null, $arg_hash = null) {
        $this->rem_id = $rem_id;
        $this->method = $method;
        $this->arg_hash = $arg_hash;
    }

    public static function getIndexKey() {
        return self::$_key_namespace . ':index';
    }

    public static function getKeyNamespace() {
        return self::$_key_namespace;
    }

    public static function setKeyNamespace($namespace) {
        self::$_key_namespace = $namespace;
    }

    public function getPrefix() {
        return $this->rem_id;
    }

    public function getSuffix() {
        return $this->method . ':' . $this->arg_hash;
    }

    public function __toString() {
        return $this->getPrefix() . ':' . $this->getSuffix();
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
     * Recache all static methods for the called class.
     */
    public static function remStaticRecache() {
        $id = new RemId(get_called_class());
        self::remRecacheId($id, $id->class);
    }

    /**
     * Recalculate all cached methods for this instance and
     * overwrite the existing values in the cache.
     */
    public function remRecache() {
        self::remRecacheId($this->remGetId(), $this);
        self::remStaticRecache();
    }

    /**
     * Recalculate all cached methods for all instances,
     * overwrite the existing values in the cache.
     */
    public function remRecacheAll() {
        $keys = self::remAllKeys();
        
        foreach($keys as $key) {
            // get class name and id, then hydrate it if possible
            list($rem, $classname, $id) = explode(':', $key);
            $class = new ReflectionClass($classname);
            if($class->hasMethod('remId') && $class->hasMethod('remHydrate')) {
                $obj = $classname::remHydrate($id);
                if(null === $obj) {
                    error_log("remHydrate() returned null for $classname:$id, invalidating its cache...");
                    Rem::remDeleteCacheForId(new RemId($classname, $id));
                } else {
                    $obj->remRecache();
                }
            } elseif ("" == $id) {
                $classname::remStaticRecache();
            } else {
                error_log("Cannot recache $classname:$id because it is missing remId() or remHydrate()");
            }
        }
    }

    /**
     * Iterate over this instance's remDependents and remRecache them.
     */
    public function remRecacheDependents() {
        $class = new ReflectionClass($this);
        if($class->hasMethod('remDependents')) {
            foreach($this->remDependents() as $dependent) {
                $dependent_class = new ReflectionClass($dependent);
                if(!$dependent_class->isSubclassOf('Rem')) {
                    throw new Exception("Dependent of '$class' does not inherit from Rem");
                }
                $dependent->remRecache();
            }
        }
    }

    private function remGetId() {
        return new RemId(get_called_class(), $this->remId());
    }

    /**
     * Remove all cached methods for this instance from the cache.
     */
    public function remInvalidate() {
        Rem::remDeleteCacheForId($this->remGetId());
    }

    /**
     * Delete all keys for a RemId from Redis.
     *
     * @param string $pattern
     */
    public static function remDeleteCacheForId(RemId $id) {
        $suffixes = self::$_redis->smembers($id->getKey());
        self::$_redis->pipeline(function($pipe) use ($suffixes) {
            foreach($suffixes as $suffix) {
                $key = $id_key . ":" . $suffix;
                self::remDeleteKey($key, $pipe);
            }
        });
    }

    /**
     * Clear the entire cache.
     */
    public static function remClear() {
        $keys = self::remAllKeys();
        self::$_redis->pipeline(function($pipe) use ($keys) {
            foreach($keys as $key) {
                self::remDeleteKey($key, $pipe);      
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

    /**
     * Recache an individual key for class/instance
     * @param string $key
     * @param string $id
     * @param object $binding
     */
    private static function remRecacheKey(RemKey $key, RemId $id, $binding) {
        // get the arguments 
        $arg_string = self::$_redis->hget($key, 'args');
        $args = unserialize($arg_string);
        if(false === $args) {
            self::remInvalidateKey($key);
            throw new Exception("Unable to unserialize arg string '$arg_string'. Invalidating key '$key'.");
        }

        foreach($args as &$arg) {
            if(is_object($arg)) {
                $class = new ReflectionClass($arg);
                if(method_exists($arg, 'remId') && $class->hasMethod('remHydrate')) {
                    $method = $class->getMethod('remHydrate');
                    $arg = $method->invokeArgs(null, array($arg->remId()));
                }
            }
        }

        // run the method with the arguments
        $method_name = $key->method;
        try {
            $class = new ReflectionClass($binding);
            $method = $class->getMethod('_rem_' . $method_name);
            if($method->isStatic() && is_string($binding)) {
                $result = $method->invokeArgs(null, $args);
            } elseif(!$method->isStatic() && is_object($binding)) {
                $result = $method->invokeArgs($binding, $args);
            }
        } catch(Exception $e) {
            // since the method call failed, destroy this cached key
            self::remInvalidateKey($key);
            throw $e;
        }

        // cache that method result
        self::remCache($id, $method_name, $args, $result);
    }

    private static function remRecacheId(RemId $id, $binding) {
        if(null === $id) {
            throw new Exception("id must be non-null and non-empty.");
        }

        $suffixes = self::$_redis->smembers($id);
        // foreach cached methods for this id/class
        foreach($suffixes as $suffix) {
            list($method, $arg_hash) = explode(':', $suffix);
            $key = new RemKey($id, $method, $arg_hash);
            self::remRecacheKey($key, $id, $binding);
        }
    }

    /**
     * Delete the cached key from Redis
     * @param string $key
     */
    private static function remInvalidateKey(RemKey $key) {
        self::remDeleteKey($key);      
    }

    private static function remDeleteKey(RemKey $key, $pipe = null) {
        if(null === $pipe) {
            $pipe = self::$_redis;
        }
        $pipe->del($key);
    }

    /**
     * Handle a static or instance call, either caching the result
     * or returning the cached result.
     */
    private static function remHandleCall($method, $args, $object = null) {
        if(null !== $object && !method_exists($object, 'remId')) {
            throw new Exception("Undefined method '$method' called on class that inherits from Rem, but does not implement remId().");
        }

        $rem_method = "_rem_$method";
        // check that the rem method exists
        $reflect = new ReflectionClass((null === $object) ? get_called_class() : $object);
        if(!$reflect->hasMethod($rem_method)) {
            throw new Exception("Undefined method '$method' called, and no corresponding '$rem_method' method defined.");
        }

        $id = (null === $object) ? new RemId(get_called_class()) : new RemId(get_called_class(), $object->remId());

        if(null === $id) {
            throw new Exception("remId() must be non-null and non-empty.");
        }

        if(self::$_enabled) {
            $value = self::remGetCached($id, $method, $args);
        }

        if(null === $value) {
            // this method was not cached for this instance, so run the method
            $value = call_user_func_array(array($object ? $object : $id->class, $rem_method), $args);

            // cache the value

            if(self::$_enabled) {
                self::remCache($id, $method, $args, $value);
            }
        }

        return $value;
    }

    /**
     * Get a cached value from Redis.
     * @param string $id
     * @param string $method
     * @param array $args
     * @return mixed unserialized value
     */
    private static function remGetCached($id, $method, $args) {
        $key = self::remCreateKey($id, $method, $args);
        $value = self::$_redis->hget($key, 'val');
        if(null !== $value && "" !== $value) {
            $value = unserialize($value);
        }
        return $value;
    }

    /**
     * Store a value serialized into the cache.
     * @param string $id
     * @param string $method
     * @param array $args
     * @param mixed $value
     */
    private static function remCache(RemId $id, $method, $args, $value) {
        $key = self::remCreateKey($id, $method, $args);
        self::$_redis->hset($key, 'args', serialize($args));
        self::$_redis->hset($key, 'val', serialize($value));
        self::$_redis->expire($key, self::$_expiry);
    }

    private static function remAddIndex(RemKey $key) {
        self::$_redis->sadd(RemKey::getIndexKey(), $key->getPrefix());
        self::$_redis->sadd($key->getPrefix(), $key->getSuffix());
    }

    private static function remDeleteIndex(RemKey $key) {
        self::$_redis->srem(RemKey::getIndexKey(), $key->getPrefix());
        self::$_redis->srem($key->getPrefix(), $key->getSuffix());
    }

    /**
     * Create the Redis key string for the values.
     * @param string $id
     * @param string $method
     * @param array $args
     * @return string
     */
    private static function remCreateKey($id, $method, $args) {
        $hash = substr(sha1(self::remSerializeArgs($args)), 12);
        $key = new RemKey($id, $method, $hash);
        self::remAddIndex($key);
        return $key;
    }

    /**
     * Create a serialized string from arguments. 
     * Used to hash and index keys.
     * @param array $args
     * @return string
     */
    private static function remSerializeArgs($args) {
        $stringify = function(&$value, $index) {
            if(is_array($value)) {
                $value = self::remSerializeArgs($value);
            } elseif(!is_string($value) && method_exists($value, 'remId')) {
                $id = $value->remId();
                if(null === $id || "" === $id) {
                    throw new Exception("remId() must be non-null and non-empty.");
                }
                $reflection = new ReflectionClass($value);
                $value = new RemId($reflection->getName(), $value->remId());
            }
        };

        $args_copy = $args;
        array_walk_recursive($args_copy, $stringify);
        return serialize($args_copy);
    }

    private static function remAllKeys() {
        $keys = array();
        $prefix_key = RemKey::getIndexKey();
        $prefixes = self::$_redis->smembers($prefix_key);
        foreach($prefixes as $prefix) {
            $suffixes = self::$_redis->smembers($prefix);
            foreach($suffixes as $suffix) {
                $keys[] = $prefix . ":" . $suffix;
            }
        }
        return $keys;
    }

}
