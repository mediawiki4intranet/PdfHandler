<?php
/**
 * Copyright © 2007 Martin Seidel (Xarax) <jodeldi@gmx.de>
 * Modified by Vitaliy Filippov (2011):
 *   antialiasing, respect page rotation, page links, multiple page thumbnails
 *
 * Inspired by djvuhandler from Tim Starling
 * Modified and written by Xarax
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

	var $startpage, $endpage, $pattern;

	function __construct( $file, $url, $width, $height, $path, $page, $endpage, $urlpattern ) {
		parent::__construct( $file, $url, $width, $height, $path, $page );
		$this->startpage = $page;
		$this->endpage = $endpage;
		$this->urlpattern = $urlpattern;
	}

	function toHtml( $options = array() ) {
		if ( !empty( $options['file-link'] ) )
			$options['custom-url-link'] = $this->file->getURL() . '#page=' . $this->page;
		if ( $this->endpage > $this->startpage ) {
			$html = '';
			for ( $this->page = $this->startpage; $this->page <= $this->endpage; $this->page++ ) {
				$this->url = sprintf( $this->urlpattern, $this->page );
				$html .= parent::toHtml( $options )."\n";
			}
			return $html;
		}
		return parent::toHtml( $options );
	}

}

class PdfHandler extends ImageHandler {

	function isEnabled() {
		global $wgPdfProcessor;

		if ( !isset( $wgPdfProcessor ) ) {
			wfDebug( "PdfHandler is disabled, please set the following\n" );
			wfDebug( "variables in LocalSettings.php:\n" );
			wfDebug( "\$wgPdfProcessor\n" );
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
			return preg_match( '/^\d+(-\d+)?$/s', $value );
		} else {
			return false;
		}
	}

	function makeParamString( $params ) {
		$page = isset( $params['page'] ) ? $params['page'] : 1;
		if ( !isset( $params['width'] ) ) {
			return false;
		}
		return "page{$page}-{$params['width']}px";
	}

	function parseParamString( $str ) {
		$m = false;

		if ( preg_match( '/^page(\d+(?:-\d+)?)-(\d+)px$/', $str, $m ) ) {
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
		global $wgPdfProcessor, $wgPdfOutputDevice, $wgPdfDpiRatio;

		$metadata = $image->getMetadata();

		if ( !$metadata ) {
			return $this->doThumbError( @$params['width'], @$params['height'], 'pdf_no_metadata' );
		}

		$n = $this->pageCount( $image );
		$page = isset( $params['page'] ) ? $params['page'] : 1;
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
		$srcPath = $image->getPath();

		if ( $endpage > $page ) {
			$dstPattern = preg_replace( '#page\d+-\d+-#', 'page%d-', $dstPath );
			$urlPattern = preg_replace( '#page\d+-\d+-#', 'page%d-', $dstUrl );
		} else {
			$dstPattern = $dstPath;
			$urlPattern = false;
		}

		if ( $flags & self::TRANSFORM_LATER ) {
			return new PdfThumbnailImage( $image, $dstUrl, $width,
							$height, $dstPath, $page, $endpage, $urlPattern );
		}

		if ( !wfMkdirParents( dirname( $dstPath ), null, __METHOD__ ) ) {
			return $this->doThumbError( $width, $height, 'thumbnail_dest_directory' );
		}

		$dpi = $wgPdfDpiRatio * $this->getPageDPIForWidth( $image, $page, $width );

		$doRename = false;
		$dst = $dstPattern;
		if ( strlen( $dst ) > 255 ) {
			# GhostScript fails (sometimes even crashes) if output filename length is > 255 bytes
			# Workaround it by renaming files from temporary directory
			$dst = tempnam( wfTempDir(), 'pdf-' );
			if ( $endpage > $page ) {
				$dst .= '%d';
			}
			$doRename = true;
		}
		$cmd = "$wgPdfProcessor -dUseCropBox -dTextAlphaBits=4 -dGraphicsAlphaBits=4".
			" -sDEVICE=$wgPdfOutputDevice " . wfEscapeShellArg( "-sOutputFile=$dst" ) .
			" -dFirstPage=$page -dLastPage=$endpage -r$dpi -dSAFER -dBATCH -dNOPAUSE -q " .
			wfEscapeShellArg( $srcPath ) . " 2>&1";

		wfProfileIn( 'PdfHandler' );
		wfDebug( __METHOD__ . ": $cmd\n" );
		$retval = '';
		$err = wfShellExec( $cmd, $retval );
		wfProfileOut( 'PdfHandler' );

		if ( $doRename ) {
			# Second part of GhostScript workaround
			for ( $i = $page; $i <= $endpage; $i++ ) {
				$tmp = sprintf( $dst, $i );
				if ( file_exists( $tmp ) ) {
					rename( $tmp, sprintf( $dstPattern, $i ) );
				}
			}
		}

		$removed = $this->removeBadFile( $dstPath, $retval );

		if ( $retval != 0 || $removed ) {
			wfDebugLog( 'thumbnail',
				sprintf( 'thumbnail failed on %s: error %d "%s" from "%s"',
				wfHostname(), $retval, trim( $err ), $cmd ) );
			return new MediaTransformError( 'thumbnail_error', $width, $height, $err );
		} else {
			return new PdfThumbnailImage( $image, $dstUrl, $width, $height, $dstPath, $page, $endpage, $urlPattern );
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

		$metadata = $image->getMetadata();

		if ( !$this->isMetadataValid( $image, $metadata ) ) {
			wfDebug( "Pdf metadata is invalid or missing, should have been fixed in upgradeRow\n" );
			return false;
		}

		wfProfileIn( __METHOD__ );
		wfSuppressWarnings();
		$image->pdfMetaArray = unserialize( $metadata );
		wfRestoreWarnings();
		wfProfileOut( __METHOD__ );

		return $image->pdfMetaArray;
	}

	function getImageSize( $image, $path ) {
		return $this->getPdfImage( $image, $path )->getImageSize();
	}

	function getThumbType( $ext, $mime, $params = null ) {
		global $wgPdfOutputExtension;
		static $mime;

		if ( !isset( $mime ) ) {
			$magic = MimeMagic::singleton();
			$mime = $magic->guessTypesForExtension( $wgPdfOutputExtension );
		}
		return array( $wgPdfOutputExtension, $mime );
	}

	function getMetadata( $image, $path ) {
		return serialize( $this->getPdfImage( $image, $path )->retrieveMetaData() );
	}

	function isMetadataValid( $image, $metadata ) {
		return !empty( $metadata ) && $metadata != serialize( array() );
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
