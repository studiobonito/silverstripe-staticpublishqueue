<?php
/**
 * This extension couples to the StaticallyPublishable and StaticPublishingTrigger implementations
 * on the SiteTree objects and makes sure the actual change to SiteTree is triggered/enqueued.
 *
 * Provides the following information as a context to StaticPublishingTrigger:
 * * action - name of the executed action: publish or unpublish
 *
 * @see PublishableSiteTree
 */

class SiteTreePublishingEngine extends DataExtension {

	/**
	 * Queues the urls to be flushed into the queue.
	 */
	private $toUpdate = array();

	/**
	 * Queues the urls to be deleted as part of a next flush operation.
	 */
	private $toDelete = array();

	public function getToUpdate() {
		return $this->toUpdate;
	}

	public function getToDelete() {
		return $this->toDelete;
	}

	public function setToUpdate($toUpdate) {
		$this->toUpdate = $toUpdate;
	}

	public function setToDelete($toDelete) {
		$this->toDelete = $toDelete;
	}

	public function onAfterPublish() {
		$context = array(
			'action' => 'publish'
		);
		$this->collectChanges($context);
		$this->flushChanges();
	}

	public function onBeforeUnpublish() {
		$context = array(
			'action' => 'unpublish'
		);
		$this->collectChanges($context);
	}

	public function onAfterUnpublish() {
		$this->flushChanges();
	}

	/**
	 * Collect all changes for the given context.
	 */
	public function collectChanges($context) {
		$urlArrayObject = Injector::inst()->get('URLArrayObject');

		increase_time_limit_to();
		increase_memory_limit_to();

		if (is_callable(array($this->owner, 'objectsToUpdate'))) {

			$toUpdate = $this->owner->objectsToUpdate($context);

			if ($toUpdate) foreach ($toUpdate as $object) {
				if (!is_callable(array($this->owner, 'urlsToCache'))) continue;

				$urls = $object->urlsToCache();
				if(!empty($urls)) {
					$this->toUpdate = array_merge(
						$this->toUpdate,
						$urlArrayObject::add_objects($urls, $object)
					);
				}

			}
		}

		if (is_callable(array($this->owner, 'objectsToDelete'))) {

			$toDelete = $this->owner->objectsToDelete($context);

			if ($toDelete) foreach ($toDelete as $object) {
				if (!is_callable(array($this->owner, 'urlsToCache'))) continue;

				$urls = $object->urlsToCache();
				if(!empty($urls)) {
					$this->toDelete = array_merge(
						$this->toDelete,
						$urlArrayObject::add_objects($urls, $object)
					);
				}
			}

		}

	}

	/**
	 * Execute URL deletions, enqueue URL updates.
	 */
	public function flushChanges() {
		$urlArrayObject = Injector::inst()->get('URLArrayObject');

		if(!empty($this->toUpdate)) {
			$urlArrayObject::add_urls($this->toUpdate);
			$this->toUpdate = array();
		}

		if(!empty($this->toDelete)) {
			$this->owner->unpublishPagesAndStaleCopies($this->toDelete);
			$this->toDelete = array();
		}
	}

	/**
	 * Delete cache file, if exists.
	 *
	 * @param string Path of the file to delete within the cache dir.
	 */
	public function deleteCacheFile($path) {
		$cacheBaseDir = $this->owner->getDestDir();
		if (file_exists($cacheBaseDir.'/'.$path)) {
			@unlink($cacheBaseDir.'/'.$path);
		}
	}

	/**
	 * Removes the unpublished page's static cache file as well as its 'stale.html' copy.
	 *
	 * This function is subsite-aware: these files could either sit in the top-level cache (no subsites),
	 * or sit in the subdirectories (main site and subsites).
	 *
	 * See BuildStaticCacheFromQueue::createCachedFiles for similar subsite-specific conditional handling.
	 *
	 * @param $urls array associative array of url => priority
	 */
	public function unpublishPagesAndStaleCopies($urls) {
		// Inject static objects.
		$urlArrayObject = Injector::inst()->get('URLArrayObject');
		$director = Injector::inst()->get('Director');

		$paths = array();
		foreach($urls as $url => $priority) {
			$obj = $urlArrayObject::get_object($url);

			if (!$obj || !$obj->hasExtension('SiteTreeSubsites')) {
				// Normal processing for files directly in the cache folder.
				$paths = array_merge($paths, $this->owner->urlsToPaths(array($url)));

			} else {
				// Subsites support detected: figure out all files to delete in subdirectories.

				Config::inst()->nest();

				// Subsite page requested. Change behaviour to publish into directory.
				Config::inst()->update('FilesystemPublisher', 'domain_based_caching', true);

				// Pop the base-url segment from the url.
				if (strpos($url, '/')===0) $cleanUrl = $director::makeRelative($url);
				else $cleanUrl = $director::makeRelative('/' . $url);

				if ($obj->SubsiteID==0) {
					// Main site page - but publishing into subdirectory.
					$staticBaseUrl = Config::inst()->get('FilesystemPublisher', 'static_base_url');
					$paths = array_merge($paths, $this->owner->urlsToPaths(array($staticBaseUrl . '/' . $cleanUrl)));
				} else {
					// Subsite page. Generate all domain variants registered with the subsite.
					$subsite = $obj->Subsite();
					foreach($subsite->Domains() as $domain) {
						$paths = array_merge($paths, $this->owner->urlsToPaths(
							array('http://'.$domain->Domain . $director::baseURL() . $cleanUrl)
						));
					}
				}

				Config::inst()->unnest();
			}

		}

		foreach($paths as $url => $path) {
			// Delete the master file.
			$this->owner->deleteCacheFile($path);

			// Delete the "stale" file.
			$lastDot = strrpos($path, '.'); //find last dot
			if ($lastDot !== false) {
				$stalePath = substr($path, 0, $lastDot) . '.stale' . substr($path, $lastDot);
				$this->owner->deleteCacheFile($stalePath);
			}
		}
	}

}
