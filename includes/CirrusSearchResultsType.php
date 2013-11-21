<?php
/**
 * Lightweight classes to describe specific result types we can return
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
interface CirrusSearchResultsType {
	function getFields();
	function getHighlightingConfiguration();
	function transformElasticsearchResult( $result );
}

class CirrusSearchTitleResultsType implements CirrusSearchResultsType {
	public function getFields() {
		return array( 'namespace', 'title' );
	}
	public function getHighlightingConfiguration() {
		return null;
	}
	public function transformElasticsearchResult( $result ) {
		$results = array();
		foreach( $result->getResults() as $r ) {
			$results[] = Title::makeTitle( $r->namespace, $r->title )->getPrefixedText();
		}
		return $results;
	}
}

class CirrusSearchFullTextResultsType implements CirrusSearchResultsType {
	public function getFields() {
		return array( 'id', 'title', 'namespace', 'redirect', 'text_bytes', 'text_words' );
	}

	/**
	 * Setup highlighting.
	 * Don't fragment title because it is small.
	 * Get just one fragment from the text because that is all we will display.
	 * Get one fragment from redirect title and heading each or else they
	 * won't be sorted by score.
	 * @return array of highlighting configuration
	 */
	public function getHighlightingConfiguration() {
		$entireValue = array(
			'number_of_fragments' => 0,
		);
		$entireValueInListField = array(
			'number_of_fragments' => 1, // Just of the values in the list
			'fragment_size' => 10000,   // We want the whole value but more than this is crazy
			'type' => 'plain',          // The fvh doesn't sort list fields by score correctly
		);
		$text = array(
			'number_of_fragments' => 1, // Just one fragment
			'fragment_size' => 100,
		);

		return array(
			'order' => 'score',
			'pre_tags' => array( CirrusSearchSearcher::HIGHLIGHT_PRE ),
			'post_tags' => array( CirrusSearchSearcher::HIGHLIGHT_POST ),
			'fields' => array(
				'title' => $entireValue,
				'text' => $text,
				'redirect.title' => $entireValueInListField,
				'heading' => $entireValueInListField,
				'title.plain' => $entireValue,
				'text.plain' => $text,
				'redirect.title.plain' => $entireValueInListField,
				'heading.plain' => $entireValueInListField,
			),
		);
	}

	public function transformElasticsearchResult( $result ) {
		return new CirrusSearchResultSet( $result );
	}
}