<?php
namespace Rem;

class Id
{
    public $class;
    public $id;

    public function __construct($class, $id = null)
    {
        $this->class = $class;
        $this->id = $id;
    }

    public function __toString()
    {
        return Key::getKeyNamespace() . ':' . "{$this->class}:{$this->id}";
    }
}