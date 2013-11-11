<?php
class StaticPublishingSiteTreeExtension extends DataExtension {

	/**
	 * include all ancestor pages in static publishing queue build, or just one level of parent
	 *
	 * @var boolean
	 */
	public static $include_ancestors = true;

	/**
	 * Extension hook
	 * 
	 */
	public function onAfterPublish() {
		$urls = $this->pagesAffected();
		if(!empty($urls)) {
			URLArrayObject::add_urls($urls);
		}
	}

	/**
	 * Extension hook
	 * 
	 */
	public function onAfterUnpublish() {
		//get all pages that should be removed
		$removePages = $this->owner->pagesToRemoveAfterUnpublish();
		$updateURLs = array();  //urls to republish
		$removeURLs = array();  //urls to delete the static cache from
		foreach($removePages as $page) {
			if ($page instanceof RedirectorPage) $removeURLs[] = $page->regularLink();
			else $removeURLs[] = $page->Link();

			//and update any pages that might have been linking to those pages
			$updateURLs = array_merge((array)$updateURLs, (array)$page->pagesAffected(true));
		}

		increase_time_limit_to();
		increase_memory_limit_to();
		$this->deleteAllCacheFiles($removeURLs); //remove those pages (right now)

		if(!empty($updateURLs)) URLArrayObject::add_urls($updateURLs);
	}

	/**
	 * Removes the unpublished page's static cache file as well as its 'stale.html' copy.
	 * Copied from: FilesystemPublisher->unpublishPages($urls)
	 * 
	 * @param array $urls 
	 */
	public function deleteAllCacheFiles($urls) {
		// Detect a numerically indexed arrays
		if (is_numeric(join('', array_keys($urls)))) $urls = $this->owner->urlsToPaths($urls);

		$cacheBaseDir = $this->owner->getDestDir();

		foreach($urls as $url => $path) {
			// Delete the cache file
			if (file_exists($cacheBaseDir.'/'.$path)) {
				@unlink($cacheBaseDir.'/'.$path);
			}
			// Delete the .stale cache file
			$lastDot = strrpos($path, '.'); //find last dot
			if($lastDot === false) {
				continue;
			}
			$stalePath = substr($path, 0, $lastDot) . '.stale' . substr($path, $lastDot);
			if (file_exists($cacheBaseDir.'/'.$stalePath)) {
				@unlink($cacheBaseDir.'/'.$stalePath);
			}
		}
	}

	/**
	 * 
	 * @return array - an array of SiteTree objects
	 */
	public function pagesToRemoveAfterUnpublish() {
		$pages = array();
		$pages[] = $this->owner;

		// Including VirtualPages with reference this page
		$virtualPages = VirtualPage::get()->filter(array('CopyContentFromID' => $this->owner->ID));
		if ($virtualPages->Count() > 0) {
			foreach($virtualPages as $virtualPage) {
				$pages[] = $virtualPage;
			}
		}

		// Including RedirectorPages with reference this page
		$redirectorPages = RedirectorPage::get()->filter(array('LinkToID' => $this->owner->ID));
		if($redirectorPages->Count() > 0) {
			foreach($redirectorPages as $redirectorPage) {
				$pages[] = $redirectorPage;
			}
		}

		$this->owner->extend('extraPagesToRemove',$this->owner, $pages);

		return $pages;
	}

	/**
	 * 
	 * @param boolean $unpublish
	 * @return array
	 */
	public function pagesAffected($unpublish = false) {
		$urls = array();
		if ($this->owner->hasMethod('pagesAffectedByChanges')) {
			$urls = $this->owner->pagesAffectedByChanges();
		}

		$oldMode = Versioned::get_reading_mode();
		Versioned::reading_stage('Live');

		// We no longer have access to the live page, so can just try to grab the ParentID.
		if ($unpublish) {
			$pageToCache = SiteTree::get()->byID($this->owner->ParentID);
		} else {
			$pageToCache = SiteTree::get()->byID($this->owner->ID);
		}

		if ($pageToCache) {
			// include any related pages (redirector pages and virtual pages)
			$urls = array_merge((array)$urls, (array)$pageToCache->subPagesToCache());
			if($pageToCache instanceof RedirectorPage) {
				$urls = array_merge((array)$urls, (array)$pageToCache->regularLink());
			}
		}

		Versioned::set_reading_mode($oldMode);
		$this->owner->extend('extraPagesAffected',$this->owner, $urls);

		return $urls;
	}

	/**
	 * Get a list of URLs to cache related to this page,
	 * e.g. through custom controller actions or views like paginated lists.
	 *
	 * @return array Of relative URLs
	 */
	public function subPagesToCache() {
		// Add redirector page (if required) or just include the current page
		if($this->owner instanceof RedirectorPage) $link = $this->owner->regularLink();
		else $link = $this->owner->Link();  //higher priority for the actual page, not others
		
		$urls = array($link => 60);

		// Include the parent and the parent's parents, etc
		$parent = $this->owner->Parent();
		if(!empty($parent) && $parent->ID > 0) {
			if(self::$include_ancestors) {
				$urls = array_merge((array)$urls, (array)$parent->subPagesToCache());
			} else {
				$urls = array_merge((array)$urls, (array)$parent->Link());
			}
		}

		// Including VirtualPages with this page as an original
		$virtualPages = VirtualPage::get()->filter(array('CopyContentFromID' => $this->owner->ID));
		if ($virtualPages->Count() > 0) {
			foreach($virtualPages as $virtualPage) {
				$urls = array_merge((array)$urls, (array)$virtualPage->subPagesToCache());
				if($p = $virtualPage->Parent) {
					$urls = array_merge((array)$urls, (array)$p->subPagesToCache());
				}
			}
		}

		// Include RedirectorPages that links to this page
		$redirectorPages = RedirectorPage::get()->filter(array('LinkToID' => $this->owner->ID));
		if($redirectorPages->Count() > 0) {
			foreach($redirectorPages as $redirectorPage) {
				$urls[] = $redirectorPage->regularLink();
			}
		}

		$this->owner->extend('extraSubPagesToCache',$this->owner, $urls);

		return $urls;
	}

	/**
	 * Overriding the static publisher's default functionality to run our on unpublishing logic. This needs to be
	 * here to satisfy StaticPublisher's method call
	 * 
	 * @return array
	 */
	public function allPagesToCache() {
		if (method_exists($this->owner,'allPagesToCache')) {
			return $this->owner->allPagesToCache();
		} else {
			return array();
		}
	}
}