<?php

/**
 * Represents a track for a single page
 */
class BrokenLinkPageTrack extends DataObject {

	private static $db = array(
		'Processed' => 'Boolean'
	);

	private static $has_one = array(
		'Page' => 'SiteTree',
		'Status' => 'BrokenLinkPageTrackStatus'
	);

	private static $has_many = array(
		'BrokenLinks' => 'BrokenLink'
	);

	/**
	 * @return SiteTree
	 */
	public function Page() {
		return Versioned::get_by_stage('SiteTree', 'Stage')
			->byID($this->PageID);
	}
}
