<?php
/**
 * Aggregate over every eligible target language for one content object
 * (MLW1a, ADR-0034 D3). Rolls up N {@see ObjectLanguageStatus} into the
 * "N / M languages ready" model and the forgotten-language signal.
 *
 * `target_count` (M) is ALWAYS the object's eligible configured target
 * languages — never the operator-selected subset — so a language the operator
 * never selected still counts against M and cannot be forgotten.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Workspace;

/**
 * Object-level multi-language completeness summary.
 */
final class ObjectLanguagesSummary {

	/**
	 * Holds the rolled-up "N / M languages ready" model for one object.
	 *
	 * @param int                $target_count         M — eligible configured target languages.
	 * @param int                $ready_count          N — languages that are complete and clean.
	 * @param int                $not_translated_count Languages with state == not_translated.
	 * @param int                $incomplete_count     Languages with state in {missing_fields, needs_attention}.
	 * @param int                $translating_count    Languages with an active translation job.
	 * @param int                $approvable_count     Languages with approvable pending review rows.
	 * @param bool               $forgotten            Any target language is entirely not translated.
	 * @param array<int, string> $forgotten_languages  Language codes that are not translated.
	 */
	public function __construct(
		public readonly int $target_count,
		public readonly int $ready_count,
		public readonly int $not_translated_count,
		public readonly int $incomplete_count,
		public readonly int $translating_count,
		public readonly int $approvable_count,
		public readonly bool $forgotten,
		public readonly array $forgotten_languages
	) {
	}

	/**
	 * Rolls up one {@see ObjectLanguageStatus} per eligible target language.
	 *
	 * @param array<int, ObjectLanguageStatus> $statuses One status per eligible target language (M entries).
	 */
	public static function from_statuses( array $statuses ): self {
		$target          = count( $statuses );
		$ready           = 0;
		$not_translated  = 0;
		$incomplete      = 0;
		$translating     = 0;
		$approvable      = 0;
		$forgotten_langs = array();

		foreach ( $statuses as $status ) {
			$state = $status->state();

			if ( $status->is_ready() ) {
				++$ready;
			}

			if ( ObjectLanguageStatus::STATE_NOT_TRANSLATED === $state ) {
				++$not_translated;
				$forgotten_langs[] = $status->language_code;
			}

			if ( in_array(
				$state,
				array( ObjectLanguageStatus::STATE_MISSING_FIELDS, ObjectLanguageStatus::STATE_NEEDS_ATTENTION ),
				true
			) ) {
				++$incomplete;
			}

			if ( ObjectLanguageStatus::STATE_TRANSLATING === $state ) {
				++$translating;
			}

			if ( $status->is_approvable() ) {
				++$approvable;
			}
		}

		return new self(
			$target,
			$ready,
			$not_translated,
			$incomplete,
			$translating,
			$approvable,
			$not_translated > 0,
			$forgotten_langs
		);
	}

	/**
	 * REST / serialization shape.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'target_count'         => $this->target_count,
			'ready_count'          => $this->ready_count,
			'not_translated_count' => $this->not_translated_count,
			'incomplete_count'     => $this->incomplete_count,
			'translating_count'    => $this->translating_count,
			'approvable_count'     => $this->approvable_count,
			'forgotten'            => $this->forgotten,
			'forgotten_languages'  => $this->forgotten_languages,
		);
	}
}
