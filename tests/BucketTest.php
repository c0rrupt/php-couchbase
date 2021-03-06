<?php
require_once('CouchbaseTestCase.php');

class BucketTest extends CouchbaseTestCase {

    /**
     * @test
     * Test that connections with invalid details fail.
     */
    function testBadPass() {
        $h = new CouchbaseCluster($this->testDsn);

        $this->wrapException(function() use($h) {
            $h->openBucket('default', 'bad_pass');
        }, 'CouchbaseException', 2);
    }

    /**
     * @test
     * Test that connections with invalid details fail.
     */
    function testBadBucket() {
        $h = new CouchbaseCluster($this->testDsn);

        $this->wrapException(function() use($h) {
            $h->openBucket('bad_bucket');
        }, 'CouchbaseException');
    }

    /**
     * @test
     * Test that a connection with accurate details works.
     */
    function testConnect() {
        $h = new CouchbaseCluster($this->testDsn);
        $b = $h->openBucket();
        $this->setTimeouts($b);
        return $b;
    }

    /**
     * @test
     * Test basic upsert
     *
     * @depends testConnect
     */
    function testBasicUpsert($b) {
        $key = $this->makeKey('basicUpsert');

        $res = $b->upsert($key, 'bob');

        $this->assertValidMetaDoc($res, 'cas');

        return $key;
    }

    /**
     * @test
     * Test basic get
     *
     * @depends testConnect
     * @depends testBasicUpsert
     */
    function testBasicGet($b, $key) {
        $res = $b->get($key);

        $this->assertValidMetaDoc($res, 'value', 'cas', 'flags');
        $this->assertEquals($res->value, 'bob');

        return $key;
    }

    /**
     * @test
     * Test basic remove
     *
     * @depends testConnect
     * @depends testBasicGet
     */
    function testBasicRemove($b, $key) {
        $res = $b->remove($key);

        $this->assertValidMetaDoc($res, 'cas');

        // This should throw a not-found exception
        $this->wrapException(function() use($b, $key) {
            $b->get($key);
        }, 'CouchbaseException', COUCHBASE_KEYNOTFOUND);
    }

    /**
     * @test
     * Test multi upsert
     *
     * @depends testConnect
     */
    function testMultiUpsert($b) {
        $keys = array(
            $this->makeKey('multiUpsert'),
            $this->makeKey('multiUpsert')
        );

        $res = $b->upsert(array(
            $keys[0] => array('value'=>'joe'),
            $keys[1] => array('value'=>'jack')
        ));

        $this->assertCount(2, $res);
        $this->assertValidMetaDoc($res[$keys[0]], 'cas');
        $this->assertValidMetaDoc($res[$keys[1]], 'cas');

        return $keys;
    }

    /**
     * @test
     * Test multi upsert with the same keys
     *
     * @depends testConnect
     */
    function testMultiUpsertTheSameKey($b) {
        $key = $this->makeKey('multiUpsert');
        $keys = array($key, $key);

        $res = $b->upsert(array(
            $keys[0] => array('value'=>'joe'),
            $keys[1] => array('value'=>'jack')
        ));

        $this->assertCount(1, $res);
        $this->assertValidMetaDoc($res[$keys[0]], 'cas');
        $this->assertValidMetaDoc($res[$keys[1]], 'cas');
        $this->assertEquals($res[$keys[0]]->cas, $res[$keys[1]]->cas);

        return $keys;
    }

    /**
     * @test
     * Test multi get
     *
     * @depends testConnect
     * @depends testMultiUpsert
     */
    function testMultiGet($b, $keys) {
        $res = $b->get($keys);

        $this->assertCount(2, $res);
        $this->assertValidMetaDoc($res[$keys[0]], 'value', 'flags', 'cas');
        $this->assertEquals($res[$keys[0]]->value, 'joe');
        $this->assertValidMetaDoc($res[$keys[1]], 'value', 'flags', 'cas');
        $this->assertEquals($res[$keys[1]]->value, 'jack');

        return $keys;
    }

    /**
     * @test
     * Test multi remove
     *
     * @depends testConnect
     * @depends testMultiGet
     */
    function testMultiRemove($b, $keys) {
        $res = $b->remove($keys);

        $this->assertCount(2, $res);
        $this->assertValidMetaDoc($res[$keys[0]], 'cas');
        $this->assertValidMetaDoc($res[$keys[1]], 'cas');

        // This should throw a not-found exception
        $res = $b->get($keys);

        $this->assertCount(2, $res);

        // TODO: Different exceptions here might make sense.
        $this->assertErrorMetaDoc($res[$keys[0]], 'CouchbaseException', COUCHBASE_KEYNOTFOUND);
        $this->assertErrorMetaDoc($res[$keys[1]], 'CouchbaseException', COUCHBASE_KEYNOTFOUND);
    }

    /**
     * @test
     * Test basic counter operations w/ an initial value
     *
     * @depends testConnect
     */
    function testCounterInitial($b) {
        $key = $this->makeKey('counterInitial');

        $res = $b->counter($key, +1, array('initial'=>1));
        $this->assertValidMetaDoc($res, 'value', 'flags', 'cas');
        $this->assertEquals(1, $res->value);

        $res = $b->counter($key, +1);
        $this->assertValidMetaDoc($res, 'value', 'flags', 'cas');
        $this->assertEquals(2, $res->value);

        $res = $b->counter($key, -1);
        $this->assertValidMetaDoc($res, 'value', 'flags', 'cas');
        $this->assertEquals(1, $res->value);

        $b->remove($key);
    }

    /**
     * @test
     * Test counter operations on missing keys
     *
     * @depends testConnect
     */
    function testCounterBadKey($b) {
        $key = $this->makeKey('counterBadKey');

        $this->wrapException(function() use($b, $key) {
            $b->counter($key, +1);
        }, 'CouchbaseException', COUCHBASE_KEYNOTFOUND);
    }

    /**
     * @test
     * Test expiry operations on keys
     *
     * @depends testConnect
     */
    function testExpiry($b) {
        $key = $this->makeKey('expiry');

        $b->upsert($key, 'dog', array('expiry' => 1));

        sleep(2);

        $this->wrapException(function() use($b, $key) {
            $b->get($key);
        }, 'CouchbaseException', COUCHBASE_KEYNOTFOUND);
    }

    /**
     * @test
     * Test CAS works
     *
     * @depends testConnect
     */
    function testCas($b) {
        $key = $this->makeKey('cas');

        $res = $b->upsert($key, 'dog');
        $this->assertValidMetaDoc($res, 'cas');
        $old_cas = $res->cas;

        $res = $b->upsert($key, 'cat');
        $this->assertValidMetaDoc($res, 'cas');

        $this->wrapException(function() use($b, $key, $old_cas) {
            $b->replace($key, 'ferret', array('cas'=>$old_cas));
        }, 'CouchbaseException', COUCHBASE_KEYALREADYEXISTS);
    }

    /**
     * @test
     * Test Locks work
     *
     * @depends testConnect
     */
    function testLocks($b) {
        $key = $this->makeKey('locks');

        $res = $b->upsert($key, 'jog');
        $this->assertValidMetaDoc($res, 'cas');

        $res = $b->getAndLock($key, 1);
        $this->assertValidMetaDoc($res, 'value', 'flags', 'cas');

        $this->wrapException(function() use($b, $key) {
            $b->getAndLock($key, 1);
        }, 'CouchbaseException', COUCHBASE_TMPFAIL);
    }

    /**
     * @test
     * Test big upserts
     *
     * @depends testConnect
     */
    function testBigUpsert($b) {
        $key = $this->makeKey('bigUpsert');

        $v = str_repeat("*", 0x1000000);
        $res = $b->upsert($key, $v);

        $this->assertValidMetaDoc($res, 'cas');

        $b->remove($key);

        return $key;
    }

    /**
     * @test
     * Test upsert with no key specified
     *
     * @depends testConnect
     */
     /*
    function testNoKeyUpsert($b) {
        $this->wrapException(function() use($b) {
            $b->upsert('', 'joe');
        }, 'CouchbaseException', COUCHBASE_EINVAL);
    }
    */

    /**
     * @test
     * Test recursive transcoder functions
     */
    function testRecursiveTranscode() {
        global $recursive_transcoder_bucket;
        global $recursive_transcoder_key2;
        global $recursive_transcoder_key3;

        $h = new CouchbaseCluster($this->testDsn);
        $b = $h->openBucket();
        $this->setTimeouts($b);

        $key1 = $this->makeKey('basicUpsertKey1');
        $key2 = $this->makeKey('basicUpsertKey2');
        $key3 = $this->makeKey('basicUpsertKey3');

        // Set up a transcoder that upserts key2 when it sees key1
        $recursive_transcoder_bucket = $b;
        $recursive_transcoder_key2 = $key2;
        $recursive_transcoder_key3 = $key3;
        $b->setTranscoder(
            'recursive_transcoder_encoder',  // defined at bottom of file
            'recursive_transcoder_decoder'); // defined at bottom of file

        // Upsert key1, transcoder should set key2
        $res = $b->upsert($key1, 'key1');
        $this->assertValidMetaDoc($res, 'cas');

        // Check key1 was upserted
        $res = $b->get($key1);
        $this->assertValidMetaDoc($res, 'cas');
        $this->assertEquals($res->value, 'key1');

        // Check key2 was upserted, trasncoder should set key3
        $res = $b->get($key2);
        $this->assertValidMetaDoc($res, 'cas');
        $this->assertEquals($res->value, 'key2');

        // Check key3 was upserted
        $res = $b->get($key3);
        $this->assertValidMetaDoc($res, 'cas');
        $this->assertEquals($res->value, 'key3');
    }

    /**
     * @test
     * Test all option values to make sure they save/load
     * We open a new bucket for this test to make sure our settings
     *   changes do not affect later tests
     */
    function testOptionVals() {
        $h = new CouchbaseCluster($this->testDsn);
        $b = $h->openBucket();
        $this->setTimeouts($b);

        $checkVal = 243;

        $b->operationTimeout = $checkVal;
        $b->viewTimeout = $checkVal;
        $b->durabilityInterval = $checkVal;
        $b->durabilityTimeout = $checkVal;
        $b->httpTimeout = $checkVal;
        $b->configTimeout = $checkVal;
        $b->configDelay = $checkVal;
        $b->configNodeTimeout = $checkVal;
        $b->htconfigIdleTimeout = $checkVal;

        $this->assertEquals($b->operationTimeout, $checkVal);
        $this->assertEquals($b->viewTimeout, $checkVal);
        $this->assertEquals($b->durabilityInterval, $checkVal);
        $this->assertEquals($b->durabilityTimeout, $checkVal);
        $this->assertEquals($b->httpTimeout, $checkVal);
        $this->assertEquals($b->configTimeout, $checkVal);
        $this->assertEquals($b->configDelay, $checkVal);
        $this->assertEquals($b->configNodeTimeout, $checkVal);
        $this->assertEquals($b->htconfigIdleTimeout, $checkVal);
    }

    /**
     * @test
     * Test all option values to make sure they save/load
     * We open a new bucket for this test to make sure our settings
     *   changes do not affect later tests
     */
    function testConfigCache() {
        $this->markTestSkipped(
              'Configuration cache is not currently behaving.'
            );

        $key = $this->makeKey('ccache');

        $h = new CouchbaseCluster(
            $this->testDsn . '?config_cache=./test.cache');
        $b = $h->openBucket();
        $this->setTimeouts($b);
        $b->upsert($key, 'yes');

        $h2 = new CouchbaseCluster(
            $this->testDsn . '?config_cache=./test.cache');
        $b2 = $h2->openBucket();
        $this->setTimeouts($b2);
        $res = $b2->get($key);

        $this->assertValidMetaDoc($res, 'value', 'cas', 'flags');
        $this->assertEquals($res->value, 'yes');
    }

    /**
     * @test
     * @depends testConnect
     */
    function testLookupIn($b) {
        $key = $this->makeKey('lookup_in');
        $b->upsert($key, array('path1' => 'value1'));

        $result = $b->retrieveIn($key, 'path1');
        $this->assertEquals(1, count($result->value));
        $this->assertEquals('value1', $result->value[0]['value']);
        $this->assertEquals(COUCHBASE_SUCCESS, $result->value[0]['code']);
        $this->assertNotEmpty($result->cas);

        # Try when path is not found
        $result = $b->retrieveIn($key, 'path2');
        $this->assertInstanceOf('CouchbaseException', $result->error);
        $this->assertEquals(COUCHBASE_SUBDOC_MULTI_FAILURE, $result->error->getCode());
        $this->assertEquals(1, count($result->value));
        $this->assertEquals(null, $result->value[0]['value']);
        $this->assertEquals(COUCHBASE_SUBDOC_PATH_ENOENT, $result->value[0]['code']);

        # Try when there is a mismatch
        $result = $b->retrieveIn($key, 'path1[0]');
        $this->assertInstanceOf('CouchbaseException', $result->error);
        $this->assertEquals(COUCHBASE_SUBDOC_MULTI_FAILURE, $result->error->getCode());
        $this->assertEquals(1, count($result->value));
        $this->assertEquals(null, $result->value[0]['value']);
        $this->assertEquals(COUCHBASE_SUBDOC_PATH_MISMATCH, $result->value[0]['code']);

        # Try existence
        $result = $b->lookupIn($key)->exists('path1')->execute();
        $this->assertEquals(1, count($result->value));
        $this->assertEquals(COUCHBASE_SUCCESS, $result->value[0]['code']);

        # Not found
        $result = $b->lookupIn($key)->exists('p')->execute();
        $this->assertInstanceOf('CouchbaseException', $result->error);
        $this->assertEquals(COUCHBASE_SUBDOC_MULTI_FAILURE, $result->error->getCode());
        $this->assertEquals(1, count($result->value));
        $this->assertEquals(COUCHBASE_SUBDOC_PATH_ENOENT, $result->value[0]['code']);


        # Insert a non-JSON document
        $key = $this->makeKey('lookup_in_nonjson');
        $b->upsert($key, 'value');

        $result = $b->lookupIn($key)->exists('path')->execute();
        $this->assertEquals(1, count($result->value));
        $this->assertEquals(COUCHBASE_SUBDOC_DOC_NOTJSON, $result->value[0]['code']);

        # Try on non-existing document. Should fail
        $key = $this->makeKey('lookup_in_with_missing_key');
        $result = $b->lookupIn($key)->exists('path')->execute();
        $this->assertEquals(0, count($result->value));
        $this->assertInstanceOf('CouchbaseException', $result->error);
        $this->assertEquals(COUCHBASE_KEY_ENOENT, $result->error->getCode());
    }

    /**
     * @test
     * @expectedException CouchbaseException
     * @expectedExceptionMessageRegExp /EMPTY_PATH/
     * @depends testConnect
     */
    function testLookupInWithEmptyPath($b) {
        $key = $this->makeKey('lookup_in_with_empty_path');
        $b->upsert($key, array('path1' => 'value1'));

        $result = $b->lookupIn($key)->exists('')->execute();
    }

    /**
     * @test
     * @depends testConnect
     */
    function testMutateIn($b) {
        $key = $this->makeKey('mutate_in');
        $b->upsert($key, new stdClass());

        $result = $b->mutateIn($key)->upsert('newDict', array('hello'))->execute();
        $this->assertNull($result->error);
        $result = $b->retrieveIn($key, 'newDict');
        $this->assertEquals(array('hello'), $result->value[0]['value']);

        # Does not create deep path without create_parents
        $result = $b->mutateIn($key)->upsert('path.with.missing.parents', 'value')->execute();
        $this->assertEquals(COUCHBASE_SUBDOC_MULTI_FAILURE, $result->error->getCode());
        $this->assertEquals(1, count($result->value));
        $this->assertEquals(COUCHBASE_SUBDOC_PATH_ENOENT, $result->value[0]['code']);

        # Creates deep path without create_parents
        $result = $b->mutateIn($key)->upsert('path.with.missing.parents', 'value', true)->execute();
        $this->assertNull($result->error);
        $result = $b->retrieveIn($key, 'path.with.missing.parents');
        $this->assertEquals('value', $result->value[0]['value']);

        $this->assertNotEmpty($result->cas);
        $cas = $result->cas;

        $result = $b->mutateIn($key, $cas . 'X')->upsert('newDict', 'withWrongCAS')->execute();
        $this->assertEquals(COUCHBASE_KEY_EEXISTS, $result->error->getCode());
        $this->assertEquals(0, count($result->value));
        # once again with correct CAS
        $result = $b->mutateIn($key, $cas)->upsert('newDict', 'withCorrectCAS')->execute();
        $this->assertNull($result->error);
        $result = $b->retrieveIn($key, 'newDict');
        $this->assertEquals('withCorrectCAS', $result->value[0]['value']);

        # insert into existing path should fail
        $result = $b->mutateIn($key)->insert('newDict', array('foo' => 42))->execute();
        $this->assertEquals(COUCHBASE_SUBDOC_MULTI_FAILURE, $result->error->getCode());
        $this->assertEquals(1, count($result->value));
        $this->assertEquals(COUCHBASE_SUBDOC_PATH_EEXISTS, $result->value[0]['code']);

        # insert into new path should succeed
        $result = $b->mutateIn($key)->insert('anotherDict', array('foo' => 42))->execute();
        $this->assertNull($result->error);
        $result = $b->retrieveIn($key, 'anotherDict');
        $this->assertEquals(array('foo' => 42), $result->value[0]['value']);

        # replace of existing path should not fail
        $result = $b->mutateIn($key)->replace('newDict', array(42 => 'foo'))->execute();
        $this->assertNull($result->error);
        $result = $b->retrieveIn($key, 'newDict');
        $this->assertEquals(array(42 => 'foo'), $result->value[0]['value']);

        # replace of missing path should not fail
        $result = $b->mutateIn($key)->replace('missingDict', array(42 => 'foo'))->execute();
        $this->assertEquals(COUCHBASE_SUBDOC_MULTI_FAILURE, $result->error->getCode());
        $this->assertEquals(1, count($result->value));
        $this->assertEquals(COUCHBASE_SUBDOC_PATH_ENOENT, $result->value[0]['code']);

        $result = $b->mutateIn($key)->upsert('empty', '')->execute();
        $this->assertNull($result->error);
        $result = $b->retrieveIn($key, 'empty');
        $this->assertEquals('', $result->value[0]['value']);

        $result = $b->mutateIn($key)->upsert('null', null)->execute();
        $this->assertNull($result->error);
        $result = $b->retrieveIn($key, 'null');
        $this->assertEquals(null, $result->value[0]['value']);

        $result = $b->mutateIn($key)->upsert('array', array(1, 2, 3))->execute();
        $this->assertNull($result->error);

        $result = $b->mutateIn($key)->upsert('array.newKey', 'newVal')->execute();
        $this->assertEquals(COUCHBASE_SUBDOC_MULTI_FAILURE, $result->error->getCode());
        $this->assertEquals(1, count($result->value));
        $this->assertEquals(COUCHBASE_SUBDOC_PATH_MISMATCH, $result->value[0]['code']);

        $result = $b->mutateIn($key)->upsert('array[0]', 'newVal')->execute();
        $this->assertEquals(COUCHBASE_SUBDOC_MULTI_FAILURE, $result->error->getCode());
        $this->assertEquals(1, count($result->value));
        $this->assertEquals(COUCHBASE_SUBDOC_PATH_EINVAL, $result->value[0]['code']);

        $result = $b->mutateIn($key)->upsert('array[3].bleh', 'newVal')->execute();
        $this->assertEquals(COUCHBASE_SUBDOC_MULTI_FAILURE, $result->error->getCode());
        $this->assertEquals(1, count($result->value));
        $this->assertEquals(COUCHBASE_SUBDOC_PATH_ENOENT, $result->value[0]['code']);
    }

    /**
     * @test
     * @expectedException CouchbaseException
     * @expectedExceptionMessageRegExp /EMPTY_PATH/
     * @depends testConnect
     */
    function testMutationInWithEmptyPath($b) {
        $key = $this->makeKey('lookup_in_with_empty_path');
        $b->upsert($key, array('path1' => 'value1'));

        $result = $b->mutateIn($key)->upsert('', 'value')->execute();
    }

    /**
     * @test
     * @depends testConnect
     */
    function testCounterIn($b) {
        $key = $this->makeKey('mutate_in');
        $b->upsert($key, new stdClass());

        $result = $b->mutateIn($key)->counter('counter', 100)->execute();
        $this->assertNull($result->error);
        $this->assertNotEmpty($result->cas);
        $this->assertEquals(1, count($result->value));
        $this->assertEquals(100, $result->value[0]['value']);

        $result = $b->mutateIn($key)->upsert('not_a_counter', 'foobar')->execute();
        $this->assertNull($result->error);

        $result = $b->mutateIn($key)->counter('not_a_counter', 100)->execute();
        $this->assertEquals(COUCHBASE_SUBDOC_MULTI_FAILURE, $result->error->getCode());
        $this->assertEquals(1, count($result->value));
        $this->assertEquals(COUCHBASE_SUBDOC_PATH_MISMATCH, $result->value[0]['code']);

        $result = $b->mutateIn($key)->counter('path.to.new.counter', 100)->execute();
        $this->assertEquals(COUCHBASE_SUBDOC_MULTI_FAILURE, $result->error->getCode());
        $this->assertEquals(1, count($result->value));
        $this->assertEquals(COUCHBASE_SUBDOC_PATH_ENOENT, $result->value[0]['code']);

        $result = $b->mutateIn($key)->counter('path.to.new.counter', 100, true)->execute();
        $this->assertNull($result->error);
        $this->assertNotEmpty($result->cas);
        $this->assertEquals(1, count($result->value));
        $this->assertEquals(100, $result->value[0]['value']);

        $result = $b->mutateIn($key)->counter('counter', -25)->execute();
        $this->assertNull($result->error);
        $this->assertNotEmpty($result->cas);
        $this->assertEquals(1, count($result->value));
        $this->assertEquals(75, $result->value[0]['value']);

        $result = $b->mutateIn($key)->counter('counter', 0)->execute();
        $this->assertEquals(COUCHBASE_SUBDOC_MULTI_FAILURE, $result->error->getCode());
        $this->assertEquals(1, count($result->value));
        $this->assertEquals(COUCHBASE_SUBDOC_BAD_DELTA, $result->value[0]['code']);
    }

    /**
     * @test
     * @depends testConnect
     */
    function testMultiLookupIn($b) {
        $key = $this->makeKey('multi_lookup_in');
        $b->upsert($key, array(
            'field1' => 'value1',
            'field2' => 'value2',
            'array' =>  array(1, 2, 3),
            'boolean' => false,
        ));

        $result = $b->lookupIn($key)
                ->get('field1')
                ->exists('field2')
                ->exists('field3')
                ->execute();

        $this->assertEquals(COUCHBASE_SUBDOC_MULTI_FAILURE, $result->error->getCode());
        $this->assertEquals(3, count($result->value));

        $this->assertEquals(COUCHBASE_SUCCESS, $result->value[0]['code']);
        $this->assertEquals('value1', $result->value[0]['value']);

        $this->assertEquals(COUCHBASE_SUCCESS, $result->value[1]['code']);
        $this->assertEquals(null, $result->value[1]['value']);

        $this->assertEquals(COUCHBASE_SUBDOC_PATH_ENOENT, $result->value[2]['code']);
        $this->assertEquals(null, $result->value[2]['value']);
    }

    /**
     * @test
     * @depends testConnect
     */
    function testMultiMutateIn($b) {
        $key = $this->makeKey('multi_mutate_in');
        $b->upsert($key, array(
            'field1' => 'value1',
            'field2' => 'value2',
            'array' =>  array(1, 2, 3),
        ));

        $result = $b->mutateIn($key)
                ->replace('field1', array('foo' => 'bar'))
                ->remove('array')
                ->replace('missing', "hello world")
                ->execute();

        $this->assertEquals(COUCHBASE_SUBDOC_MULTI_FAILURE, $result->error->getCode());
        $this->assertEquals(1, count($result->value));
        $this->assertEquals(COUCHBASE_SUBDOC_PATH_ENOENT, $result->value[2]['code']);
    }

    /**
     * @test
     * @depends testConnect
     */
    function testMultiValue($b) {
        $key = $this->makeKey('multi_value');
        $b->upsert($key, array('array' => array()));

        $result = $b->mutateIn($key)->arrayAppend('array', true)->execute();
        $this->assertNull($result->error);

        $result = $b->retrieveIn($key, 'array');
        $this->assertNull($result->error);
        $this->assertEquals(1, count($result->value));
        $this->assertEquals(array(true), $result->value[0]['value']);

        $result = $b->mutateIn($key)->arrayAppendAll('array', array(1, 2, 3))->execute();
        $this->assertNull($result->error);

        $result = $b->retrieveIn($key, 'array');
        $this->assertNull($result->error);
        $this->assertEquals(1, count($result->value));
        $this->assertEquals(array(true, 1, 2, 3), $result->value[0]['value']);

        $result = $b->mutateIn($key)->arrayPrepend('array', array(42))->execute();
        $this->assertNull($result->error);

        $result = $b->retrieveIn($key, 'array');
        $this->assertNull($result->error);
        $this->assertEquals(1, count($result->value));
        $this->assertEquals(array(array(42), true, 1, 2, 3), $result->value[0]['value']);
    }
}

function recursive_transcoder_encoder($value) {
    global $recursive_transcoder_bucket;
    global $recursive_transcoder_key2;
    if ($value == 'key1') {
        $recursive_transcoder_bucket->upsert(
            $recursive_transcoder_key2, 'key2');
    }
    return couchbase_default_encoder($value);
}
function recursive_transcoder_decoder($bytes, $flags, $datatype) {
    global $recursive_transcoder_bucket;
    global $recursive_transcoder_key3;
    $value = couchbase_default_decoder($bytes, $flags, $datatype);
    if ($value == 'key2') {
        $recursive_transcoder_bucket->upsert(
            $recursive_transcoder_key3, 'key3');
    }
    return $value;
}
