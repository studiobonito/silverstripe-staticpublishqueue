<?php

class SiteTreePublishingEngineTest extends SapphireTest {

	function testCollectChangesForPublishing() {

		$obj = Object::create('SiteTreePublishingEngineTest_StaticPublishingTrigger');
		$obj->collectChanges(array('action'=>'publish'));

		$this->assertEquals(
			$obj->getToUpdate(),
			array('/updateOnPublish?_ID=1&_ClassName=StaticallyPublishableTest'=>10)
		);
		$this->assertEquals(
			$obj->getToDelete(),
			array('/deleteOnPublish?_ID=1&_ClassName=StaticallyPublishableTest'=>10)
		);

	}

	function testCollectChangesForUnpublishing() {

		$obj = Object::create('SiteTreePublishingEngineTest_StaticPublishingTrigger');
		$obj->collectChanges(array('action'=>'unpublish'));

		$this->assertEquals(
			$obj->getToUpdate(),
			array('/updateOnUnpublish?_ID=1&_ClassName=StaticallyPublishableTest'=>10)
		);
		$this->assertEquals(
			$obj->getToDelete(),
			array('/deleteOnUnpublish?_ID=1&_ClassName=StaticallyPublishableTest'=>10)
		);

	}

	function testFlushChanges() {

		$toUpdate = array('/toUpdate?_ID=1&_ClassName=StaticallyPublishableTest'=>10);
		$toDelete = array('/toDelete?_ID=1&_ClassName=StaticallyPublishableTest'=>10);

		$urlArrayObjectClass = $this->getMockClass('URLArrayObject', array('add_urls'));
		Injector::inst()->registerNamedService('URLArrayObject', new $urlArrayObjectClass);
		$urlArrayObjectClass::staticExpects($this->once())
			->method('add_urls')
			->with($this->equalTo($toUpdate));

		$stub = $this->getMock(
			'SiteTreePublishingEngineTest_StaticPublishingTrigger',
			array('unpublishPagesAndStaleCopies')
		);
		$stub->expects($this->once())
			->method('unpublishPagesAndStaleCopies')
			->with($this->equalTo($toDelete));

		$stub->setToUpdate($toUpdate);
		$stub->setToDelete($toDelete);

		$stub->flushChanges();

		$this->assertEquals($stub->getToUpdate(), array(), 'The update cache has been flushed.');
		$this->assertEquals($stub->getToDelete(), array(), 'The delete cache has been flushed.');

	}

	function testUnpublishPagesAndStaleCopiesNoObject() {
		$toDelete = array('/toDelete'=>10);

		$stub = $this->getMock(
			'SiteTreePublishingEngineTest_StaticPublishingTrigger',
			array('urlsToPaths', 'deleteCacheFile')
		);
		$stub->expects($this->any())
			->method('urlsToPaths')
			->will($this->returnValue(array('toDelete'=>'toDelete.html')));

		$stub->expects($this->any())
			->method('deleteCacheFile')
			->will($this->onConsecutiveCalls(
				'toDelete.html',
				'toDelete.stale.html')
			);
		$stub->unpublishPagesAndStaleCopies($toDelete);

	}

	function testUnpublishPagesAndStaleCopiesForMainSite() {
		$toDelete = array('/toDelete?_ID=1&_ClassName=SiteTreePublishingEngineTest_StaticallyPublishable'=>10);
		Config::inst()->nest();
		Config::inst()->update('FilesystemPublisher', 'static_base_url', 'http://foo/bar');
		Config::inst()->update('Director', 'alternative_base_url', 'http://foo/bar');

		$page = $this->getMock('SiteTreePublishingEngineTest_StaticallyPublishable');
		$page->SubsiteID = 0;

		$urlArrayObjectClass = $this->getMockClass('URLArrayObject', array('get_object'));
		Injector::inst()->registerNamedService('URLArrayObject', new $urlArrayObjectClass);
		$urlArrayObjectClass::staticExpects($this->any())
			->method('get_object')
			->will($this->returnValue(
				$page
			));

		$stub = $this->getMock(
			'SiteTreePublishingEngineTest_StaticPublishingTrigger',
			array('urlsToPaths', 'deleteCacheFile')
		);
		$stub->expects($this->any())
			->method('urlsToPaths')
			->will($this->returnValue(array('toDelete'=>'toDelete.html')));

		$stub->expects($this->any())
			->method('deleteCacheFile')
			->will($this->onConsecutiveCalls(
				'http://foo/bar/toDelete.html',
				'http://foo/bar/toDelete.stale.html')
			);
		$stub->unpublishPagesAndStaleCopies($toDelete);

		Config::inst()->unnest();
	}

	function testUnpublishPagesAndStaleCopiesForSubsite() {
		$toDelete = array('/toDelete?_ID=1&_ClassName=SiteTreePublishingEngineTest_StaticallyPublishable'=>10);
		Config::inst()->nest();
		Config::inst()->update('FilesystemPublisher', 'static_base_url', 'http://foo/bar');
		Config::inst()->update('Director', 'alternative_base_url', 'http://foo/bar');

		// Mock a set of objects pretending to support Subsites.
		$domain1 = $this->getMock('SubsiteDomain');
		$domain1->Domain = 'subiste1.domain.org';
		$domain2 = $this->getMock('SubsiteDomain');
		$domain2->Domain = 'subiste2.domain.org';

		$domains = Object::create('ArrayList', array($domain1, $domain2));

		$subsite = $this->getMock('Subsite');
		$subsite->expects($this->any())
			->method('Domains')
			->will($this->returnValue($domains));

		$page = $this->getMock('SiteTreePublishingEngineTest_StaticallyPublishable');
		$page->SubsiteID = 1;
		$page->expects($this->any())
			->method('Subsite')
			->will($this->returnValue(
				$subsite
			));

		// Mock statics.
		$urlArrayObjectClass = $this->getMockClass('URLArrayObject', array('get_object'));
		Injector::inst()->registerNamedService('URLArrayObject', new $urlArrayObjectClass);
		$urlArrayObjectClass::staticExpects($this->any())
			->method('get_object')
			->will($this->returnValue($page));

		// Excercise the function.
		$stub = $this->getMock(
			'SiteTreePublishingEngineTest_StaticPublishingTrigger',
			array('urlsToPaths', 'deleteCacheFile')
		);
		$stub->expects($this->any())
			->method('urlsToPaths')
			->will($this->returnValue(array('toDelete'=>'toDelete.html')));

		$stub->expects($this->any())
			->method('deleteCacheFile')
			->will($this->onConsecutiveCalls(
				'http://subsite1.domain.org/bar/toDelete.html',
				'http://subsite1.domain.org/bar/toDelete.stale.html',
				'http://subsite2.domain.org/bar/toDelete.html',
				'http://subsite2.domain.org/bar/toDelete.stale.html'
			));
		$stub->unpublishPagesAndStaleCopies($toDelete);

		Config::inst()->unnest();
	}
}

class SiteTreePublishingEngineTest_StaticallyPublishable extends SiteTree implements TestOnly, StaticallyPublishable {

	public $url;
	public $prio;

	public function getClassName() {
		return 'StaticallyPublishableTest';
	}

	public function getID() {
		return '1';
	}

	public function urlsToCache() {
		return array($this->url => $this->prio);
	}

}

class SiteTreePublishingEngineTest_StaticPublishingTrigger extends SiteTree implements TestOnly, StaticPublishingTrigger {

	private $extensions = array(
		'SiteTreePublishingEngine'
	);

	public function generatePublishable($url, $prio) {
		$obj = Object::create('SiteTreePublishingEngineTest_StaticallyPublishable');
		$obj->url = $url;
		$obj->prio = $prio;

		return $obj;
	}

	public function objectsToUpdate($context) {

		switch ($context['action']) {
			case 'publish':
				return new ArrayList(array($this->generatePublishable('/updateOnPublish', 10)));
			case 'unpublish':
				return new ArrayList(array($this->generatePublishable('/updateOnUnpublish', 10)));
		}

	}

	/**
	 * Remove the object on unpublishing (the parent will get updated via objectsToUpdate).
	 */
	public function objectsToDelete($context) {

		switch ($context['action']) {
			case 'publish':
				return new ArrayList(array($this->generatePublishable('/deleteOnPublish', 10)));
			case 'unpublish':
				return new ArrayList(array($this->generatePublishable('/deleteOnUnpublish', 10)));
		}

	}

}
