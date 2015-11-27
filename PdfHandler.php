<?php
/**
 * PDF Handler extension -- handler for viewing PDF files in image mode,
 *   using Poppler library command-line tools (pdftocairo, pdfinfo, pdftotext)
 *
 * @file
 * @ingroup Extensions
 * @author Martin Seidel (Xarax) <jodeldi@gmx.de>, Vitaliy Filippov (vitalif) <vitalif@yourcmc.ru>
 * @copyright Copyright © 2007 Martin Seidel (Xarax), © 2011+ Vitaliy Filippov
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

# WARNING: pdfinfo prior to 0.20 does not respect page rotation, so if you don't
# want to see ugly thumbnails with incorrect aspect ratio on landscape PDFs,
# you must build your own poppler-utils with "poppler-utils.diff" patch applied.

# WARNING 2: you should set abbrvThreshold of your $wgLocalFileRepo to 235 or lower
# when using PdfHandler to limit thumbnail filenames to 255 characters which is
# common filesystem filename limit, i.e. you might otherwise get thumbnail generation
# errors on PDFs with very long names. Search includes/DefaultSettings.php in your
# MediaWiki installation for "$wgLocalFileRepo" for more information.

# Not a valid entry point, skip unless MEDIAWIKI is defined
if ( !defined( 'MEDIAWIKI' ) ) {
	echo 'PdfHandler extension';
	exit( 1 );
}

$wgExtensionCredits['media'][] = array(
	'path' => __FILE__,
	'version' => '2015-11-27',
	'name' => 'PDF Handler',
	'author' => array( 'Martin Seidel', 'Mike Połtyn', 'Vitaliy Filippov' ),
	'descriptionmsg' => 'pdf-desc',
	'url' => 'http://www.mediawiki.org/wiki/Extension:PdfHandler',
);

// External program requirements...
$wgPdfToCairo = 'pdftocairo';
$wgPdfInfo = 'pdfinfo';
$wgPdftoText = 'pdftotext';

$wgPdfOutputFormat = 'jpg';

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
$wgHooks['UploadComplete'][] = 'CreatePdfThumbnailsJob::insertJobs';
