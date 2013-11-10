<?php
/**
 * This is an helper object to StaticPagesQueue to hold an array of urls with
 * priorites to be recached.
 * 
 * If the StaticPagesQueue::is_realtime is false this class will call 
 * StaticPagesQueue::push_urls_to_db when in __destructs.
 *
 */
class URLArrayObject extends ArrayObject {

	/**
	 *
	 * @var URLArrayObject
	 */
	protected static $instance;

	/**
	 *
	 * @staticvar string $instance
	 * @return URLArrayObject
	 */
	protected static function get_instance() {
		static $instance = null;
		if (!self::$instance) {
			self::$instance = new URLArrayObject();
		}
		return self::$instance;
	}

	/**
	 * The format of the urls should be array( 'URLSegment' => '50')
	 *
	 * @param array $urls 
	 */
	public static function add_urls(array $urls) {
		if(!$urls) {
			return;
		}
		
		$urlsAlreadyProcessed = array();    //array to filter out any duplicates
		foreach ($urls as $URLSegment=>$priority) {
			if(is_numeric($URLSegment) && is_string($priority)) {   //case when we have a non-associative flat array
				$URLSegment = $priority;
				$priority = 50;
			}

			//only add URLs of a certain length and only add URLs not already added
			if (self::url_should_be_added($URLSegment, $urlsAlreadyProcessed)) {    
				self::get_instance()->append(array($priority, $URLSegment));
				$urlsAlreadyProcessed[$URLSegment] = true;  //set as already processed
			}
		}

		// Insert into the database directly instead of waiting to destruct time
		if (StaticPagesQueue::is_realtime()) {
			self::get_instance()->insertIntoDB();
		}
	}
	
	/**
	 * Checks if an url should added to the queue
	 * 
	 * @param string $url
	 * @param array $urlsAlreadyProcessed
	 * @return boolean
	 */
	protected static function url_should_be_added($url, $urlsAlreadyProcessed) {
		if(empty($url)) {
			return false;
		}
		
		if(strlen($url) < 1 ) {
			return false;
		}
		
		if(isset($urlsAlreadyProcessed[$url])) {
			return false;
		}
		
		if(self::exclude_from_cache($url)) {
			return false;
		}

		// if the url points to another domain
		if(substr($url,0,4) == "http" && !Config::inst()->get('FilesystemPublisher', 'domain_based_caching')) {
			return false;
		}
		
		return true;
	}

	/**
	 * 
	 * @param string $url
	 * @return boolean
	 */
	protected static function exclude_from_cache($url) {
		$excluded = false;

		//don't publish objects that are excluded from cache
		$candidatePage = SiteTree::get_by_link($url);
		if (!empty($candidatePage)) {
			if (!empty($candidatePage->excludeFromCache)) {
				$excluded = true;
			}
		}

		return $excluded;
	}

	/**
	 * When this class is getting garbage collected, trigger the insert of all 
	 * urls into the database
	 * 
	 */
	public function __destruct() {
		$this->insertIntoDB();
	}

	/**
	 * This method will insert all URLs that exists in this object into the 
	 * database by calling the StaticPagesQueue
	 *
	 * @return type 
	 */
	public function insertIntoDB() {
		$arraycopy = $this->getArrayCopy();
		usort($arraycopy, array(__CLASS__, 'sort_on_priority'));
		foreach ($arraycopy as $array) {
			StaticPagesQueue::add_to_queue($array[0], $array[1]);
		}
		StaticPagesQueue::push_urls_to_db();
		$this->exchangeArray(array());
	}

	/**
	 * Sorts the array on priority, from highest to lowest
	 *
	 * @param array $a
	 * @param array $b
	 * @return int - signed
	 */
	protected function sort_on_priority($a, $b) {
		if ($a[0] == $b[0]) {
			return 0;
		}
		return ($a[0] > $b[0]) ? -1 : 1;
	}

}
