<?php
Use Rem\Rem;

class FakeParent extends Rem
{
    public static function remHydrate($id)
    {
        $class = get_called_class();
        return new $class($id, 0);
    }
}

class FakeChild extends FakeParent
{
    public $name;
    public $age;

    public function __construct($name, $age)
    {
        $this->name = $name;
        $this->age = $age;
    }

    public function remId()
    {
        return $this->name;
    }

    public function _rem_getAge(FakeChild $fake_child)
    {
        return $fake_child->age;
    }
}

class FakeObject extends Rem
{
    public static function remHydrate($id)
    {
        $name = $id;
        $obj = new FakeObject($name);
        $obj->hydrated = true;
        return $obj;
    }

    public function __construct($name)
    {
        $this->name = $name;
        $this->hydrated = false;
    }

    public function remId()
    {
        return $this->name;
    }
}

class Fake extends Rem
{
    public static function remHydrate($id)
    {
        return new Fake();
    }

    public static function fooStatic()
    {
        return 'fooStatic';
    }

    public static function _rem_barStatic()
    {
        return 'barStatic';
    }

    public function remId()
    {
        return '42';
    }

    public static function _rem_staticTime()
    {
        return microtime();
    }

    public function _rem_time()
    {
        return microtime();
    }

    public function foo()
    {
        return 'foo';
    }

    public function _rem_bar()
    {
        return 'bar';
    }

    public function _rem_getObjectName($obj)
    {
        return $obj->name;
    }

    public function _rem_getObjectHydrated($obj)
    {
        return $obj->hydrated;
    }

}

class RemTest extends PHPUnit_Framework_TestCase
{
    protected function setUp()
    {
        $this->redis = new \Predis\Client();
        Rem::remSetRedis($this->redis);

        /* clear existing rem keys from redis */
        foreach ($this->getRemKeys() as $key) {
            $this->redis->del($key);
        }
    }

    protected function tearDown()
    {
    }

    protected function getRemKeys()
    {
        return $this->redis->keys('rem:*');
    }

    public function testMethodCaching()
    {
        $fake = new Fake();

        /* test that Rem doesn't break normal method calls */
        $this->assertEquals('foo', $fake->foo());

        /* test that an uncached method returns the right result */
        $this->assertEquals('bar', $fake->bar());

        /* test that a cached method returns the right result */
        $this->assertEquals('bar', $fake->bar());

        /* change the cached value in redis and test that it is reflected on the instance */
        $keys = $this->redis->keys("rem:Fake:42:*");
        $key = $keys[0];
        $this->redis->hset($key, 'val', serialize('Baz'));
        $this->assertEquals('Baz', $fake->bar());
    }

    public function testStaticMethodCaching()
    {
        /* test that Rem doesn't break normal method calls */
        $this->assertEquals('fooStatic', Fake::fooStatic());

        /* test that an uncached method returns the right result */
        $this->assertEquals('barStatic', Fake::barStatic());

        /* test that a cached method returns the right result */
        $this->assertEquals('barStatic', Fake::barStatic());

        /* change the cached value in redis and test that it is reflected on the instance */
        $keys = $this->redis->keys("rem:Fake::*");
        $key = $keys[0];
        $this->redis->hset($key, 'val', serialize('BazStatic'));
        $this->assertEquals('BazStatic', Fake::barStatic());
    }

    public function testMethodCachingWithObjectArguments()
    {
        $fake = new Fake();
        $fake_obj = new FakeObject('foo');

        /* test that an uncached method returns the right result */
        $this->assertEquals('foo', $fake->getObjectName($fake_obj));

        /* test that a cached method returns the right result */
        $this->assertEquals('foo', $fake->getObjectName($fake_obj));

        /* test that a different argument doesn't get the cached result */
        $another_fake = new FakeObject('bar');
        $this->assertEquals('bar', $fake->getObjectName($another_fake));
    }

    public function testRecache()
    {
        $fake = new Fake();
        $time = $fake->time(); // call time() to cache the result
        $start = microtime();
        $this->assertGreaterThanOrEqual($time, $start);

        $fake->remRecache();

        $new_time = $fake->time();
        $this->assertGreaterThan($start, $new_time);
    }

    public function testRecacheAll()
    {
        $fake = new Fake();
        $time = $fake->time(); // call time() to cache the result
        $start = microtime();
        $this->assertGreaterThanOrEqual($time, $start);

        Rem::remRecacheAll();

        $new_time = $fake->time();
        $this->assertGreaterThan($start, $new_time);
    }

    public function testStaticRecache()
    {
        $time = Fake::staticTime(); // call time() to cache the result
        $start = microtime();
        $this->assertGreaterThanOrEqual($time, $start);

        Fake::remStaticRecache();

        $new_time = Fake::staticTime();
        $this->assertGreaterThan($start, $new_time);
    }

    public function testRecacheWithHydrate()
    {
        $fake = new Fake();
        $fake_obj = new FakeObject('Herp');
        $hydrated = $fake->getObjectHydrated($fake_obj); // call to cache result
        $this->assertFalse($hydrated);

        $fake->remRecache();

        $new_hydrated = $fake->getObjectHydrated($fake_obj);
        $this->assertTrue($new_hydrated);
    }

    public function testInheritedHydration()
    {
        $child = new FakeChild('child', 5);
        $this->assertEquals(5, $child->getAge($child));
        $child->age = 10;
        $this->assertEquals(5, $child->getAge($child));
        $child->remRecache();
        $this->assertEquals(0, $child->getAge($child));
    }
}
