<?php

if(!class_exists('AbstractQueuedJob')) return;

/**
 * A Job for running a external link check for published pages
 *
 */
class CheckLinksJob extends AbstractQueuedJob implements QueuedJob {

	public function getTitle() {
		return _t('CheckLinksJob.TITLE', 'Checking for  broken links');
	}

	public function getJobType() {
		return QueuedJob::QUEUED;
	}

	public function getSignature() {
		return md5(get_class($this));
	}

	/**
	 * Check an individual page
	 */
	public function process() {
		$task = CheckLinksTask::create();
		$track = $task->runLinksCheck(1);
		$this->currentStep = $track->CompletedPages;
		$this->totalSteps = $track->TotalPages;
		$this->isComplete = $track->Status === 'Completed';
	}

}
