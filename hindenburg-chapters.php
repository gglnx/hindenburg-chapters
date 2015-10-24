<?php
/**
 * Plugin Name: Hindenburg Chapters for Podlove Podcast Publisher
 * Depends: Podlove Podcast Publisher
 */

use Podlove\Chapters\Chapter;
use Podlove\Chapters\Chapters;

function hxmlppp_install() {
	// Init Podlove Podlove Publisher unless it's already loaded
	if ( false == function_exists( 'load_podlove_podcast_publisher' ) )
		include_once WP_PLUGIN_DIR . '/podlove-podcasting-plugin-for-wordpress/podlove.php';

	// Try to find Hindenburg XML filetype
	$filetype = Podlove\Model\FileType::find_one_by_name( 'Hindenburg XML' );

	// Create new file type
	if ( null === $filetype ):
		$filetype = new Podlove\Model\FileType;
		$filetype->name = 'Hindenburg XML';
		$filetype->type = 'chapters';
		$filetype->mime_type = 'application/zip';
		$filetype->extension = 'zip';
		$filetype->save();
	endif;
}

register_activation_hook( __FILE__, 'hxmlppp_install' );

function hxmlppp_get_chapters_object( $chapters, $mime_type, $chapters_file, $chapter_manager ) {
	// Only run on our mime type for Hindenburg XML
	if ( 'application/zip' !== $mime_type ) {
		return $chapters;
	}

	// Cache key
	$cache_key = 'podlove_chapters_string_' . $chapter_manager->episode->id;

	// Parse Hindenburg XML
	if ( false === ( $chapters = get_transient( $cache_key ) ) ) {
		// Create new chapters object
		$chapters = new Chapters;

		// Download Hindenburg XML as temp file
		require_once ABSPATH . 'wp-admin/includes/file.php';
		add_filter( 'http_request_host_is_external', '__return_true' );
		$file = download_url( $chapters_file->get_file_url() );

		// Init ZipArchive
		$zip = new ZipArchive;

		// Check if it is a valid ZIP file
		if ( true !== ( $error_code = $zip->open( $file, ZipArchive::CHECKCONS ) ) ) {
			return $chapters;
		}

		// Check if the ZIP constains a File5.xml
		$hindenburg_xml_file = $zip->getFromName( 'File5.xml' );
		if ( false === $hindenburg_xml_file ) {
			return $chapters;
		}

		// Parse Hindenburg XML from ZIP
		$hindenburg_xml = new SimpleXMLElement( $hindenburg_xml_file );

		// Upload directory
		$episode_post = get_post( $chapter_manager->episode->post_id );
		$upload_dir = wp_upload_dir( date( 'Y/m', strtotime( $episode_post->post_date ) ) );

		// Get chapters
		foreach ( $hindenburg_xml->Chapters->children() as $chapter ) {
			// Find chapter link
			$link = '';
			foreach ( $chapter->children() as $content ) {
				if ( 'link' == $content->attributes()->type ) {
					$link = (string) $content->attributes()->url;
					break;
				}
			}

			// Add chapter image
			$image = '';
			if ( 'true' == $chapter->attributes()->cover ) {
				$id = (int) $chapter->attributes()->id;
				$image_contents = $zip->getFromName( "Images/Chapters/{$id}/Cover.jpg" );
				$filename = "/chapter_{$chapter_manager->episode->id}_{$id}.jpg";

				file_put_contents( $upload_dir['path'] . $filename, $image_contents );

				$image = $upload_dir['url'] . $filename;
			}

			// Add new chapter
			$chapters->addChapter( new Chapter(
				(int) $chapter->attributes()->start,
				(string) $chapter->attributes()->title,
				$link, $image
			) );
		}

		// Remove tmp file
		@unlink( $file );

		// Save to cache
		set_transient( $cache_key, $chapters, 60*60*24*365 );
	}

	return $chapters;
}

add_filter( 'podlove_get_chapters_object', 'hxmlppp_get_chapters_object', 10, 4 );
