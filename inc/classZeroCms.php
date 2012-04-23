<?php
/**
 * ZeroCms
 *
 * A minimal CMS/Wiki using Textile for easy publushing and APC or file cache for performance
 *
 * @category CMS or Wiki
 * @copyright Copyright (c) 2011 Ulrich Kautz <uk@fortrabbit.de>
 * @license http://dev.perl.org/licenses/artistic.html Perl Artistic License OR http://www.opensource.org/licenses/gpl-license.php GPL 2 or GPL 3
 * @version 0.1.0
 *
 **/


define( 'DS', DIRECTORY_SEPARATOR );

function zc_log( $msg ) {
	if ( ZC_DEBUG ) error_log( $msg );
}

class ZeroCms {
	
	private $is_admin = null;
	private $textile = null;
	private $current_path = null;
	private $current_content = null;
	private $current_error = null;
	private $current_struct = null;
	private $current_titles = null;
	private $current_breadcrump = null;
	private $render_seen = null;
	private $render_marker = null;
	private $plugins = array();
	private $has_plugins = false;
	
	
	public function __construct() {
		
		// define non-parsed-tags
		switch( ZC_PARSER ) {
			case 'TextileAlike':
				define( 'ZC_OPEN_NONE_PARSE', '{{{' );
				define( 'ZC_CLOSE_NONE_PARSE', '}}}' );
				include_once( ZC_DIR. '/inc/classTextileAlike.php' );
				break;
			case 'Textile':
				define( 'ZC_OPEN_NONE_PARSE', '<notextile>' );
				define( 'ZC_CLOSE_NONE_PARSE', '</notextile>' );
				include_once( ZC_DIR. '/inc/classTextile.php' );
				break;
			case 'Markdown':
				define( 'ZC_OPEN_NONE_PARSE', '{{{' );
				define( 'ZC_CLOSE_NONE_PARSE', '}}}' );
				include_once( ZC_DIR. '/inc/markdown.php' );
				break;
			default:
				throw new Exception( 'Use either "Textitle", "TextileAlike" or "Markdown" as ZC_PARSER' );
				break;
		}
		
		// init parser
		if ( strpos( ZC_PARSER, 'Textile' ) === 0 ) {
			$parser = ZC_PARSER;
			$this->textile = new $parser();
		}
		
		// include
		if ( is_dir( ZC_DIR. DS. 'plugins' ) ) {
			$dh = opendir( ZC_DIR. DS. 'plugins' );
			while( ( $file = readdir( $dh ) ) !== false ) {
				if ( ! preg_match( '/\.php$/', $file ) ) continue;
				include_once( ZC_DIR. DS. 'plugins'. DS. $file );
				if ( preg_match( '/^class(ZeroCmsPlugin(.+))\.php$/', $file, $match ) ) {
					$this->plugins[ strtolower( $match[2] ) ] = new $match[1]( &$this );
					$this->has_plugins = true;
				}
			}
			closedir( $dh );
		}
	}
	
	/**
	 * Run CMS
	 * <code>
	 * <?php
	 * // in index.php
	 * include_once( __DIR__. '/config.php' );
	 * include_once( __DIR__. '/inc/classZeroCms.php' );
	 * include_once( __DIR__. '/inc/classTextile.php' );
	 * 
	 * $zerocms = new ZeroCms();
	 * $zerocms->run();
	 * ?>
	 * </code>
	 *
	 * @return void
	 * @access public
	 */
	public function run() {
		$start = microtime(true);
		$parser = ZC_PARSER;
		
		// init session
		session_start();
		
		// are we admin ?
		$this->is_admin = $this->isAdmin();
		
		// get path
		$path = isset( $_REQUEST[ 'path' ] ) ? $_REQUEST[ 'path' ] : null;
		if ( @empty( $path ) )
			$path = 'index';
		else
			$path = preg_replace( '#(?:\.tx|/+)$#', '', $path );
		$this->current_path = $path;
		zc_log( "PATH '$path'" );
		
		// admin can edit and save
		if ( $this->is_admin ) {
			
			// save ?
			if ( ! @empty( $_POST[ 'content' ] ) ) {
				try {
					$this->_pluginRunHook( 'PreSave', array( $path ) );
					$this->_savePost( $_POST[ 'content' ], $path );
					$this->_pluginRunHook( 'PostSave', array( $path ) );
				}
				catch( Exception $e ) {}
				header( 'Location: /'. $path );
			}
			
			// edit ?
			elseif ( isset( $_REQUEST[ 'edit' ] ) ) {
				print $this->_editForm( $path );
				return;
			}
			
			// clear cache ?
			elseif ( isset( $_REQUEST[ 'clear-cache' ] ) ) {
				$this->_cacheClear();
				$this->_pluginRunHook( 'PostClearCache', array( $path ) );
				header( 'Location: /'. $path );
				return;
			}
		}
		
		// dont give a ** about non-utf8
		#header( 'Content-type: text/html; charset='+ ZC_CHARSET_OUT );
		header( 'Content-type: text/html' );
		
		// get site contents
		$this->render_marker = array();
		$this->render_seen = array();
		
		$content = null;
		try {
			$this->_pluginRunHook( 'PreContentRender', array( $path ) );
			$content = $this->_renderPage( $path );
			$this->_pluginRunHook( 'PostContentRender', array( $path, $content ) );
		}
		catch( ZeroCmsHookException $e ) {
			if ( $e->hasArg( 'content' ) ) {
				$content = $e->getArg( 'content' );
				if ( ! $e->hasArg( 'noLayout' ) || $e->getArg( 'noLayout' ) !== true )
					$content = $this->_renderLayout( $content );
			}
			elseif ( $e->hasArg( 'fallback' ) && $e->getArg( 'fallback' ) === true )
				$content = $this->_renderPage( $path );
			error_log( "** EXCEPTION: '$e' **" );
		}
		
		// no content received -> 404
		if ( is_null( $content ) ) {
			$content = '<div class="error">Page not found 404</div>';
			$this->current_error = '404';
			$content = $this->_renderLayout( $content );
		}
		else
			$this->current_error = null;
		
		// render out
		if ( ZC_PRINT_RENDER_TIME === true )
			$content .= '<!-- Render '. ( microtime(true) - $start ). ' seconds -->';
		
		// print, done
		print $content;
	}
	
	
	/**
	 * Returns bool wheter this current session user is admin or not.
	 * Also takes care of authentication -> login and logout.
	 *
	 * @return bool
	 * @access public
	 */
	public function isAdmin() {
		if ( ! is_null( $this->is_admin ) )
			return $this->is_admin;
		
		// is logged in
		if ( isset( $_SESSION[ 'admin' ] ) && $_SESSION[ 'admin' ] == 'yes' ) {
			
			// want log out
			if ( isset( $_REQUEST[ 'logout' ] ) && $_REQUEST[ 'logout' ] == '1' ) {
				unset( $_SESSION[ 'admin' ] );
				return false;
			}
			return true;
		}
		
		// do login
		elseif ( isset( $_REQUEST[ 'login' ] )
			&& $_REQUEST[ 'login' ] == ZC_ADMIN_LOGIN
			&& isset( $_REQUEST[ 'password' ] )
			&& $_REQUEST[ 'password' ] == ZC_ADMIN_PASSWORD
		) {
			$_SESSION[ 'admin' ] = 'yes';
			return true;
		}
		
		// no way
		return false;
	}
	
	
	
	
	/**
	 * Returns the current rendered content of the page. Probably used in your layout.php only
	 *
	 * @return string
	 * @access public
	 */
	public function getContent() {
		return $this->current_content;
	}
	
	
	/**
	 * Returns the page title which is either the path or defined via ###title directive
	 *
	 * @return string
	 * @access public
	 */
	public function getTitle( $path = null ) {
		$this->_getSiteStruture();
		if ( is_null( $path ) ) $path = $this->getPath();
		zc_log( "Titles ($path) ". print_r( $this->current_titles, true ) );
		return isset( $this->current_titles[ $path ] )
			? $this->current_titles[ $path ]
			: $path
		;
	}
	
	
	/**
	 * Returns the current path (eg /some/sub/page)
	 *
	 * @return string
	 * @access public
	 */
	public function getPath() {
		return $this->current_path;
	}
	
	
	/**
	 * Returns the relative path to the theme dir. 
	 *
	 * Example
	 * <code>
	 * // in your layout.php
	 * <img src="<?php echo $zerocms->getThemeDir(); ?>/images/some" />
	 * </code>
	 *
	 * @return string
	 * @access public
	 */
	public function getThemeDir( $from_abs = false ) {
		return ( $from_abs ? '/' : ZC_RELDIR ). 'themes/'. ZC_THEME;
	}
	
	
	/**
	 * Returns the current error. So far, either null or "404"
	 *
	 * @return mixed
	 * @access public
	 */
	public function getError() {
		return $this->current_error;
	}
	
	
	/**
	 * Returns the current path (eg /some/sub/page)
	 *
	 * Example
	 * <code>
	 * // in your layout.php
	 * <ul class="navi">
	 *   <li class="<?php echo $zerocms->isInPath( '/some/sub' ) ? 'selected: ''; ?>"><a href="/some/sub">Some Sub</a></li>
	 *   <li class="<?php echo $zerocms->isInPath( '/some/other' ) ? 'selected: ''; ?>"><a href="/some/other">Some Other</a></li>
	 * </ul>
	 * </code>
	 *
	 * @return string
	 * @access public
	 */
	public function isInPath( $check ) {
		$path = $this->getPath();
		if ( empty( $check ) ) return false;
		if ( strpos( $check, ZC_RELDIR ) === 0 )
			$check = substr( $check, strlen( ZC_RELDIR ) );
		$check = preg_replace( '#(^\/|\/$)#', '', $check );
		zc_log( "Check '$path' vs '$check'" );
		zc_log( "  Same: ". ( $path == $check ? 'YES' : 'NO' ) );
		zc_log( "  Contain: ". ( strpos( $path, $check ) === 0 ? 'YES' : 'NO' ) );
		return $path == $check || strpos( $path, $check ) === 0;
	}
	
	
	/**
	 * Returns the rendered navi, based on the ste structure in the content folder
	 * All files not ending with ".tx" or beginning with "admin-" or "." will be
	 * ignored, as well as any files in the "snippets/" subfolder.
	 *
	 * @return string
	 * @param string $tag_name defaults to "ul", you can use "ol" instead.
	 * @access public
	 */
	public function getNavi( $tag_name = 'ul' ) {
		
		// check cache
		if ( ! is_null( $cached = $this->cacheRead( 'site-navi-'. $this->getPath() ) ) )
			return $cached;
		
		// get site strucutre
		$struct = $this->_getSiteStruture();
		
		// make a list we can work with
		$list = array();
		foreach ( $struct as $idx => $item )
			$list []= array( $item[ 'level' ], $item[ 'title' ], ZC_RELDIR. $item[ 'full' ] );
		
		// build html
		$html = $this->_buildNaviListHtml( $list, $tag_name, 'navi', array( 'isInPath' ) );
		
		// write cache (?)
		$this->cacheWrite( 'site-navi-'. $this->getPath(), $html );
		
		return $html;
	}
	
	
	
	/**
	 * Returns the rendered breadcrump navigation
	 *
	 * @return string
	 * @access public
	 */
	public function getBreadCrump() {
		zc_log( "WILL PRINT '". $this->current_breadcrump. "'" );
		return $this->current_breadcrump;
	}
	
	
	
	public function getPlugin( $name ) {
		if ( isset( $this->plugins[ $name ] ) )
			return $this->plugins[ $name ];
		return null;
	}
	
	private function _pluginRunHook( $hook, $args = array() ) {
		$hook_method = "hook$hook";
		foreach ( $this->plugins as $name => $instance ) {
			if ( ! method_exists( $instance, $hook_method ) ) continue;
			call_user_method_array( $hook_method, $instance, $args );
		}
		return true;
	}
	
	
	/**
	 * Writes to cache.
	 * Uses either APC or file-cache according to ZC_CACHE ("apc" or "file")
	 *
	 * @param string $name the name of the cache
	 * @param mixed $value the data to be stored
	 * @return void
	 * @access private
	 */
	public function cacheWrite( $name, $value ) {
		if ( ZC_CACHE == 'none' || $this->isAdmin() ) return;
		$name = $this->_cacheName( $name );
		if ( ZC_CACHE == 'apc' ) {
			apc_store( ZC_CACHE_PREFIX. $name, $value );
		}
		else {
			$file = ZC_CACHE_DIR . '/'. $name. '.cache';
			file_put_contents( $file, serialize( $value ) );
		}
	}
	
	
	
	/**
	 * Reads from cache.
	 * Uses either APC or file-cache according to ZC_CACHE ("apc" or "file")
	 *
	 * @param string $name the name of the cache
	 * @return mixed the content
	 * @access private
	 */
	public function cacheRead( $name ) {
		if ( ZC_CACHE == 'none' || $this->isAdmin() ) return null; // admin -> no cache
		
		$content = null;
		$name = $this->_cacheName( $name );
		if ( ZC_CACHE == 'apc' ) {
			$content = apc_fetch( ZC_CACHE_PREFIX. $name, $success );
			if ( ! $success ) $content = null;
		}
		else {
			$file = ZC_CACHE_DIR . '/'. $name. '.cache';
			if ( file_exists( $file ) )
				$content = unserialize( file_get_contents( $file ) );
		}
		return $content;
	}
	
	
	
	/**
	 * Clear the whole cache
	 *
	 * @return void
	 * @access private
	 */
	public function _cacheClear() {
		$content = null;
		if ( ZC_CACHE == 'apc' ) {
			$info = apc_cache_info( 'user' );
			foreach ( $info[ 'cache_list' ] as $item ) {
				if ( preg_match( '/^\Q'. ZC_CACHE_PREFIX. '\E/', $item[ 'info' ] ) )
					apc_delete( $item[ 'info' ] );
			}
		}
		else {
			if ( ( $dh = opendir( ZC_CACHE_DIR ) ) !== false ) {
				while( ( $file = readdir( $dh ) ) !== false ) {
					if ( ! preg_match( '/\.cache$/', $file ) || is_dir( $file ) ) continue;
					unlink( ZC_CACHE_DIR. '/'. $file );
				}
			}
		}
	}
	
	
	/**
	 * Returns bool wheter this current session user is admin or not.
	 * Also takes care of authentication -> login and logout.
	 *
	 * @param string $path which page to render
	 * @param bool $use_layout wheter to use layout or not
	 * @return string the renredered content
	 * @access private
	 */
	private function _renderPage( $path = null, $use_layout = true ) {
		if ( is_null( $path ) ) return null;
		
		// remember orig
		$path_orig = $path;
		
		// get absolute (suffixed) path
		$path = $this->_getTextileFilePath( $path );
		
		// no such file -> no good
		if ( is_null( $path ) || ! file_exists( $path ) ) return null;
		
		
		// get stat to check wheter changed
		$stat = stat( $path );
		
		// try cache, if found -> return cached
		$cached_time = $this->cacheRead( 'site-timestamp-'. $path );
		if ( ! is_null( $cached_time ) && $cached_time > $stat[9] )
			return $this->cacheRead( 'site-content-'. $path );
		
		// read contents
		$site_content = file_get_contents( $path );
		
		// parse load/render (recursive)
		$site_content = preg_replace_callback(
			'/^###(load|render)\s+(\S[^\n\r]+?)(?:[ \t]+([^\n\r]+))?$/ms',
			array( $this, '_renderContentKeywords' ),
			$site_content
		);
		
		// render toc, if any
		$site_content = $this->_renderContentToc( $site_content );
		
		// render breadcrump, if any
		$site_content = $this->_renderContentBreadCrump( $site_content );
		
		// strip title
		$site_content = preg_replace( '/^###(title|index)[^\n\r]+/ms', '', $site_content );
		
		// render textile
		$site_rendered = $this->render( $site_content );
		
		// clear paragraphs ?
		if ( ZC_CLEAR_EMPTY_PARAGRAPHS )
			$site_rendered = preg_replace( '#<p>\s*</p>#ms', '', $site_rendered );
		
		// encapse in layout
		$rendered = $use_layout
			? $this->_renderLayout( $site_rendered )
			: $site_rendered
		;
		
		// cache now (unless admin)
		$this->cacheWrite( 'site-timestamp-'. $path, time() );
		$this->cacheWrite( 'site-content-'. $path, $rendered );
		
		// return
		return $rendered;
	}
	
	
	
	
	/**
	 * Does the rendering with the used parser (Textile, TextileAlike or Markdown)
	 *
	 * @param string $text unparsed text
	 * @return string the rendered HTML
	 *
	 */
	public function render( $text ) {
		if ( ZC_PARSER == 'Textile' )
			return $this->textile->TextileThis( $text );
		elseif ( ZC_PARSER == 'TextileAlike' )
			return $this->textile->render( $text );
		elseif ( ZC_PARSER == 'Markdown' )
			return Markdown( $text );
		return $text;
	}
	
	
	
	
	
	/**
	 * Renders load- and render-keywords. Called by preg_replace_callback.
	 * "render" looks in the content folder (ZC_CONTENTS) and loads/renders another textile file
	 * "load" looks in the theme folder and includes/renders a php file
	 * 
	 * Example
	 * 
	 * in ZC_CONTENTS/snippets/somepart.tx
	 * <code>
	 * This is some part
	 * </code>
	 *
	 * in ZC_CONTENTS/something.tx
	 * <code>
	 * ###render snippets/somepart
	 * This is some part
	 * </code>
	 *
	 * @param string $matches the name of the cache
	 * @return mixed the content
	 * @access private
	 */
	private function _renderContentKeywords( $matches ) {
		$attrib = trim( $matches[2] );
		
		// load another textile file
		if ( $matches[1] == 'render' && ! isset( $this->render_seen[ $attrib ] ) ) {
			$this->render_seen[ $attrib ] = true;
			zc_log( "Render page '$attrib'" );
			$loaded = $this->_renderPage( $attrib, false );
			zc_log( "Rendered -> '$loaded'" );
			
			if ( !@empty( $matches[3] ) ) {
				foreach ( preg_split( '/\s*\|\|\s*/', $matches[3] ) as $kv ) {
					list( $k, $v ) = split( '=', $kv, 2 );
					$loaded = preg_replace( '/###'. $k. '###/', $v, $loaded );
				}
			}
			
			$marker = '###RENDERMARK#'. count( $this->render_marker ). '###';
			$this->render_marker[ $marker ] = $loaded;
			return $marker;
		}
		
		// render php page
		elseif ( $matches[1] == 'load' ) {
			
			// suffix php
			if ( ! preg_match( '/\.php$/', $attrib ) )
				$attrib .= '.php';
			
			ob_start();
			$zcms = $this;
			
			// render php
			include( ZC_DIR . $this->getThemeDir( true ) . '/'. $attrib );
			
			// get / clean buffer
			$rendered = ob_get_contents();
			ob_end_clean();
			
			$marker = '###RENDERMARK#'. count( $this->render_marker ). '###';
			$this->render_marker[ $marker ] = $rendered;
			
			return $marker;
		}
		
		return '';
	}
	
	/**
	 * Callback method for reinsert rerender marks
	 *
	 * @return array $match the match from preg_replace_callback
	 * @access private
	 */
	private function _rerenderMarker( $match ) {
		$idx = $match[1];
		return $this->render_marker[ '###RENDERMARK#'. $idx. '###' ];
	}
	
	
	/**
	 * Renders TOC
	 *
	 * @return string $site_content the updated content
	 * @access private
	 */
	private function _renderContentToc( $site_content = '' ) {
		if ( ! preg_match( '/^###toc\b[ \t]*(?:([^\n\r]+))?$/m', $site_content, $title_match ) )
			return $site_content;
		$toc = array();
		$lines = preg_split( '/\n/', $site_content );
		$rerender = array();
		foreach ( $lines as $line ) {
			if ( preg_match( '/^h([0-9]+)(?:\([^\)]+\))?\.\s+([^\n\r]+)/', $line, $match ) ) {
				$name = preg_replace( '/\-\-+/', '-',
					preg_replace( '/(?:^\-+|\-+$)/', '',
					preg_replace( '/[^a-z0-9\-_\.]/', '-',
					strtolower( $match[2] )
				) ) );
				$rerender []= ZC_OPEN_NONE_PARSE. '<a name="'. $name. '"></a>'. ZC_CLOSE_NONE_PARSE;
				$toc []= array( $match[1], $match[2], '#'. $name );
			}
			$rerender []= $line;
		}
		
		// generate TOC HTML
		$toc_html = '<div class="toc">'. ( !@empty( $title_match[1] )
			? '<h2>'. $title_match[1]. '</h2>'
			: ( ZC_TOC_DEFAULT_TITLE
				? '<h2>'. ZC_TOC_DEFAULT_TITLE. '</h2>'
				: ''
			)
		). $this->_buildNaviListHtml( $toc, 'ol', 'toc-navi' ). '</div>';
		
		// inser TOC into site
		$site_content = join( "\n", $rerender );
		$site_content = preg_replace( '/^###toc\b[ \t]*(?:([^\n\r]+))?$/m', $toc_html, $site_content );
		
		// return re-rendered content
		return $site_content;
	}
	
	
	/**
	 * Renders bread crump navigation
	 *
	 * @return string $site_content the site content
	 * @access private
	 */
	private function _renderContentBreadCrump( $site_content ) {
		
		// render bread crump
		if ( @empty( $this->current_breadcrump ) ) {
			
			// get path parts
			$paths = preg_split( '/\//', $this->getPath() );
			$cur_path = array();
			$breadcrump = array();
			
			// init html
			$html = array( '<div class="breadcrump">' );
			
			// iterate over paths and generate bread crump html
			foreach ( $paths as $path ) {
				$cur_path []= $path;
				$path = join( '/', $cur_path );
				
				// handle title, if found in path
				$title = $this->getTitle( $path );
				if ( empty( $title ) ) {
					$path .= '/index';
					$title = $this->getTitle( $path );
				}
				
				// add html
				$breadcrump []= '<a href="/'. $path. '">'. $this->getTitle( $path ). '</a>';
			}
			
			// finish html
			$html []= join( ' &gt; ', $breadcrump );
			$html []= '</div>';
			
			// save for next usage
			$this->current_breadcrump = join( '', $html );
		}
		
		// return upgraded content
		return preg_replace( '/###breadcrump\b/', $this->current_breadcrump, $site_content );
	}
	
	
	
	/**
	 * Renders contnet in layout.php
	 * Uses either APC or file-cache according to ZC_CACHE ("apc" or "file")
	 *
	 * @param string $name the name of the cache
	 * @return mixed the content
	 * @access private
	 */
	private function _renderLayout( $site_content = '' ) {
		
		// re-insert markers
		$site_content = preg_replace_callback(
			'/###RENDERMARK#([0-9]+)###/',
			array( $this, '_rerenderMarker' ),
			$site_content
		);
		
		$this->current_content = $site_content;
		ob_start();
		$zcms = $this;
		include( ZC_DIR . $this->getThemeDir( true ) . '/layout.php' );
		$rendered = ob_get_contents();
		ob_end_clean();
		return $rendered;
	}
	
	
	
	/**
	 * Cache save name
	 *
	 * @param string $name the name of the cache
	 * @return string update name
	 * @access private
	 */
	private function _cacheName( $name ) {
		return preg_replace( '/[^a-z0-9\-_]/', '', strtolower( $name ) ). '-'. md5( $name );
	}
	
	
	
	/**
	 * Get absolute path to textile file
	 *
	 * @param string $path path
	 * @param bool $get_path wheter to return not null, even if the path does not exist
	 * @return string the path
	 * @access private
	 */
	private function _getTextileFilePath( $path, $get_path = false ) {
		$path = preg_replace( '#(?:\.tx|/+)$#', '', $path );
		$abs = ZC_CONTENTS . '/'. $path;
		
		// index in dir
		if ( is_dir( $abs ) ) {
			if ( is_file( $abs . '/index.tx' ) )
				return $abs. '/index.tx';
			elseif ( is_file( $abs . '/.index.tx' ) )
				return $abs. '/.index.tx';
			if ( $get_path )
				return $abs . '/index.tx';
		}
		
		// get parts
		$filepart = preg_replace( '#^.*/#', '', $path );
		$dirpart  = preg_replace( '#/[^/]+$#', '', $path );
		if ( $filepart == $dirpart ) $dirpart = '';
		$filepart .= '.tx';
		$abs_dir = ZC_CONTENTS. ( empty( $dirpart ) ? '' : '/'. $dirpart );
		$abs .= '.tx';
		
		// existing file
		if ( is_file( $abs ) )
			return $abs;
		elseif ( is_file( $abs_dir. '/.'. $filepart ) )
			return $abs_dir. '/.'. $filepart;
		if ( $get_path )
			return $abs;
		
		return null;
	}
	
	
	
	/**
	 * Admin edit formular
	 *
	 * @param string $path path
	 * @return string the formular HTML
	 * @access private
	 */
	private function _editForm( $path ) {
		$path_orig = $path;
		$path = $this->_getTextileFilePath( $path, true );
		$content = file_exists( $path )
			? file_get_contents( $path )
			: ''
		;
		return $this->_renderLayout( join( '', array(
			'<form id="adminedit" method="post">',
				'<textarea name="content">',
					preg_replace( '/</', '&lt;', preg_replace( '/>/', '&gt;', $content ) ),
				'</textarea>',
				'<input type="hidden" name="path" value="'. $path_orig. '" />',
				'<button name="save">Save</button>',
			'</form>'
		) ) );
	}
	
	
	
	/**
	 * Read all .tx files and build a structure out of it
	 *
	 * @param string $path path
	 * @return mixed structure array
	 * @access private
	 */
	private function _getSiteStruture() {
		
		if ( ! is_null( $this->current_struct ) )
			return $this->current_struct;
		
		// don't bother reloading, if we are NOT admin and there IS a strucuture in cache
		if ( ! is_null( $cached_struct = $this->cacheRead( 'site-structure' ) )
			&& ! is_null( $cached_titles = $this->cacheRead( 'site-titles' ) )
		) {
			$this->current_struct = $cached_struct;
			$this->current_titles = $cached_titles;
			return $cached_struct;
		}
		
		// build structure
		$struct = $this->_readDirRecursiv( ZC_CONTENTS );
		$plain_struct = array();
		$site_titles = array();
		$site_struct = array();
		/*print_r( $struct );*/
		
		// sort function
		function sort_pos( $a, $b ) {
			return strcmp( $a[ 'sortname' ], $b[ 'sortname' ] );
		}
		
		// parse and sort results
		function parse_rec( &$struct, &$site_titles, &$site_struct ) {
			foreach ( $struct as $name => &$sub ) {
				$sub[ 'sortname' ] = sprintf( '%06d-%s', $sub[ 'pos' ], $name );
			}
			uasort( $struct, 'sort_pos' );
			foreach ( $struct as $name => &$sub ) {
				if ( isset( $site_titles[ $name ] ) ) continue;
				$site_titles[ $name ] = $sub[ 'title' ];
				$parts = preg_split( '#/#', $name );
				$level = count( $parts );
				if ( isset( $site_struct[ $name ] ) ) print "OVERWRITE '$name'\n";
				$site_struct[ $name ] = array(
					'full'	=> $name,
					'level'	=> $level,
					'name'	=> $parts[ $level - 1 ],
					'title'	=> $sub[ 'title' ]
				);
				if ( !@empty( $sub[ 'sub' ] ) ) {
					$site_titles[ $name. '/index' ] = $site_titles[ $name ];
					parse_rec( $sub[ 'sub' ], $site_titles, $site_struct );
				}
			}
		}
		parse_rec( $struct, $site_titles, $site_struct );
		
		/*print_r( array( $site_titles, $site_struct ) );
		throw( new Exception( "ASD" ) );*/
		
		try {
			$this->_pluginRunHook( 'PostSiteStructGeneration', array( &$site_struct ) );
		}
		catch( Exception $e ) {}
		
		// save to stash
		$this->current_struct = $site_struct;
		$this->current_titles = $site_titles;
		
		// write to cache
		$this->cacheWrite( 'site-titles', $site_titles );
		$this->cacheWrite( 'site-structure', $site_struct );
		
		return $site_struct;
	}
	
	
	
	/**
	 * Used by structure building
	 *
	 * @param string $path path
	 * @param mixed $structure path structure
	 * @param string $prefix path
	 * @return mixed structure array
	 * @access private
	 */
	private function _readDirRecursiv( $path, $prefix = '', &$seen = array() ) {
		$struct = array();
		if ( ( $dir = opendir( $path ) ) !== false ) {
			while( ( $file = readdir( $dir ) ) !== false ) {
				
				// ignore curent and upper dir and all hidden files
				if ( preg_match( '/^\./', $file ) )
					continue;
				
				// get abs path
				$abs = $path . '/'. $file;
				/*print( "READING '$abs'\n" );*/
				
				// seen ?
				if ( isset( $seen[ $abs ] ) )
					continue;
				$seen[ $abs ] = true;
				
				// ignore snippets folders and admin files
				if ( $abs == ZC_CONTENTS. '/snippets' || preg_match( '/^admin-/', $file ) )
					continue;
				
				// we will determine title later one
				$title_file = $abs;
				$title = $file;
				$struct_name = null;
				
				// found tx file
				if ( is_file( $abs ) && preg_match( '/^(.+)\.tx$/', $file, $match ) ) {
					$struct_name = $prefix. $match[1];
					$struct_name = preg_replace( '#/index$#', '', $struct_name );
					if ( ! isset( $struct[ $struct_name ] ) ) {
						$title = $match[1];
						$struct[ $struct_name ] = array(
							'title' => $title,
							'file'	=> $abs,
							'pos'	=> 99999,
							'sub'	=> array()
						);
					}
				}
				
				// found dir -> recurse
				elseif ( is_dir( $abs ) ) {
					$title_file = "$abs/index.tx";
					$struct_name = $prefix. $file;
					$struct[ $struct_name ] = array(
						'title' => $title,
						'file'	=> $title_file,
						'pos'	=> 99999,
					);
					$struct[ $struct_name ][ 'sub' ]
						= $this->_readDirRecursiv( $abs, $prefix. $file . '/', $seen );
					if ( isset( $struct[ $struct_name ][ 'sub' ][ $struct_name ] ) )
						unset( $struct[ $struct_name ][ 'sub' ][ $struct_name ] );
				}
				
				// determine title and index
				if ( file_exists( $title_file ) && ( $fh = fopen( $title_file, 'r' ) ) !== false ) {
					while ( ( $line = fgets( $fh ) ) !== false ) {
						if ( preg_match( '/^###title\s+(?:(\d+):\s*)?([^\n\r]+)/ms', $line, $mtitle ) ) {
							$struct[ $struct_name ][ 'title' ] = $mtitle[2];
							if ( ! @empty( $mtitle[1] ) )
								$struct[ $struct_name ][ 'pos' ] = $mtitle[1];
							fclose( $fh );
							break;
						}
					}
				}
			}
			closedir( $dir );
		}
		
		return $struct;
	}
	
	
	
	/**
	 * Used for building navigation and toc
	 *
	 * @param array $list list of items
	 * @param string $tag_name either "ol" or "ul"
	 * @param string $css_class class name of the structure
	 * @param string $selected what to selected
	 * @return mixed structure array
	 * @access private
	 */
	private function _buildNaviListHtml( $list, $tag_name, $css_class, $selected = null ) {
		if ( @empty( $list ) ) return '';
		
		// init html
		$html = array();
		$html []= '<'. $tag_name. ' class="'. $css_class. '">';
		
		// init level
		$init_level = $level = $list[0][0];
		
		// iterate through all
		foreach ( $list as $idx => $item ) {
			list( $l, $t, $n ) = $item;
			
			// increase
			if ( $level < $l ) {
				while( ++$level < $l ) {
					$html []= '<'. $tag_name. '>';
					$html []= '<li class="'. $css_class. '-level'. $l. '">';
				}
				$level = $l;
				$html []= '<'. $tag_name. '>';
			}
			
			// decraese
			elseif ( $level > $l ) {
				$html []= '</li>';
				while ( --$level >= $l ) {
					$html []= '</'. $tag_name. '>';
					$html []= '</li>';
				}
				$level = $l;
			}
			
			// same level
			else if ( $idx > 0 )
				$html []= '</li>';
			
			// selected ?
			$selected_css = '';
			if ( ! is_null( $selected )
				&& ( ( is_array( $selected ) && $this->{ $selected[0] }( $n ) )
					|| ( ! is_array( $selected ) && $selected == $n )
				)
			)
				$selected_css = $css_class. '-selected';
			
			// build li and link
			$html []= '<li class="'. $css_class. '-level'. $l. ' '. $selected_css. '">';
			$html []= '<a href="'. $n. '">'. $t. '</a>';
		}
		$html []= '</li>';
		
		// go back to start
		while ( --$level >= $init_level ) {
			$html []= '</'. $tag_name. '>';
			$html []= '</li>';
		}
		$html []= '</'. $tag_name. '>';
		
		return join( '', $html );
	}
	
	
	
	
	
	/**
	 * Administrative saving of a (new|existing) post
	 *
	 * @param string $content the content of the post
	 * @param string $path the path name to save in
	 * @return void
	 * @access private
	 */
	private function _savePost( $content, $path ) {
		$abs_path = $this->_getTextileFilePath( $path, true );
		
		// get dir
		$check_dir = $dir = dirname( $abs_path );
		
		// determine how far back we gotta go
		while( ! is_dir( $check_dir ) && strlen( $check_dir ) > 0 ) {
			$check_dir = preg_replace( '#/[^/]+$#', '', $check_dir );
		}
		
		// have to create sub-dir(s) ?
		if ( strlen( $check_dir ) < strlen( $dir ) ) {
			
			// get dir parts
			$diff = substr( $dir, strlen( $check_dir ) + 1 );
			$parts = preg_split( '/\//', $diff );
			
			// create sub dirs
			foreach ( $parts as $part ) {
				$check_dir .= '/'. array_shift( $parts );
				mkdir( $check_dir );
				if ( ! is_dir( $check_dir ) )
					throw new Exception( "Cannot create '$check_dir'" );
			}
		}
		
		// write file content
		file_put_contents( $abs_path, $content );
		if ( ! file_exists( $abs_path ) )
			throw new Exception( "Could not create file '$abs_path'" );
	}
}


class ZeroCmsHookException extends Exception {
	
	private $args = array();
	
	// Redefine the exception so message isn't optional
	public function __construct($message, $code = 0, Exception $previous = null) {
		if ( ! is_array( $message ) )
			throw new Exception( "ZeroCmsHookException needs array message format" );
		$message_str = "";
		if ( isset( $message[ 'error' ] ) ) {
			$message_str = $message[ 'error' ];
			unset( $message[ 'error' ] );
		}
		$this->args = $message;
		parent::__construct($message_str, $code, $previous);
	}
	
	public function hasArg( $name ) {
		return isset( $this->args[ $name ] );
	}
	
	public function getArg( $name ) {
		return $this->hasArg( $name ) ? $this->args[ $name ] : null;
	}
}

class ZeroCmsPlugin {
	private $zcms;
	
	public function __construct( &$zcms ) {
		$this->zcms = $zcms;
	}
	
	protected function getZcms() {
		return $this->zcms;
	}
}