<?php


class ZeroCmsPluginSearchEngine extends ZeroCmsPlugin {
	
	private $search_form_template = null;
	private $search_result_template = null;
	private $search_result = null;
	private $search_string = null;
	
	public function __construct( &$zcms ) {
		parent::__construct( &$zcms );
		$search_form_template = ZC_DIR. DS. $zcms->getThemeDir(). DS. 'search-form.php';
		if ( file_exists( $search_form_template ) )
			$this->search_form_template = $search_form_template;
		$search_result_template = ZC_DIR. DS. $zcms->getThemeDir(). DS. 'search-result.php';
		if ( file_exists( $search_result_template ) )
			$this->search_result_template = $search_result_template;
	}
	
	public function hookPreContentRender( $path ) {
		
		// print search form
		if ( $path == 'search' ) {
			
			$content = '';
			if ( @empty( $_REQUEST[ 's' ] ) ) {
				if ( is_null( $this->search_form_template ) )
					throw new ZeroCmsHookException( array(
						'error' => 'ZeroCmsPluginSearchEngine not correctly configured. Missing search-form.php..'
					) );
				ob_start();
				$zcms = $this->getZcms();
				include( $this->search_form_template );
				$content = ob_get_contents();
				ob_end_clean();
			}
			else {
				
				$this->search_string = $_REQUEST[ 's' ];
				
				// oops, missing template
				if ( is_null( $this->search_result_template ) )
					throw new ZeroCmsHookException( array(
						'error' => 'ZeroCmsPluginSearchEngine not correctly configured. Missing search-result.php..'
					) );
				
				// try read search result from cache
				$search_index = $this->getZcms()->cacheRead( 'search-index' );
				
				// not in cache -> try file
				if ( @empty( $search_index ) && is_file( $this->_searchIndexFile() ) ) {
					$search_index = unserialize( file_get_contents( $this->_searchIndexFile() ) );
					$this->getZcms()->cacheWrite( 'search-index', $search_index );
				}
				
				// got any
				if ( !@empty( $search_index ) ) {
					
					// parse user search
					$search_words = $this->_extractWords( $this->search_string );
					$search_result = array();
					
					// determine result
					foreach ( $search_words as $word => $weight ) {
						if ( isset( $search_index[ $word ] ) ) {
							foreach ( $search_index[ $word ] as $abs_link => $amount ) {
								if ( ! isset( $search_result[ $abs_link ] ) )
									$search_result[ $abs_link ] = 0;
								$search_result[ $abs_link ] += $amount;
							}
						}
					}
					
					//
					
					$search_result_links = array_keys( $search_result );
					function _search_result_links( $a, $b ) {
						global $search_result;
						return $search_result[ $a ] == $search_result[ $b ]
							? 0
							: ( $search_result[ $a ] < $search_result[ $b ]
								? 1
								: -1
							);
					}
					usort( $search_result_links, '_search_result_links' );
					$this->search_result = $search_result_links;
				}
				
				ob_start();
				$zcms = $this->getZcms();
				include( $this->search_result_template );
				$content = ob_get_contents();
				ob_end_clean();
			}
			
			throw new ZeroCmsHookException( array(
					'content' => $content
				) );
			
			
		}
		
		// adminstrative: rebuild
		elseif ( $path == 'rebuild-search' && $this->getZcms()->isAdmin() ) {
			
			// generate search database
			$search_index = $this->_buildSearchIndex( ZC_CONTENTS );
			
			// serialize search cache
			file_put_contents( $this->_searchIndexFile(), serialize( $search_index ) );
			
			// output
			throw new ZeroCmsHookException( array(
				'content' => '<p>Search cache generated. You should now flush/clear cache</p>'
					. '<p>Do <strong>NOT</strong> delete the file "'. ZC_CACHE_DIR. DS. '.search-index-cache"</p>'
			) );
		}
	}
	
	public function hookPostClearCache( $path ) {
		
		// generate search database
		$search_index = $this->_buildSearchIndex( ZC_CONTENTS );
		
		// serialize search cache
		file_put_contents( $this->_searchIndexFile(), serialize( $search_index ) );
	}
	
	public function hasSearchString() {
		return ! @empty( $this->search_string );
	}
	
	public function getSearchStringHtml() {
		return @empty( $this->search_string )
			? ''
			: htmlentities( $this->search_string );
	}
	
	public function hasSearchResult() {
		return ! @empty( $this->search_result );
	}
	
	public function getResultCount() {
		return @empty( $this->search_result )
			? 0
			: count( $this->search_result );
	}
	
	public function getSearchResults() {
		return @empty( $this->search_result )
			? array()
			: $this->search_result;
	}
	
	public function getContentSample( $abs_link, $search_string = null, $args = array() ) {
		$abs_path = ZC_CONTENTS. DS. $abs_link. '.tx';
		if ( !file_exists( $abs_path ) )
			return '';
		if ( is_null( $search_string ) && $this->hasSearchString() )
			$search_string = $this->search_string;
		if ( @empty( $search_string ) )
			return '';
		
		# get search result content sample from cache
		$cache_key = 'search-rcs-'. md5( $abs_link, $search_string );
		$cached = $this->getZcms()->cacheRead( $cache_key );
		if ( ! @empty( $cached ) )
			return $cached;
		
		$args = array_merge( array(
			'sample_sep' => '[..]',
			'prefix_string' => '..',
			'prefix_length' => 20,
			'suffix_string' => '..',
			'suffix_length' => 20,
			'max_samples' => 3
		), $args );
		
		// read content and split into single words
		$content = file_get_contents( $abs_path );
		$title = $abs_link;
		if ( preg_match( '/^###title\s+(?:[0-9]+\s*:\s*)?([^\n\r]+)$/ms', $content, $match ) )
			$title = $match[1];
		$content = preg_replace( '/^###[^\n\r]+$/ms', '', $content );
		$content = $this->getZcms()->render( $content );
		$content = strip_tags( $content );
		$content_words = preg_split( '/\s+/ms', $content );
		$content_length = strlen( $content );
		
		// get search words from search string
		$search_words = $this->_extractWords( $search_string, true );
		
		// ..
		$missing = $args[ 'max_samples' ];
		$seen = array();
		$sample = array();
		$hits = array();
		
		// begin from start and go through all words:
		//	transform word into letter-only word
		//	if word is searchword
		//		add sample with prefix and suffix
		foreach ( $content_words as $cword ) {
			$xword = preg_replace( '/[^\p{L}]/', '', strtolower( $cword ) );
			if ( isset( $search_words[ $xword ] ) && ! isset( $seen[ $xword ] ) ) {
				$missing --;
				$seen[ $xword ] = true;
				$idx = strpos( $content, $cword );
				
				$hit = array();
				$hit[ 'prefix_length' ] = $idx > $args[ 'prefix_length' ]
					? $args[ 'prefix_length' ]
					: $idx;
				$hit[ 'prefix_idx' ] = $idx - $hit[ 'prefix_length' ];
				
				$count_hits = count( $hits );
				if ( $count_hits > 0 && $hits[ $count_hits - 1 ][ 'suffix_end' ] >= $hit[ 'prefix_idx' ] ) {
					$hits[ $count_hits -1 ][ 'words' ][ $cword ] = $idx;
					$hit[ 'word_length' ] += strlen( $cword );
				}
				else {
					$hit[ 'words' ] = array( $cword => $idx );
					$hit[ 'word_length' ] = strlen( $cword );
					$hit[ 'suffix_idx' ] = $hit[ 'prefix_idx' ]+ strlen( $cword );
					$hit[ 'suffix_length' ] = $hit[ 'suffix_idx' ] + $args[ 'suffix_length' ] > $content_length
						? $content_length - $hit[ 'suffix_idx' ]
						: $args[ 'suffix_length' ];
					$hit[ 'suffix_end' ] = $hit[ 'suffix_idx' ] + $hit[ 'suffix_length' ];
					$hits []= $hit;
				}
				if ( $missing <= 0 ) break;
			}
		}
		
		foreach ( $hits as $hit ) {
			$text = substr( $content, $hit[ 'prefix_idx' ],
				$hit[ 'prefix_length' ]+  $hit[ 'word_length' ]+  $hit[ 'suffix_length' ] );
			$words = array_keys( $hit[ 'words' ] );
			$words = array_reverse( $words );
			foreach ( $words as $word ) {
				$idx = strpos( $text, $word );
				$text = 
					substr( $text, 0, $idx )
					. '<strong>'. $word. '</strong>'
					. substr( $text, $idx + strlen( $word ), -1 );
				error_log( "'$word': $idx -> '$text'" );
			}
			$sample []= strip_tags( $text, '<strong>' );
		}
		#return '<pre>'. json_encode( $hits, true ). '</pre>';
		$sample_text = join( $args[ 'sample_sep' ], $sample );
		$this->getZcms()->cacheWrite( $cache_key, array( $title, $sample_text ) );
		return array( $title, $sample_text );
	}
	
	private function _buildSearchIndex( $dir, $search_index = array() ) {
		$dh = opendir( $dir );
		$cnt = 0;
		while( ( $path = readdir( $dh ) ) !== false ) {
			$cnt ++;
			if ( preg_match( '/^\.+$/', $path ) ) continue;
			$abs_path = $dir. DS. $path;
			if ( is_dir( $abs_path ) ) {
				$this->_buildSearchIndex( $abs_path, &$search_index );
			}
			elseif ( preg_match( '/^(.+)\.tx$/', $path, $match ) ) {
				$abs_link = $dir. '/'. $match[1];
				$abs_link = substr( $abs_link, strlen( ZC_CONTENTS ) );
				$words = $this->_extractWords( file_get_contents( $abs_path ) );
				foreach ( $words as $word => $amount ) {
					if ( ! isset( $search_index[ $word ] ) )
						$search_index[ $word ] = array();
					$search_index[ $word ][ $abs_link ] = $amount;
				}
			}
		}
		closedir( $dh );
		return $search_index;
	}
	
	private function _extractWords( $text, $sorted = false ) {
		$str = strtolower( $text );
		$candidates = preg_split( '/\s+/ms', $str );
		$unique = array();
		foreach ( $candidates as $word ) {
			$word = preg_replace( '/[^\p{L}]/', '', $word );
			if ( strlen( $word ) <= 3 ) continue;
			if ( ! isset( $unique[ $word ] ) ) $unique[ $word ] = 0;
			$unique[ $word ] ++;
		}
		if ( $sorted ) {
			$sorted_keys = array_keys( $unique );
			function _sort_extract_words( $a, $b ) {
				global $unique;
				return $unique[ $a ] == $unique[ $b ]
					? 0
					: ( $unique[ $a ] < $unique[ $b ]
						? 1
						: 0
					);
			}
			usort( $sorted_keys, '_sort_extract_words' );
			$unique_sorted = array();
			foreach ( $sorted_keys as $word ) {
				$unique_sorted[ $word ] = $unique[ $word ];
			}
			return $unique_sorted;
		}
		return $unique;
	}
	
	private function _searchIndexFile() {
		return ZC_CACHE_DIR. DS. '.search-index-cache';
	}
}