<?php
namespace Rem;
/**
 * RemSingleton is provided for convenience when you have
 * multiple classes for which each instance should have the
 * same RemId. For example, any singleton class could inherit
 * from RemSingleton to avoid having to specify a remId() method.
 */
class Singleton extends Rem
{
    public function remGetId()
    {
        return new Id(get_called_class());
    }

    public function remId()
    {
        return '';
    }
}
