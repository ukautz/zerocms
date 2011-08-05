<?php

/**
 * This is a rewrite of the Textile parser, cause i was interested
 * in how this is done and the ul-li-issue re-emerged on my php 5.3
 * It is not 100% compatible with Textile:
 * * It does not understand .table-Tags (but the |col|col|-syntax very well)
 * * It is beta or a demo, if you wish, not nearly as well tested as Textile
 * * It is a little bit faster, depending on your Textile-syntax
 * * The ul-li-issue is not an issue
 * * Only free-standing "<", ">" and "&" will be htmlized (eg AT & T -> transformed, AT&T -> not transformed)
 *
 * @category Text-Parser
 * @copyright Copyright 2011 (c) Ulrich Kautz <uk@fortrabbit.de>
 * @license http://dev.perl.org/licenses/artistic.html Perl Artistic License OR http://www.opensource.org/licenses/gpl-license.php GPL 2 or GPL 3
 * @version 0.1.0
 *
 **/

class TextileAlike {
	private $unparse_mark = null;
	
	private $trivial_block_map = array(
		'*'  => 'strong',
		'_'  => 'em',
		'??' => 'cite',
		'-'  => 'strike',
		'+'  => 'ins',
		'^'  => 'sup',
		'~'  => 'sub',
		'%'  => 'span',
		'$'  => 'small',
		'@'  => 'code',
		'!'  => 'img',
	);
	
	private $trivial_style_modifier = array(
		'<>' => 'text-align: justify;',
		'<' => 'text-align: left;',
		'>' => 'text-align: right;',
		'=' => 'text-align: center;',
	);
	
	private $trivial_tag_map = array(
		'p' => 'p',
		'bc' => 'blockquote',
		'bq' => 'blockquote',
	);
	
	private $rx_block_modi = null;
	private $rx_block_map  = null;
	private $rx_line_break = '\r\n|\n|\r';
	private $rx_table_col  = '\\\\[0-9]+|\/[0-9]+';
	private $rx_block      = 'p|bc|h[1-9]';
	private $rx_url_chars  = '[\w"$\-_\.\+!*\'\(\),";\/?:@=&%#\{\}\|\\\\^~\[\]`]';
	
	
	
	/**
	 * Create a new instance of Textile Alike
	 * pre-generates regexes which could not be setup before
	 *
	 * @return object TextileAlike
	 * @access public
	 */
	public function __construct() {
		$this->rx_block_modi = $this->_orRegexpList( array_keys( $this->trivial_style_modifier ) ). '|\{.+?\}|\(.+?\)';
		$this->rx_block_map = $this->_orRegexpList( array_keys( $this->trivial_block_map ) );
	}
	
	
	
	/**
	 * Alias for "render"-method
	 *
	 * @param string $text textile text, unparsed
	 * @return string rendered text
	 * @access public
	 */
	public function TextileThis( $text ) {
		return $this->render( $text );
	}
	
	
	
	/**
	 * Renders input Textile language into HTML
	 *
	 * @param string $text textile text, unparsed
	 * @return string rendered text
	 * @access public
	 */
	public function render( $text ) {
		
		// safe all unparsed blocks with markers
		$this->unparse_mark = array();
		$text = preg_replace_callback(
			'#\{\{\{(.*?)\}\}\}#ms',
			array( $this, '_safeUnparsedBlocks' ),
			preg_replace_callback(
				'#<pre([^>\n\r]*)>(.*?)</pre>#ms',
				array( $this, '_safeUnparsedBlocksPre' ),
				$text
			)
		);
		
		// parse paragraphs and lines
		$text = $this->_parseLineBased( $text );
		
		// parse links
		$text = preg_replace_callback(
			'/
				(["!])(?!\1)						# link or img
				(?: (
					(?:'. $this->rx_block_modi. ')	# opt: block modi
				*) \s? )?
				([^"!]+)							# content
				\1	:								# closing
				(.+?)(\S)							# href and last char
				(?=\s|$|<|\)|\]|\})
			/xu',
			array( $this, '_parseLinks' ),
				preg_replace_callback(
				'/
					(?<!["!]:)
					(?<![@_\*])
					(
						(?: (?: https? | ftp ) :\/\/ | mailto: )
						.+?
					)
					(\S)
					(?=\s|$|<)
				/xm',
				array( $this, '_parseLinksPlain' ),
				$text
			)
		);
			
			/*preg_replace_callback(
			'/
				(?<=\s|^)(?<!>)
				(
					(?: (?: https? | ftp ) :\/\/ | mailto : )	# protocol
					'. $this->rx_url_chars. '+				# uri non encapsed
				)
				(\p{P})?
				(?=\s|$)(?!<)
			/xms',
			array( $this, '_parseLinksPlain' ),*/
		
		// parse all trivial
		$text = preg_replace_callback(
			'#(?<=>|\A)(.+?)(?=<(?!\b)|\z)#ms',
			array( $this, '_parseTrivialBlocksOuter' ),
			$text
		);
		
		
		// rewrite (pre)marks
		$text = preg_replace_callback(
			'/###((?:PRE)?MARK)#[0-9]+###/m',
			array( $this, '_insertMarks' ),
			$text
		);
		
		return $text;
	}
	
	
	
	/**
	 * Splits all inner contents of HTML tags into chunks and hands them to the
	 * actual trivial block parser.
	 * Called from preg_replace_callback in render method
	 *
	 * @param array $match the input from preg_replace_callback
	 * @return string rendered text
	 * @access private
	 */
	private function _parseTrivialBlocksOuter( $match ) {
		$text = $match[1];
		if ( preg_match( '/\A\s*\z/ms', $text ) ) return $text;
		
		// htmlize
		/*$text = preg_replace( '/(?<=\s|^)&(?=\s|$)/m', '&amp;',
			preg_replace( '/(?<=\s|^)>(?=\s|$)/m', '&gt;',
			preg_replace( '/(?<=\s|^)<(?=\s|$)/m', '&lt;', $text ) ) );*/
		
		// parse blocks
		return preg_replace_callback(
			'/
				(?<=\s|^|\p{P}|>)					# look behind: new line, begin, punktuation
				('. $this->rx_block_map. ')			# start: block symbol
				((?:'. $this->rx_block_modi. ')*)	# opt: block modi
				(?!\s)								# look ahead: non space
				(.+?)								# the block content
				(?<!\s)								# look behind: not ending with space
				\1									# end: closing block symbol
				(?=\s|$|\p{P}|<)					# look ahead: space, endline, punktuation
			/xums',
			array( $this, '_parseTrivialBlocks' ),
			$text
		);
	}
	
	
	
	/**
	 * Parses all trivial blocks, such as strong, em, strike and so on.
	 * Called from preg_replace_callback in _parseTrivialBlocksOuter method
	 *
	 * @param array $match the input from preg_replace_callback
	 * @return string rendered text
	 * @access private
	 */
	private function _parseTrivialBlocks( $match ) {
		#list( $orig, $pre, $tag, $modi, $content, $suf ) = $match;
		list( $orig, $tag, $modi, $content ) = $match;
		
		// convert tag
		$tag = $this->trivial_block_map[ $tag ];
		
		// get tag attribs
		$attribs = @empty( $modi ) ? '' : ' '. $this->_getTagAttribs( $modi );
		
		// image tag
		if ( $tag == 'img' )
			return $this->_parseImageInner( $content, $attribs );
		
		// return rendered
		#return $pre. '<'. $tag. $attribs. '>'. $content. '</'. $tag. '>'. $suf;
		return '<'. $tag. $attribs. '>'. $content. '</'. $tag. '>';
	}
	
	
	
	/**
	 * Parse plain links, directly in the text
	 *
	 * @param array $match the input from preg_replace_callback
	 * @return string rendered text
	 * @access private
	 */
	private function _parseLinksPlain( $match ) {
		list( $orig, $link, $post ) = $match;
		error_log( "YADDA ORIG '$orig', LINK '$link', POST '$post'" );
		$text = $link;
		if ( ! preg_match( '/[\.!?,;:<>\(\)\[\]\{\}"\'\+\-\$]/', $post ) ) {
			$link .= $post;
			$post = '';
		}
		return '<a href="'. $link. '">'. $link. '</a>'. $post;
	}
	
	
	
	/**
	 * Parses all Textile-link-structures into HTML.
	 * Called from preg_replace_callback in render method
	 *
	 * @param array $match the input from preg_replace_callback
	 * @return string rendered text
	 * @access private
	 */
	private function _parseLinks( $match ) {
		list( $orig, $tag, $modi, $text, $href, $post ) = $match;
		$title = '';
		
		if ( empty( $href ) ) 
			$href = array_pop( $match );
		
		// get tag attribs
		$attribs = @empty( $modi ) ? '' : ' '. $this->_getTagAttribs( $modi );
		
		// get title, if any
		if ( preg_match( '#^(.+)\((.+?)\)\s*$#', $text, $match ) ) {
			$text = $match[1];
			$title = ' title="'. addslashes( $match[2] ). '"';
		}
		
		if ( ! preg_match( '/[.!?,;:]/', $post ) ) {
			$href .= $post;
			$post = '';
		}
		
		// text == url
		if ( $text == '$' )
			$text = $href;
		
		// image
		if ( $tag == '!' )
			$text = $this->_parseImageInner( $text );
		
		// return link
		return '<a href="'. $href. '"'. $title. $attribs. '>'. $text. '</a>'. $post;
		
	}
	
	
	
	/**
	 * Parses Textitle-image-tags, or at least the inner contnet
	 * Called from preg_replace_callback in _parseLinks method
	 *
	 * @param array $match the input from preg_replace_callback
	 * @return string rendered text
	 * @access private
	 */
	private function _parseImageInner( $image, $attribs = '' ) {
		$title = '';
		if ( preg_match( '/^(.+?)\s+\((.+?)\)$/', $image, $match ) ) {
			$image = $match[1];
			$title = ' title="'. addslashes( $match[2] ). '"';
		}
		$image = trim( $image );
		return '<img src="'. $image. '"'. $title. $attribs. ' />'; 
	}
	
	
	
	/**
	 * Parses all line based Textile-tags, such as the h* or p blocks, tables,
	 * lists and so on.
	 * Called from the render method.
	 *
	 * @param string $text the whole unrendered text
	 * @return string rendered text
	 * @access private
	 */
	private function _parseLineBased( $text ) {
		$lines = preg_split( '/(?:'. $this->rx_line_break. ')/', $text );
		$level = 0;
		
		$output = array();
		$paragraphs = array();
		$lists = array(); $list = array(); $list_idx = -1;
		$tables = array(); $table = array(); $table_idx = -1; 
		
		// iterate through the lines
		foreach ( $lines as $idx => $line ) {
			
			// easiest: hr
			if ( preg_match( '/^\s*\-\-\-+\s*$/', $line ) ) {
				$output []= '<hr />';
			}
			
			// found list item
			elseif ( preg_match( '/^([\*#]+)((?:'. $this->rx_block_modi. ')*)[ \t]+(.+)$/', $line, $match ) ) {
				
				// finish paragraphs, if any
				if ( !@empty( $paragraphs ) ) {
					$output []= '<'. $paragraphs[ 'tag' ]. $paragraphs[ 'attribs' ]. '>'. join( "<br />\n", $paragraphs[ 'text' ] ). '</'. $paragraphs[ 'tag' ]. '>';
					$paragraphs = array();
				}
				
				if ( $list_idx == -1 ) {
					$marker = '###LIST#'. count( $lists ). '###';
					$output []= $marker;
				}
				
				// increment list index
				$list_idx ++;
				
				// determine lieve
				$level = strlen( $match[1] );
				
				// add to list
				$list[ $list_idx ] = array(
					'level'   => $level,
					'content' => array( $match[3] ),
					'attrib'  => @empty( $match[2] )
						? ''
						: ' '. $this->_getTagAttribs( $match[2] )
					,
					'flavor'  => substr( $match[1], -1, 1 ) == '*' ? 'ul' : 'ol'
				);
			}
			
			// add to last list item (multi line
			elseif ( $level > 0 && $list_idx > -1 && ! preg_match( '/^\s*$/', $line ) ) {
				$list[ $list_idx ][ 'content' ] []= $line;
			}
			
			// found table
			elseif ( $list_idx == -1 && preg_match( '#(?:((?:'. $this->rx_block_modi. ')+)\.)?\s*\|([^\n\r]+)\|#', $line, $match ) ) {
				list( $orig, $modi, $cols_pre ) = $match;
				
				// init table ?
				if ( @empty( $table ) ) {
					$marker = '###TABLE#'. count( $tables ). '###';
					$output []= $marker;
					$table = array(
						'rows' => array(),
					);
				}
				
				// parse cols
				$cols_pre = preg_split( '#\s*\|\s*#', $cols_pre );
				$cols = array();
				foreach ( $cols_pre as $col ) {
					$attribs = '';
					if ( preg_match( '/^((?:'. $this->rx_block_modi .'|'. $this->rx_table_col. ')+)\.\s+(.+)$/', $col, $cmatch ) ) {
						$attribs = ' '. $this->_getTagAttribs( $cmatch[1], 'table' );
						$col = $cmatch[2];
					}
					
					$cols []= array(
						'text'    => $col,
						'attribs' => $attribs
					);
				}
				
				// add row
				$table[ 'rows' ] []= array(
					'cols' => $cols,
					'attribs' => @empty( $modi ) ? '' : ' '. $this->_getTagAttribs( $modi )
				);
			}
			
			// empty line -> add all list markers or paragraphs
			elseif ( preg_match( '/^\s*$/', $line ) ) {
				
				// reset list, add marker
				if ( !@empty( $list ) ) {
					$marker = '###LIST#'. count( $lists ). '###';
					$lists[ $marker ] = $list;
					$list = array();
					$list_idx = -1;
					$level = 0;
				}
				
				// reset list, add marker
				if ( !@empty( $table ) ) {
					$marker = '###TABLE#'. count( $tables ). '###';
					$tables[ $marker ] = $table;
					$table = array();
				}
				
				// finish paragraphs
				if ( !@empty( $paragraphs ) ) {
					$output []= '<'. $paragraphs[ 'tag' ]. $paragraphs[ 'attribs' ]. '>'. join( "<br />\n", $paragraphs[ 'text' ] ). '</'. $paragraphs[ 'tag' ]. '>';
					$paragraphs = array();
				}
				
				// just add an empty thingy
				else
					$output []= '';
			}
			
			// regular line, add to paragraphs stack
			elseif ( ! preg_match( '/^\s*###((PRE)?MARK|LIST)#/', $line ) ) {
				$attribs = '';
				$tag = 'p';
				if ( preg_match( '/^('. $this->rx_block. ')((?:'. $this->rx_block_modi. ')*)\.\s+(.+)$/', $line, $lmatch ) ) {
					list( $orig, $tag, $attribs, $line ) = $lmatch;
					if ( isset( $this->trivial_tag_map[ $tag ] ) )
						$tag = $this->trivial_tag_map[ $tag ];
					if ( !@empty( $attribs ) )
						$attribs = ' '. $this->_getTagAttribs( $attribs );
				}
				
				if ( @empty( $paragraphs ) )
					$paragraphs = array(
						'tag'  => $tag,
						'text' => array( $line ),
						'attribs' => $attribs
					);
				else
					$paragraphs[ 'text' ] []= $line;
			}
			else {
				$output []= $line;
			}
		}
		
		// got lists left over -> add marker now
		if ( !@empty( $list ) ) {
			$marker = '###LIST#'. count( $lists ). '###';
			$output []= $marker;
			$lists[ $marker ] = $list;
			$output []= '';
		}
		
		// finish tables
		if ( !@empty( $table ) ) {
			$marker = '###TABLE#'. count( $tables ). '###';
			$output []= $marker;
			$tables[ $marker ] = $table;
		}
		
		// got paragraphs -> render now
		if ( !@empty( $paragraphs ) ) {
			$output []= '<'. $paragraphs[ 'tag' ]. $paragraphs[ 'attribs' ]. '>'. join( "<br />\n", $paragraphs[ 'text' ] ). '</'. $paragraphs[ 'tag' ]. '>';
		}
		
		// rebuild text
		$text = join( "\n", $output );
		
		// iterate through markers
		foreach ( $lists as $marker => $list ) {
			$html = array();
			$level = 0;
			$flavor = array();
			
			// render list to html
			foreach ( $list as $idx => $item ) {
				
				// increase
				if ( $item[ 'level' ] > $level ) {
					$flavor[ $item[ 'level' ] ] = $item[ 'flavor' ];
					while( $level ++ < $item[ 'level' ] - 1 ) {
						$flavor[ $level ] = $item[ 'flavor' ];
						$html []= str_repeat( "\t", $level ). '<'. $item[ 'flavor' ]. '>';
						$html []= str_repeat( "\t", $level ). '<li>';
					}
					$html []= str_repeat( "\t", $level+ ( $idx ? 0 : -1 ) ). '<'. $item[ 'flavor' ]. '>';
				}
				
				// decrease
				elseif ( $item[ 'level' ] < $level ) {
					$html []= str_repeat( "\t", $level ). '</li>';
					while( $level -- > $item[ 'level' ] + 1 ) {
						$down_flavor = isset( $flavor[ $level + 1 ] )
							? $flavor[ $level ]
							: $item[ 'flavor' ]
						;
						$html []= str_repeat( "\t", $level+1 ). '</'. $down_flavor. '>';
						$html []= str_repeat( "\t", $level ). '</li>';
					}
					$down_flavor = isset( $flavor[ $level+1 ] )
						? $flavor[ $level+1 ]
						: $item[ 'flavor' ]
					;
					$html []= str_repeat( "\t", $level+1 ). '</'. $down_flavor. '>';
					$html []= str_repeat( "\t", $level ). '</li>';
				}
				elseif ( $idx > 0 )
					$html []= str_repeat( "\t", $level ). '</li>';
				
				$html []= str_repeat( "\t", $level ). '<li'. $item[ 'attrib' ]. '>'. join( "<br />\n", $item[ 'content' ] );
			}
			
			// decrease back to level 1
			$html []= str_repeat( "\t", $level ). '</li>';
			while( $level -- > 1 ) {
				$down_flavor = isset( $flavor[ $level + 1 ] )
					? $flavor[ $level + 1 ]
					: $item[ 'flavor' ]
				;
				$html []= str_repeat( "\t", $level+1 ). '</'. $down_flavor. '>';
				$html []= str_repeat( "\t", $level ). '</li>';
			}
			$html []= '</'. $list[0][ 'flavor' ]. '>';
			
			// replace now marker with html
			$text = preg_replace( '/'. $marker. '/ms', join( "\n", $html ), $text );
		}
		
		// render tables
		foreach ( $tables as $marker => $table ) {
			$html = array( '<table>' );
			foreach ( $table[ 'rows' ] as $row ) {
				$count_cols = count( $row );
				$html []= "\t<tr". $row[ 'attribs' ]. ">";
				foreach ( $row[ 'cols' ] as $col ) {
					$html []= "\t\t<td". $col[ 'attribs' ]. ">";
					$html []= "\t\t\t". $col[ 'text' ];
					$html []= "\t\t</td>";
				}
				$html []= "\t</tr>";
			}
			$html []= '</table>';
			
			// replace now marker with thml
			$text = preg_replace( '/'. $marker. '/ms', join( "\n", $html ), $text );
		}
		
		return $text;
	}
	
	
	
	/**
	 * Re-inserts the content of the beforehand set unparsed-markers in the
	 * _safeUnparsedBlocks* methods.
	 * Called from preg_replace_callback in render method after all rendering.
	 *
	 * @param string $match the whole unrendered text
	 * @return string rendered text
	 * @access private
	 */
	private function _insertMarks( $match ) {
		list( $marker, $name ) = $match;
		$data = $this->unparse_mark[ $marker ];
		if ( strpos( $name, 'PRE' ) === 0 )
			$data[ 'content' ] = '<pre'. $data[ 'attribs' ]. '>'. htmlentities( $data[ 'content' ] ). '</pre>';
		return $data[ 'content' ];
	}
	
	
	
	/**
	 * Replaces all textile exclusion marks ({{{ .. }}}) with marks, so they
	 * can be replaced after the rendering of the syntax and stay untouched.
	 * Called from preg_replace_callback in render method
	 *
	 * @param string $match the whole unrendered text
	 * @return string marker
	 * @access private
	 */
	private function _safeUnparsedBlocks( $match, $markname = 'MARK' ) {
		$marker = '###'. $markname. '#'. count( $this->unparse_mark ). '###';
		$this->unparse_mark[ $marker ] = array(
			'content' => $match[1],
			'attribs' => !empty( $match[2] ) ? $match[2] : ''
		);
		return $marker;
	}
	
	
	
	/**
	 * Same as _safeUnparsedBlocks method, but for pre-blocks.
	 * Called from preg_replace_callback in render method
	 *
	 * @param string $match the whole unrendered text
	 * @return string marker
	 * @access private
	 */
	private function _safeUnparsedBlocksPre( $match ) {
		return $this->_safeUnparsedBlocks( array( null, $match[2], $match[1] ), 'PREMARK' );
	}
	
	
	
	/**
	 * Creates a or'ed regex list and quotes all disjunct members
	 *
	 * @param array $list of chars/words
	 * @return string or'ed list
	 * @access private
	 */
	private function _orRegexpList( $list ) {
		return join( '|', array_map( 'quotemeta', $list ) );
	}
	
	
	
	/**
	 * Extracts tag attributes, such as style, class, id and so on
	 * from Textitle-attribute-syntax and returns it.
	 * Called from multiple parsing methods.
	 *
	 * @param string $modi Textile-attributes
	 * @param string $attrib_mode Either "default" or "table", which can also rowspan and colspan
	 * @return string attributes to be used
	 * @access private
	 */
	private function _getTagAttribs( $modi, $attrib_mode = 'default' ) {
		
		// determine regex
		$rx = $this->rx_block_modi;
		if ( $attrib_mode == 'table' )
			$rx .= '|'. $this->rx_table_col;
		
		// get all modifiers
		preg_match_all( '/('. $rx. ')/', $modi, $matches );
		
		// build list of modifications
		$amodi = array( 'style' => array(), 'id' => '', 'class' => array(), 'other' => array() );
		foreach ( $matches[0] as $cmodi ) {
			
			// alignment
			if ( preg_match( '/^(<>|<|>|=)$/', $cmodi ) ) {
				$amodi[ 'style' ] []= $this->trivial_style_modifier[ $cmodi ];
			}
			
			// inline style
			elseif ( preg_match( '/^\{(.+?)\}$/', $cmodi, $m ) ) {
				$amodi[ 'style' ] []= $m[1];
			}
			
			// class or id
			elseif ( preg_match( '/^\((#)?(.+?)\)$/', $cmodi, $m ) ) {
				if ( !@empty( $m[1] ) )
					$amodi[ 'id' ] = $m[2];
				else
					$amodi[ 'class' ] []= $m[2];
			}
			else {
				
				// table mode
				if ( $attrib_mode == 'table' ) {
					if ( preg_match( '/^\/([0-9]+)$/', $cmodi, $m ) )
						$amodi[ 'other' ] []= 'colspan="'. $m[1]. '"';
					elseif ( preg_match( '/^\\\\([0-9]+)$/', $cmodi, $m ) )
						$amodi[ 'other' ] []= 'rowspan="'. $m[1]. '"';
				}
			}
			
			
		}
		$attrib = array();
		if ( !@empty( $amodi[ 'style' ] ) )
			$attrib []= 'style="'. join( '; ', $amodi[ 'style' ] ). '"';
		if ( !@empty( $amodi[ 'class' ] ) )
			$attrib []= 'class="'. join( ' ', $amodi[ 'class' ] ). '"';
		if ( !@empty( $amodi[ 'id' ] ) )
			$attrib []= 'id="'. $amodi[ 'id' ]. '"';
		if ( !@empty( $amodi[ 'other' ] ) )
			$attrib []= join( ' ', $amodi[ 'other' ] );
		return join( ' ', $attrib );
	}
	
}

?>
