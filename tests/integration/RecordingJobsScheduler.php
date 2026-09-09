<?php
/**
 * Scheduler test double that reports healthy and records worker wakes.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Integration;

use AIMultilingual\Jobs\BackgroundTranslationScheduler;

/**
 * Lets AIT1 autostart tests assert that a job/batch was enqueued without a
 * live Action Scheduler.
 */
final class RecordingJobsScheduler extends BackgroundTranslationScheduler {

	/**
	 * Job ids passed to enqueue_job().
	 *
	 * @var list<int>
	 */
	public array $enqueued = array();

	public function is_available(): bool {
		return true;
	}

	/**
	 * @return array{available: bool, message: string}
	 */
	public function health(): array {
		return array(
			'available' => true,
			'message'   => 'Action Scheduler is available.',
		);
	}

	/**
	 * @param int $job_id Job id.
	 * @return true
	 */
	public function enqueue_job( int $job_id ) {
		$this->enqueued[] = $job_id;

		return true;
	}

	/**
	 * @param int $job_id        Job id.
	 * @param int $delay_seconds Delay.
	 * @return true
	 */
	public function enqueue_job_delayed( int $job_id, int $delay_seconds ) {
		unset( $delay_seconds );
		$this->enqueued[] = $job_id;

		return true;
	}
}
