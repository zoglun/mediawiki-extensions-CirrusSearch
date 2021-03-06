<?php

namespace CirrusSearch\Maintenance\Validators;

use CirrusSearch\Maintenance\Maintenance;
use Elastica\Index;
use Status;

class ReplicaRangeValidator extends Validator {
	/**
	 * @var Index
	 */
	private $index;

	/**
	 * @var int
	 */
	protected $replicaCount;

	/**
	 * @param Index $index
	 * @param int $replicaCount
	 * @param Maintenance $out
	 */
	public function __construct( Index $index, $replicaCount, Maintenance $out = null ) {
		parent::__construct( $out );

		$this->index = $index;
		$this->replicaCount = $replicaCount;
	}

	/**
	 * @return Status
	 */
	public function validate() {
		$this->outputIndented( "\tValidating replica range..." );
		$settings = $this->index->getSettings()->get();
		$actualReplicaCount = isset( $settings['auto_expand_replicas'] ) ? $settings['auto_expand_replicas'] : 'false';
		if ( $actualReplicaCount == $this->replicaCount ) {
			$this->output( "ok\n" );
		} else {
			$this->output( "is $actualReplicaCount but should be " . $this->replicaCount . '...' );
			$this->index->getSettings()->set( array( 'auto_expand_replicas' => $this->replicaCount ) );
			$this->output( "corrected\n" );
		}

		return Status::newGood();
	}
}
