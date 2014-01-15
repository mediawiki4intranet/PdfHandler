<?php
/**
 * PDF Handler extension -- handler for viewing PDF files in image mode.
 *
 * @file
 * @ingroup Extensions
 * @author Martin Seidel (Xarax) <jodeldi@gmx.de>, Vitaliy Filippov (vitalif) <vitalif@yourcmc.ru>
 * @copyright Copyright © 2007 Martin Seidel (Xarax) <jodeldi@gmx.de>
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

#########################################################################
# WARNING: pdfinfo does not respect page rotation, so if you don't want #
# to see ugly thumbnails with incorrect aspect ratio on landscape PDFs, #
# you must build your own poppler-utils with "poppler-utils.diff" patch #
# applied.                                                              #
#########################################################################

# Not a valid entry point, skip unless MEDIAWIKI is defined
if ( !defined( 'MEDIAWIKI' ) ) {
	echo 'PdfHandler extension';
	exit( 1 );
}

$wgExtensionCredits['media'][] = array(
	'path' => __FILE__,
	'name' => 'PDF Handler',
	'author' => array( 'Martin Seidel', 'Mike Połtyn', 'Vitaliy Filippov' ),
	'descriptionmsg' => 'pdf-desc',
	'url' => 'http://www.mediawiki.org/wiki/Extension:PdfHandler',
);

// External program requirements...
$wgPdfProcessor     = 'gs';
$wgPdfInfo          = 'pdfinfo';
$wgPdftoText        = 'pdftotext';

$wgPdfOutputDevice = 'jpeg';
$wgPdfOutputExtension = 'jpg';

// Now PdfHandler selects output DPI by itself, based on requested image size
// If you want more quality, specify a power of 2 here.
// Generated images will be downscaled by browser.
$wgPdfDpiRatio = 1;

// This setting, if enabled, will put creating thumbnails into a job queue,
// so they do not have to be created on-the-fly,
// but rather inconspicuously during normal wiki browsing
$wgPdfCreateThumbnailsInJobQueue = false;

// Enable PDF upload by default. If you want to forbid PDF upload - do so in your LocalSettings.php
$wgFileExtensions[] = 'pdf';

$dir = dirname( __FILE__ ) . '/';
$wgExtensionMessagesFiles['PdfHandler'] = $dir . 'PdfHandler.i18n.php';
$wgAutoloadClasses['PdfImage'] = $dir . 'PdfHandler.image.php';
$wgAutoloadClasses['PdfHandler'] = $dir . 'PdfHandler_body.php';
$wgAutoloadClasses['CreatePdfThumbnailsJob'] = $dir . 'CreatePdfThumbnailsJob.class.php';
$wgMediaHandlers['application/pdf'] = 'PdfHandler';
$wgJobClasses['createPdfThumbnailsJob'] = 'CreatePdfThumbnailsJob';
