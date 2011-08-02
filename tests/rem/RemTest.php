<?php
require_once REM_PATH . '/Rem.php';
require_once('Predis.php');

class FakeObject extends Rem {
    public static function remHydrate($id) {
        $name = $id;
        $obj = new FakeObject($name);
        $obj->hydrated = true;
        return $obj;
    }

    public function __construct($name) {
        $this->name = $name;
        $this->hydrated = false;
    }

    public function remId() {
        return $this->name;
    }
}

class Fake extends Rem {
    public static function remHydrate($id) {
        return new Fake();
    }

    public static function fooStatic() {
        return 'fooStatic';
    }

    public static function _rem_barStatic() {
        return 'barStatic';
    }

    public function remId() {
        return '42';
    }

    public static function _rem_staticTime() {
        return time();
    }

    public function _rem_time() {
        return time();
    }

    public function foo() {
        return 'foo';
    }

    public function _rem_bar() {
        return 'bar';
    }

    public function _rem_getObjectName($obj) {
        return $obj->name;
    }

    public function _rem_getObjectHydrated($obj) {
        return $obj->hydrated;
    }

}

class RemTest extends PHPUnit_Framework_TestCase
{
    protected function setUp() {
        $this->redis = new Predis\Client();
        Rem::remSetRedis($this->redis);
        
        /* clear existing rem keys from redis */
        foreach($this->getRemKeys() as $key) {
            $this->redis->del($key);
        }
    }

    protected function tearDown() {
    }

    protected function getRemKeys() {
        return $this->redis->keys('rem:*');
    }

    public function testMethodCaching() {
        $fake = new Fake();

        /* test that Rem doesn't break normal method calls */
        $this->assertEquals('foo', $fake->foo());
        
        /* test that an uncached method returns the right result */
        $this->assertEquals('bar', $fake->bar());

        /* test that calling 'bar' cached a key in redis */
        $keys = $this->getRemKeys();
        $this->assertEquals(1, count($keys));

        /* test that a cached method returns the right result */
        $this->assertEquals('bar', $fake->bar());

        /* change the cached value in redis and test that it is reflected on the instance */
        $key = $keys[0]; $this->redis->hset($key, 'val', serialize('Baz'));
        $this->assertEquals('Baz', $fake->bar());
    }

    public function testStaticMethodCaching() {
        /* test that Rem doesn't break normal method calls */
        $this->assertEquals('fooStatic', Fake::fooStatic());
        
        /* test that an uncached method returns the right result */
        $this->assertEquals('barStatic', Fake::barStatic());

        /* test that calling 'bar' cached a key in redis */
        $keys = $this->getRemKeys();
        $this->assertEquals(1, count($keys));

        /* test that a cached method returns the right result */
        $this->assertEquals('barStatic', Fake::barStatic());

        /* change the cached value in redis and test that it is reflected on the instance */
        $key = $keys[0];
        $this->redis->hset($key, 'val', serialize('BazStatic'));
        $this->assertEquals('BazStatic', Fake::barStatic());
    }

    public function testMethodCachingWithObjectArguments() {
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

    public function testRecache() {
        $fake = new Fake();
        $time = $fake->time(); // call num_calls() to cache the result
        $start = time();
        $this->assertGreaterThanOrEqual($time, $start);

        sleep(1);
        $fake->remRecache();

        $new_time = $fake->time();
        $this->assertGreaterThan($start, $new_time);
    }

    public function testRecacheAll() {
        $fake = new Fake();
        $time = $fake->time(); // call num_calls() to cache the result
        $start = time();
        $this->assertGreaterThanOrEqual($time, $start);

        sleep(1);
        Rem::remRecacheAll();

        $new_time = $fake->time();
        $this->assertGreaterThan($start, $new_time);
    }

    public function testStaticRecache() {
        $time = Fake::staticTime(); // call num_calls() to cache the result
        $start = time();
        $this->assertGreaterThanOrEqual($time, $start);

        sleep(1);
        Fake::remStaticRecache();

        $new_time = Fake::staticTime();
        $this->assertGreaterThan($start, $new_time);
    }

    public function testRecacheWithHydrate() {
        $fake = new Fake();
        $fake_obj = new FakeObject('Herp');
        $hydrated = $fake->getObjectHydrated($fake_obj); // call to cache result
        $this->assertFalse($hydrated);

        $fake->remRecache();

        $new_hydrated = $fake->getObjectHydrated($fake_obj);
        $this->assertTrue($new_hydrated);
    }
}
?>
