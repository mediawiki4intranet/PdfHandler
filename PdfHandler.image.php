<?php
/**
 * Copyright © 2007 Xarax <jodeldi@gmx.de>
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

/**
 * inspired by djvuimage from Brion Vibber
 * modified and written by xarax
 * then modified by Vitaliy Filippov
 */

class PdfImage {

	const BUFSIZE = 2048;

	function __construct( $filename ) {
		$this->mFilename = $filename;
	}

	/**
	 * Extract CropBox or MediaBox, if no CropBox is present,
	 * for each page of PDF file $filename, respecting Rotation
	 */
	public static function extractPageSizes( $filename ) {
		$fp = fopen( $filename, "rb" );
		if ( !$fp ) {
			return false;
		}
		$buf = '';
		$line = '';
		$pages = array();
		while( ( $buf = fread( $fp, self::BUFSIZE ) ) !== '' ) {
			$line .= $buf;
			$lines = explode( "endobj", $line );
			$line = array_pop( $lines );
			foreach( $lines as $s ) {
				if ( preg_match( '/Rotate\s+(\d+)/', $s, $m ) ) {
					$landscape = $m[1] == 90 || $m[1] == 270 ? 1 : 0;
				} else {
					$landscape = 0;
				}
				if ( preg_match( '/CropBox\s*\[([^\]]*\s*)([\d\.]+)\s+([\d\.]+)\s+([\d\.]+)\s+([\d\.]+)\s*\]/', $s, $m ) ) {
					$pages[] = array(
						'width' => intval( $m[ 4 + $landscape ] - $m[ 2 + $landscape ] ),
						'height' => intval( $m[ 5 - $landscape ] - $m[ 3 - $landscape ] ),
					);
				} elseif ( preg_match( '/MediaBox\s*\[[^\]]*\s+([\d\.]+)\s+([\d\.]+)\s*\]/', $s, $m ) ) {
					$pages[] = array(
						'width' => intval( $m[ 1 + $landscape ] ),
						'height' => intval( $m[ 2 - $landscape ] ),
					);
				}
			}
		}
		fclose( $fp );
		return $pages;
	}

	public function isValid() {
		return true;
	}

	public function getImageSize() {
		$data = $this->retrieveMetadata();
		$size = self::getPageSize( $data, 1 );

		if( $size ) {
			$width = $size['width'];
			$height = $size['height'];
			return array( $width, $height, 'Pdf',
				"width=\"$width\" height=\"$height\"" );
		}
		return false;
	}

	/**
	 * Get page size from retrieved metadata array for page $page
	 */
	public static function getPageSize( $data, $page ) {
		if( isset( $data['pages'][$page-1] ) ) {
			return array(
				'width' => $data['pages'][$page-1]['width'],
				'height' => $data['pages'][$page-1]['height'],
			);
		}
		return false;
	}

	public function retrieveMetaData() {
		global $wgPdftoText;

		# Retrieve CropBoxes manually, do not use pdfinfo,
		# as it doesn't respect page rotation
		$data = array( 'pages' => self::extractPageSizes( $this->mFilename ) );

		# Read text layer
		if ( isset( $wgPdftoText ) ) {
			wfProfileIn( 'pdftotext' );
			$cmd = wfEscapeShellArg( $wgPdftoText ) . ' '. wfEscapeShellArg( $this->mFilename ) . ' - ';
			wfDebug( __METHOD__.": $cmd\n" );
			$retval = '';
			$txt = wfShellExec( $cmd, $retval );
			wfProfileOut( 'pdftotext' );
			if( $retval == 0 ) {
				$txt = str_replace( "\r\n", "\n", $txt );
				$pages = explode( "\f", $txt );
				foreach( $pages as $page => $pageText ) {
					# Get rid of invalid UTF-8, strip control characters
					# Note we need to do this per page, as \f page feed would be stripped.
					$pages[$page] = UtfNormal::cleanUp( $pageText );
				}
				$data['text'] = $pages;
			}
		}
		return $data;
	}
}
