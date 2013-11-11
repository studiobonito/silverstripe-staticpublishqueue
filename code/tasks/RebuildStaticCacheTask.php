<?php
/**
 * Similar to {@link RebuildStaticPagesTask}, but only queues pages for republication
 * in the {@link StaticPagesQueue}. This queue is worked off by an independent task running constantly on the server.
 */
class RebuildStaticCacheTask extends BuildTask {

	/**
	 *
	 * @var string
	 */
	protected $description = 'Full cache rebuild: adds all pages on the site to the static publishing queue';

	/**
	 * 
	 */
	public function __construct() {
		parent::__construct();
		if ($this->config()->get('disabled') === true) {
			$this->enabled = false ;
		}
	}

	/**
	 * 
	 * @param SS_HTTPRequest $request
	 */
	public function run($request) {
		ini_set('memory_limit', '512M');
		$oldMode = Versioned::get_reading_mode();
		Versioned::reading_stage('Live');

		$urls = $this->getURLs();

		echo sprintf("StaticPagesQueueAllTask: Queuing %d pages\n", count($urls));
		URLArrayObject::add_urls($urls);

		Versioned::set_reading_mode($oldMode);
	}
	
	/**
	 * 
	 * @return array
	 */
	protected function getURLs() {
		$urls = array();
		$page = singleton('SiteTree');
		if(!empty($_GET['urls'])) {
			return (is_array($_GET['urls'])) ? $_GET['urls'] : explode(',', $_GET['urls']);
		}
		
		if(class_exists('Subsite')) {
			Subsite::disable_subsite_filter(true);
		}
		// memory intensive depending on number of pages
		$pages = DataObject::get("SiteTree");

		foreach($pages as $page) {
			$link = $this->getLinkFromSiteTree($page);
			//sub-pages are not necessary, since this will already include every page on the site
			$urls = array_merge($urls, (array)$link);
		}
		
		if(class_exists('Subsite')) {
			Subsite::disable_subsite_filter(false);
		}
		return $urls;
	}


	/**
	 * 
	 * @param SiteTree $page
	 * @return string
	 */
	protected function getLinkFromSiteTree(SiteTree $page) {
		if(Config::inst()->get('FilesystemPublisher', 'domain_based_caching') && class_exists('Subsite')) {
			return $page->alternateAbsoluteLink();
		}
		if(Config::inst()->get('FilesystemPublisher', 'domain_based_caching')) {
			return $page->AbsoluteLink();
		} 
		if($page instanceof RedirectorPage) {
			return $page->regularLink();
		}
		return $page->Link();
	}
}
