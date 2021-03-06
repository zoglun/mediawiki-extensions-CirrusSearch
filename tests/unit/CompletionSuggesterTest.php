<?php

namespace CirrusSearch;

use \CirrusSearch\Test\HashSearchConfig;
use \CirrusSearch\Test\DummyConnection;
use \CirrusSearch\BuildDocument\SuggestBuilder;

/**
 * Completion Suggester Tests
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 */
class CompletionSuggesterTest extends \PHPUnit_Framework_TestCase {

	/**
	 * @dataProvider provideQueries
	 */
	public function testQueries( $config, $limit, $search, $variants, $expectedProfiles, $expectedQueries ) {
		$completion = new MyCompletionSuggester( new HashSearchConfig( $config ), $limit );
		list( $profiles, $suggest ) = $completion->testBuildQuery( $search, $variants );
		$this->assertEquals( $expectedProfiles, $profiles );
		$this->assertEquals( $expectedQueries, $suggest );
	}


	public function provideQueries() {
		$simpleProfile = array(
			'plain' => array(
				'field' => 'suggest',
				'min_query_len' => 0,
				'discount' => 1.0,
				'fetch_limit_factor' => 2,
			),
		);

		$simpleFuzzy = $simpleProfile + array(
			'plain-fuzzy' => array(
				'field' => 'suggest',
				'min_query_len' => 0,
				'fuzzy' => array(
					'fuzzyness' => 'AUTO',
					'prefix_length' => 1,
					'unicode_aware' => true,
				),
				'discount' => 0.5,
				'fetch_limit_factor' => 1.5
			)
		);

		return array(
			"simple" => array(
				array( 'CirrusSearchCompletionSettings' => $simpleProfile ),
				10,
				' complete me ',
				null,
				$simpleProfile, // The profile remains unmodified here
				array(
					'plain' => array(
						'text' => 'complete me ', // keep trailing white spaces
						'completion' => array(
							'field' => 'suggest',
							'size' => 20, // effect of fetch_limit_factor
						),
					),
				),
			),
			"simple with fuzzy" => array(
				array( 'CirrusSearchCompletionSettings' => $simpleFuzzy ),
				10,
				' complete me ',
				null,
				$simpleFuzzy, // The profiles remains unmodified here
				array(
					'plain' => array(
						'text' => 'complete me ', // keep trailing white spaces
						'completion' => array(
							'field' => 'suggest',
							'size' => 20, // effect of fetch_limit_factor
						),
					),
					'plain-fuzzy' => array(
						'text' => 'complete me ', // keep trailing white spaces
						'completion' => array(
							'field' => 'suggest',
							'size' => 15.0, // effect of fetch_limit_factor
							// fuzzy config is simply copied from the profile
							'fuzzy' => array(
								'fuzzyness' => 'AUTO',
								'prefix_length' => 1,
								'unicode_aware' => true,
							),
						),
					),
				),
			),
			"simple with variants" => array(
				array( 'CirrusSearchCompletionSettings' => $simpleProfile ),
				10,
				' complete me ',
				array( ' variant1 ', ' complete me ', ' variant2 ' ),
				// Profile is updated with extra variant setup
				// to include an extra discount
				// ' complete me ' variant duplicate will be ignored
				$simpleProfile + array(
					'plain-variant-1' => array(
						'field' => 'suggest',
						'min_query_len' => 0,
						'discount' => 1.0 * CompletionSuggester::VARIANT_EXTRA_DISCOUNT,
						'fetch_limit_factor' => 2,
						'fallback' => true, // extra key added, not used for now
					),
					'plain-variant-2' => array(
						'field' => 'suggest',
						'min_query_len' => 0,
						'discount' => 1.0 * (CompletionSuggester::VARIANT_EXTRA_DISCOUNT/2),
						'fetch_limit_factor' => 2,
						'fallback' => true, // extra key added, not used for now
					)
				),
				array(
					'plain' => array(
						'text' => 'complete me ', // keep trailing white spaces
						'completion' => array(
							'field' => 'suggest',
							'size' => 20, // effect of fetch_limit_factor
						),
					),
					'plain-variant-1' => array(
						'text' => 'variant1 ',
						'completion' => array(
							'field' => 'suggest',
							'size' => 20, // effect of fetch_limit_factor
						),
					),
					'plain-variant-2' => array(
						'text' => 'variant2 ',
						'completion' => array(
							'field' => 'suggest',
							'size' => 20, // effect of fetch_limit_factor
						),
					),
				),
			),
		);
	}

	/**
	 * @dataProvider provideMinMaxQueries
	 */
	public function testMinMaxDefaultProfile( $len, $query ) {
		global $wgCirrusSearchCompletionProfiles;
		$config = array( 'CirrusSearchCompletionSettings' => $wgCirrusSearchCompletionProfiles['default'] );
		// Test that we generate at most 4 profiles
		$completion = new MyCompletionSuggester( new HashSearchConfig( $config ), 1 );
		list( $profiles, $suggest ) = $completion->testBuildQuery( $query, array() );
		// Unused profiles are kept
		$this->assertEquals( count( $wgCirrusSearchCompletionProfiles['default'] ), count( $profiles ) );
		// Never run more than 4 suggest query (without variants)
		$this->assertTrue( count( $suggest ) <= 4 );
		// small queries
		$this->assertTrue( count( $suggest ) >= 2 );

		if ( $len < 3 ) {
			// We do not run fuzzy for small queries
			$this->assertEquals( 2, count( $suggest ) );
			foreach( $suggest as $key => $value ) {
				$this->assertArrayNotHasKey( 'fuzzy', $value );
			}
		}
		foreach( $suggest as $key => $value ) {
			// Make sure the query is truncated otherwise elastic won't send results
			$this->assertTrue( mb_strlen( $value['text'] ) < SuggestBuilder::MAX_INPUT_LENGTH );
		}
		foreach( array_keys( $suggest ) as $sug ) {
			// Makes sure we have the corresponding profile
			$this->assertArrayHasKey( $sug, $profiles );
		}
	}

	public function provideMinMaxQueries() {
		$queries = array();
		// The completion should not count extra spaces
		// This is to avoid enbling costly fuzzy profiles
		// by cheating with spaces
		$query = '  ';
		for( $i = 0; $i < 100; $i++ ) {
			$test = "Query length {$i}";
			$queries[$test] = array( $i, $query . '   ' );
			$query .= '';
		}
		return $queries;
	}
}

/**
 * No package visibility in with PHP so we have to subclass...
 */
class MyCompletionSuggester extends CompletionSuggester {
	public function __construct( SearchConfig $config, $limit ) {
		parent::__construct( new DummyConnection(), $limit, $config, array( NS_MAIN ), null, "dummy" );
	}

	public function testBuildQuery( $search, $variants ) {
		$this->setTermAndVariants( $search, $variants );
		return $this->buildQuery();
	}
}
