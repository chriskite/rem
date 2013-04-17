<?php
namespace Rem;

class Key
{
    protected static $_key_namespace = 'rem';
    public $rem_id;
    public $method;
    public $arg_hash;

    public static function fromString($str)
    {
        list($rem, $class, $id, $method, $hash) = explode(':', $str);
        $id = new Id($class, $id);
        return new Key($id, $method, $hash);
    }

    public function __construct($rem_id, $method = null, $arg_hash = null)
    {
        $this->rem_id = $rem_id;
        $this->method = $method;
        $this->arg_hash = $arg_hash;
    }

    public static function getIndexKey()
    {
        return self::$_key_namespace . ':index';
    }

    public static function getKeyNamespace()
    {
        return self::$_key_namespace;
    }

    public static function setKeyNamespace($namespace) {
        self::$_key_namespace = $namespace;
    }

    public function getPrefix()
    {
        return $this->rem_id;
    }

    public function getSuffix()
    {
        return $this->method . ':' . $this->arg_hash;
    }

    public function __toString()
    {
        return $this->getPrefix() . ':' . $this->getSuffix();
    }
}