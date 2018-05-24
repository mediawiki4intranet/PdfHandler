<?php
/**
 * Copyright © 2007 Martin Seidel (Xarax) <jodeldi@gmx.de>
 * Copyright © 2011+ Vitaliy Filippov <vitalif@yourcmc.ru>
 * Inspired by djvuhandler from Tim Starling
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

class PdfThumbnailImage extends ThumbnailImage {

	var $startpage, $endpage;

	function __construct( $file, $url, $width, $height, $path, $page, $endpage ) {
		parent::__construct( $file, $url, $width, $height, $path, $page );
		$this->startpage = $page;
		$this->endpage = $endpage;
	}

	function toHtml( $options = array() ) {
		$this->responsiveUrls = array();
		if( !empty( $options['file-link'] ) ) {
			// Link the thumbnail to an individual PDF page
			$options['custom-url-link'] = $this->file->getURL() . '#page=' . $this->page;
		}
		if( $this->endpage > $this->startpage ) {
			// Multiple thumbnails requested - useful, for example,
			// for embedding presentations on wiki pages
			$html = '<span class="pdfhandler">';
			$urlpattern = $this->url;
			$urlpattern = preg_replace('/page\d+-/', 'page$N-', $urlpattern);
			for( $this->page = $this->startpage; $this->page <= $this->endpage; $this->page++ ) {
				$this->url = str_replace( '$N', sprintf( "%04d", $this->page ), $urlpattern );
				$html .= parent::toHtml( $options )."\n";
			}
			$this->url = $urlpattern;
			$html .= '</span>';
			return $html;
		}
		return parent::toHtml( $options );
	}

}

class PdfHandler extends ImageHandler {

	function isEnabled() {
		global $wgPdfToCairo, $wgPdfInfo;

		if ( empty( $wgPdfToCairo ) || empty( $wgPdfInfo ) ) {
			wfDebug( "PdfHandler is disabled, please set the following\n" );
			wfDebug( "variables in LocalSettings.php:\n" );
			wfDebug( "\$wgPdfToCairo, \$wgPdfInfo\n" );
			return false;
		}
		return true;
	}

	function mustRender( $file ) {
		return true;
	}

	function isMultiPage( $file ) {
		return true;
	}

	function validateParam( $name, $value ) {
		if ( $name == 'width' || $name == 'height' ) {
			return ( $value <= 0 ) ? false : true;
		} elseif ( $name == 'page' ) {
			return preg_match( '/^\d*(-\d*)?$/s', $value );
		} else {
			return false;
		}
	}

	function makeParamString( $params ) {
		$page = isset( $params['page'] ) ? $params['page'] : 1;
		if ( !isset( $params['width'] ) ) {
			return false;
		}
		if ( strpos( $page, '-' ) !== false ) {
			list( $page, $endpage ) = explode( '-', $page, 2 );
			if( !$page ) {
				$page = 1;
			}
		}
		// Reserve space for page number, so that thumbnail filename length
		// won't depend on it, and we don't get inconsistent filenames if
		// MW will cut it to be shorter than 256 characters (filesystem limit).
		return 'page'.sprintf( "%04d", $page ).'-'.$params['width'].'px';
	}

	function parseParamString( $str ) {
		$m = false;

		if ( preg_match( '/^page(\d+)-(\d+)px$/', $str, $m ) ) {
			return array( 'width' => $m[2], 'page' => $m[1] );
		}

		return false;
	}

	function getScriptParams( $params ) {
		return array(
			'width' => $params['width'],
			'page' => $params['page'],
		);
	}

	function getParamMap() {
		return array(
			'img_width' => 'width',
			'img_page' => 'page',
		);
	}

	protected function doThumbError( $width, $height, $msg ) {
		return new MediaTransformError( 'thumbnail_error',
			$width, $height, wfMsgForContent( $msg ) );
	}

	function doTransform( $image, $dstPath, $dstUrl, $params, $flags = 0 ) {
		global $wgPdfToCairo, $wgPdfOutputFormat, $wgVersion;

		$metadata = $image->getMetadata();

		if ( !$metadata ) {
			return $this->doThumbError( @$params['width'], @$params['height'], 'pdf_no_metadata' );
		}

		$n = $this->pageCount( $image );
		$page = isset( $params['page'] ) ? $params['page'] : 1;
		if (is_numeric($page) && (intval($page)<0)) {
			// If $page < 0 — set $page from end
			$page = $n + 1 + intval($page);
		}
		$endpage = false;
		if ( strpos( $page, '-' ) !== false ) {
			list( $page, $endpage ) = explode( '-', $page, 2 );
			if ( $page === '' ) {
				$page = 1;
			}
			if ( $endpage === '' ) {
				$endpage = $n;
			}
		} else {
			$endpage = $page;
		}
		$params['page'] = $page;

		if ( !$this->normaliseParams( $image, $params ) ) {
			return new TransformParameterError( $params );
		}

		if ( $page > $n || $endpage > $n ) {
			return $this->doTHumbError( $width, $height, 'pdf_page_error' );
		}

		$width = $params['width'];
		$height = $params['height'];
		$srcPath = version_compare( $wgVersion, '1.19', '>=' ) ? $image->getLocalRefPath() : $image->getPath();

		if ( $flags & self::TRANSFORM_LATER ) {
			return new PdfThumbnailImage( $image, $dstUrl, $width,
							$height, $dstPath, $page, $endpage );
		}

		if ( !wfMkdirParents( dirname( $dstPath ), null, __METHOD__ ) ) {
			return $this->doThumbError( $width, $height, 'thumbnail_dest_directory' );
		}

		$dpi = $this->getPageDPIForWidth( $image, $page, $width );
		$page = intval($page);
		$endpage = intval($endpage);

		// Generate files in temporary directory
		$dst = tempnam(wfTempDir(), 'pdf-');
		unlink($dst);
		$cmd = $wgPdfToCairo.' '.($wgPdfOutputFormat == 'png' ? '-png' : '-jpeg').' -r '.$dpi.
			' -f '.$page.' -l '.$endpage.' '.wfEscapeShellArg($srcPath).' '.wfEscapeShellArg($dst);

		wfProfileIn( 'PdfHandler' );
		wfDebug( __METHOD__ . ": $cmd\n" );
		$retval = '';
		$err = wfShellExec( $cmd, $retval );
		wfProfileOut( 'PdfHandler' );

		// Move files from temporary directory to the destination
		$dst .= '-%0'.strlen($n).'d.'.($wgPdfOutputFormat == 'png' ? 'png' : 'jpg');
		// Rename (move) first file
		if ( file_exists( $tmp = sprintf( $dst, $page ) ) ) {
			rename( $tmp, $dstPath );
		}
		// quickImport other files directly to thumbnail path of repository
		$removed = false;
		$destPattern = preg_replace( '/page\d+-/', 'page$N-', $image->thumbName( $params ) );
		for ( $i = $page+1; $i <= $endpage; $i++ ) {
			$tmp = sprintf( $dst, $i );
			$real = str_replace( '$N', sprintf( "%04d", $i ), $destPattern );
			$status = $image->repo->quickImport( $tmp, $image->getThumbPath( $real ), $image->getThumbDisposition( $real ) );
			$removed = $removed || !$status->isOK();
			wfSuppressWarnings();
			unlink( $tmp );
			wfRestoreWarnings();
		}

		if ( $retval != 0 || $removed ) {
			wfDebugLog( 'thumbnail',
				sprintf( 'thumbnail failed on %s: error %d "%s" from "%s"',
				wfHostname(), $retval, trim( $err ), $cmd ) );
			return new MediaTransformError( 'thumbnail_error', $width, $height, $err );
		} else {
			return new PdfThumbnailImage( $image, $dstUrl, $width, $height, $dstPath, $page, $endpage );
		}
	}

	function getPdfImage( $image, $path ) {
		if ( !$image ) {
			$pdfimg = new PdfImage( $path );
		} elseif ( !isset( $image->pdfImage ) ) {
			$pdfimg = $image->pdfImage = new PdfImage( $path );
		} else {
			$pdfimg = $image->pdfImage;
		}

		return $pdfimg;
	}

	function getMetaArray( $image ) {
		if ( isset( $image->pdfMetaArray ) ) {
			return $image->pdfMetaArray;
		}

		wfProfileIn( __METHOD__ );
		$metadata = $this->isMetadataValid( $image, $image->getMetadata() );
		wfProfileOut( __METHOD__ );

		if ( !$metadata ) {
			$image->purgeCache();
			wfDebug( "Pdf metadata is invalid or missing, should have been fixed in upgradeRow\n" );
			return false;
		}

		$image->pdfMetaArray = $metadata;
		return $image->pdfMetaArray;
	}

	function getImageSize( $image, $path ) {
		return $this->getPdfImage( $image, $path )->getImageSize();
	}

	function getThumbType( $ext, $mime, $params = null ) {
		global $wgPdfOutputFormat;
		$ext = $wgPdfOutputFormat == 'png' ? 'png' : 'jpg';
		$mime = $wgPdfOutputFormat == 'png' ? 'image/png' : 'image/jpeg';
		return array( $ext, $mime );
	}

	function getMetadata( $image, $path ) {
		return serialize( $this->getPdfImage( $image, $path )->retrieveMetaData() );
	}

	function isMetadataValid( $image, $metadata ) {
		wfSuppressWarnings();
		$metadata = unserialize( $metadata );
		wfRestoreWarnings();
		if ( !empty( $metadata ) && ( empty( $metadata['pages'] ) || empty( $metadata['pages'][0] ) ) ) {
			return $metadata;
		}
		return NULL;
	}

	function pageCount( $image ) {
		$data = $this->getMetaArray( $image );
		if ( !$data ) {
			return false;
		}
		return count( $data['pages'] );
	}

	function getPageDimensions( $image, $page ) {
		$data = $this->getMetaArray( $image );
		return PdfImage::getPageSize( $data, $page );
	}

	function getPageDPIForWidth( $image, $page, $width ) {
		$data = $this->getMetaArray( $image );
		$pagesize = PdfImage::getPageSize( $data, $page );
		return intval( $width * 72 / $pagesize['width'] );
	}

	function getPageText( $image, $page ) {
		$data = $this->getMetaArray( $image, true );
		if ( !$data ) {
			return false;
		}
		if( !isset( $data['text'] ) ) {
			return false;
		}
		if( !isset( $data['text'][$page - 1] ) ) {
			return false;
		}
		return $data['text'][$page - 1];
	}

}
