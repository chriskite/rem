<?php
require_once REM_PATH . '/Rem.php';
require_once('Predis.php');

class FakeObject extends Rem {
    public function __construct($name) {
        $this->name = $name;
    }

    public function remId() {
        "FakeObject." . $this->name;
    }
}

class Fake extends Rem {
    public function remId() {
        return 'Fake.42';
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
        $key = $keys[0];
        $this->redis->hset($key, 'val', serialize('Baz'));
        $this->assertEquals('Baz', $fake->bar());
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


//    public function testCache() {
//        $value = array(1, 'foo');
//        Rem::remCache('Fake.42', 'get', array(), $value);
//        $cached = Rem::remGetCached('Fake.42', 'get', array());
//        $this->assertEquals($value, $cached);
//    }
//
//    public function testRemSerializeArgs() {
//        $test_args = array(1, new Fake(), array(2, new Fake()));
//        $expected = serialize(array(1, new RemId('Fake.42'), array(2, new RemId('Fake.42'))));
//        $this->assertEquals($expected, Rem::remSerializeArgs($test_args));
//    }
}
?>
