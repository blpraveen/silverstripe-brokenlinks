<?php

class CheckLinksTask extends BuildTask {

	private static $dependencies = array(
		'LinkChecker' => '%$LinkChecker'
	);

	/**
	 * @var bool
	 */
	protected $silent = false;

	/**
	 * @var LinkChecker
	 */
	protected $linkChecker;

	protected $title = 'Checking broken  links in the SiteTree';

	protected $description = 'A task that records  broken links in the SiteTree';

	protected $enabled = true;

	/**
	 * Log a message
	 *
	 * @param string $message
	 */
	protected function log($message) {
		if(!$this->silent) Debug::message($message);
	}

	public function run($request) {
		$this->runLinksCheck();
	}
	/**
	 * Turn on or off message output
	 *
	 * @param bool $silent
	 */
	public function setSilent($silent) {
		$this->silent = $silent;
	}

	/**
	 * @param LinkChecker $linkChecker
	 */
	public function setLinkChecker(LinkChecker $linkChecker) {
		$this->linkChecker = $linkChecker;
	}

	/**
	 * @return LinkChecker
	 */
	public function getLinkChecker() {
		return $this->linkChecker;
	}

	/**
	 * Check the status of a single link on a page
	 *
	 * @param BrokenLinkPageTrack $pageTrack
	 * @param DOMNode $link
	 */
	protected function checkPageLink(BrokenLinkPageTrack $pageTrack, DOMNode $link) {
		$class = $link->getAttribute('class');
		$href = $link->getAttribute('href');
		$markedBroken = preg_match('/\b(ss-broken)\b/', $class);
		$text = $link->nodeValue;

		// Check link
		$httpCode = $this->linkChecker->checkLink($href);
		if($httpCode === null) return; // Null link means uncheckable, such as an internal link

		// If this code is broken then mark as such
		if($foundBroken = $this->isCodeBroken($httpCode)) {
			// Create broken record
			$brokenLink = new BrokenLink();
			$brokenLink->Link = $href;
			$brokenLink->Text = $text;
			$brokenLink->HTTPCode = $httpCode;
			$brokenLink->TrackID = $pageTrack->ID;
			$brokenLink->StatusID = $pageTrack->StatusID; // Slight denormalisation here for performance reasons
			$brokenLink->write();
		}

		// Check if we need to update CSS class, otherwise return
		if($markedBroken == $foundBroken) return;
		if($foundBroken) {
			$class .= ' ss-broken';
		} else {
			$class = preg_replace('/\s*\b(ss-broken)\b\s*/', ' ', $class);
		}
		$link->setAttribute('class', trim($class));
	}

	/**
	 * Determine if the given HTTP code is "broken"
	 *
	 * @param int $httpCode
	 * @return bool True if this is a broken code
	 */
	protected function isCodeBroken($httpCode) {
		// Null represents no request attempted
		if($httpCode === null) return false;

		// do we have any whitelisted codes
		$ignoreCodes = Config::inst()->get('CheckLinks', 'IgnoreCodes');
		if(is_array($ignoreCodes) && in_array($httpCode, $ignoreCodes)) return false;

		// Check if code is outside valid range
		return $httpCode < 200 || $httpCode > 302;
	}

	/**
	 * Runs the links checker and returns the track used
	 *
	 * @param int $limit Limit to number of pages to run, or null to run all
	 * @return BrokenLinkPageTrackStatus
	 */
	public function runLinksCheck($limit = null) {
		// Check the current status
		$status = BrokenLinkPageTrackStatus::get_or_create();

		// Calculate pages to run
		$pageTracks = $status->getIncompleteTracks();
		if($limit) $pageTracks = $pageTracks->limit($limit);

		// Check each page
		foreach ($pageTracks as $pageTrack) {
			// Flag as complete
			$pageTrack->Processed = 1;
			try{
				$pageTrack->write();
			} catch(ValidationException $e)  {
				$this->log("Error PageID:{$pageTrack->ID} Message:: {$e->getResult()->message()}");
			} catch(Exception $e)  {
				$this->log("Error PageID:{$pageTrack->ID} Message:: {$e->message}");
			} 

			// Check value of html area
			$page = $pageTrack->Page();
			$this->log("Checking {$page->Title}");
			$htmlValue = Injector::inst()->create('HTMLValue', $page->Content);
			if (!$htmlValue->isValid()) continue;

			// Check each link
			$links = $htmlValue->getElementsByTagName('a');
			foreach($links as $link) {
				$this->checkPageLink($pageTrack, $link);
			}

			// Update content of page based on link fixes / breakages
			/*$htmlValue->saveHTML();
			$page->Content = $htmlValue->getContent();
			try{
				$page->write();
			} catch(ValidationException $e)  {
				$this->log("Error PageID:{$page->ID} Message:: {$e->getResult()->message()}");
			} catch(Exception $e)  {
				$this->log("Error PageID:{$page->ID} Message:: {$e->message}");
			}
			*/
			// Once all links have been created for this page update HasBrokenLinks
			$count = $pageTrack->BrokenLinks()->count();
			$this->log("Found {$count} broken links");
			if($count) {
				// Bypass the ORM as syncLinkTracking does not allow you to update HasBrokenLink to true
				DB::query(sprintf(
					'UPDATE "SiteTree" SET "HasBrokenLink" = 1 WHERE "ID" = \'%d\'',
					intval($pageTrack->ID)
				));
			}
		}

		$status->updateJobInfo('Updating completed pages');
		$status->updateStatus();
		return $status;
	}

	private function updateCompletedPages($trackID = 0) {
		$noPages = BrokenLinkPageTrack::get()
			->filter(array(
				'TrackID' => $trackID,
				'Processed' => 1
			))
			->count();
		$track = BrokenLinkPageTrackStatus::get_latest();
		$track->CompletedPages = $noPages;
		$track->write();
		return $noPages;
	}

	private function updateJobInfo($message) {
		$track = BrokenLinkPageTrackStatus::get_latest();
		if($track) {
			$track->JobInfo = $message;
			$track->write();
		}
	}
}
