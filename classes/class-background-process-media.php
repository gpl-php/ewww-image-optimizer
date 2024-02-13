<?php
/**
 * Class for Background processing of Media Library images.
 *
 * @link https://ewww.io
 * @package EWWW_Image_Optimizer
 */

namespace EWWW;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Processes media uploads in background/async mode.
 *
 * Uses a db queue system to track uploads to be optimized, handling them one at a time.
 *
 * @see EWWW\Background_Process
 */
class Background_Process_Media extends Background_Process {

	/**
	 * The action name used to trigger this class extension.
	 *
	 * @access protected
	 * @var string $action
	 */
	protected $action = 'ewwwio_media_optimize';

	/**
	 * The queue name for this class extension.
	 *
	 * @access protected
	 * @var string $action
	 */
	protected $active_queue = 'media-async';

	/**
	 * Runs task for an item from the Media Library queue.
	 *
	 * Makes sure an image upload has finished processing and has been stored in the database.
	 * Then runs the usual media optimization routine on the specified item.
	 *
	 * @access protected
	 *
	 * @param array $item The id of the attachment, how many attempts have been made to process
	 *                    the item, the type of attachment, and whether it is a new upload.
	 * @return bool|array If the item is not complete, return it. False indicates completion.
	 */
	protected function task( $item ) {
		session_write_close();
		\ewwwio_debug_message( '<b>' . __METHOD__ . '()</b>' );
		ewwwio()->defer = false;
		$max_attempts   = 15;
		$id             = $item['id'];
		if ( empty( $item['attempts'] ) ) {
			ewwwio_debug_message( 'first attempt, going to sleep for a bit' );
			$item['attempts'] = 0;
			sleep( 1 ); // On the first attempt, hold off and wait for the db to catch up.
		}
		$type = get_post_mime_type( $id );
		if ( empty( $type ) ) {
			ewwwio_debug_message( "mime is missing, requeueing {$item['attempts']}" );
			sleep( 4 );
			return $item;
		}
		ewwwio_debug_message( "background processing $id, type: " . $type );
		$image_types = array(
			'image/jpeg',
			'image/png',
			'image/gif',
		);

		if ( in_array( $type, $image_types, true ) && $item['new'] && class_exists( 'wpCloud\StatelessMedia\EWWW' ) ) {
			$meta = wp_get_attachment_metadata( $id );
		} else {
			// This is unfiltered for performance, because we don't often need filtered meta.
			$meta = wp_get_attachment_metadata( $id, true );
		}
		if ( in_array( $type, $image_types, true ) && empty( $meta ) ) {
			ewwwio_debug_message( "metadata is missing, requeueing {$item['attempts']}" );
			sleep( 4 );
			return $item;
		}
		/* $meta = ewww_image_optimizer_resize_from_meta_data( $meta, $id, true, $item['new'] ); */
		$this->process_attachment( $meta, $item, $id );

		return false;
	}

	/**
	 * Queue an individual size for a media attachment.
	 *
	 * @global object $wpdb
	 * @global object $ewwwdb A clone of $wpdb unless it is lacking utf8 connectivity.
	 *
	 * @param int    $id The attachment ID number.
	 * @param string $size The thumb size (name).
	 * @param string $file_path The filesystem path for the image.
	 * @param array  $item The async data for this attachment.
	 */
	protected function queue_single_size( $id, $size, $file_path, $item ) {
		\ewwwio_debug_message( '<b>' . __METHOD__ . '()</b>' );
		global $wpdb;

		$already_optimized = ewww_image_optimizer_find_already_optimized( $file_path );

		if ( strpos( $wpdb->charset, 'utf8' ) === false ) {
			ewww_image_optimizer_db_init();
			global $ewwwdb;
		} else {
			$ewwwdb = $wpdb;
		}

		ewwwio_debug_message( "queuing optimization for $id/$size" );
		if ( ! empty( $already_optimized['id'] ) ) {
			$ewwwdb->update(
				$ewwwdb->ewwwio_images,
				array(
					'pending'       => 1,
					'attachment_id' => $id,
					'gallery'       => 'media',
					'resize'        => $size,
					'updated'       => $already_optimized['updated'],
				),
				array(
					'id' => $already_optimized['id'],
				)
			);
			$id_to_queue = $already_optimized['id'];
			ewwwio_debug_message( 'toggled db record' );
		} else {
			$image_size = ewww_image_optimizer_filesize( $file_path );
			$ewwwdb->insert(
				$ewwwdb->ewwwio_images,
				array(
					'path'          => ewww_image_optimizer_relativize_path( $file_path ),
					'gallery'       => 'media',
					'orig_size'     => $image_size,
					'attachment_id' => $id,
					'resize'        => $size,
					'pending'       => 1,
				)
			);
			$id_to_queue = $ewwwdb->insert_id;
			ewwwio_debug_message( 'inserted db record' );
		}
		if ( ! $id_to_queue ) {
			ewwwio_debug_message( 'failed to update/insert record, no ID to queue' );
			return;
		}
		ewwwio()->background_image->push_to_queue(
			array(
				'id'           => $id_to_queue,
				'new'          => $item['new'],
				'convert_once' => $item['convert_once'],
				'force_reopt'  => $item['force_reopt'],
				'force_smart'  => $item['force_smart'],
				'webp_only'    => $item['webp_only'],
			)
		);
	}

	/**
	 * Find image paths from an attachment's meta data and process each image.
	 *
	 * Called after `wp_generate_attachment_metadata` is completed (async), it also searches for retina images,
	 * and a few custom theme resizes.
	 *
	 * @global array $ewww_attachment {
	 *     Stores the ID and meta for later use with W3TC.
	 *
	 *     @type int $id The attachment ID number.
	 *     @type array $meta The attachment metadata from the postmeta table.
	 * }
	 *
	 * @param array $meta The attachment metadata generated by WordPress.
	 * @param array $item The async data for this attachment.
	 * @param int   $id The attachment ID number.
	 */
	protected function process_attachment( $meta, $item, $id ) {
		\ewwwio_debug_message( '<b>' . __METHOD__ . '()</b>' );
		if ( ! is_array( $meta ) && empty( $meta ) ) {
			$meta = array();
		} elseif ( ! is_array( $meta ) ) {
			ewwwio_debug_message( 'attachment meta is not a usable array' );
			return;
		}

		$gallery = 'media';
		$size    = 'full';
		ewwwio_debug_message( "attachment id: $id" );

		session_write_close();
		if ( $item['new'] ) {
			ewwwio_debug_message( 'this is a newly uploaded image from the async queue' );
			$new_image = true;
		} else {
			ewwwio_debug_message( 'this image is not a new upload' );
			$new_image = false;
		}

		list( $file_path, $upload_path ) = ewww_image_optimizer_attachment_path( $meta, $id );

		/**
		 * Allow altering the metadata or performing other actions before the plugin processes an attachement.
		 *
		 * @param array  $meta The attachment metadata.
		 * @param string $file_path The file path to the image.
		 * @param bool   $new_image True if this is a newly uploaded image, false otherwise.
		 */
		$meta = apply_filters( 'ewww_image_optimizer_resize_from_meta_data', $meta, $file_path, $new_image );

		if ( ! $new_image && class_exists( 'Amazon_S3_And_CloudFront' ) && ewww_image_optimizer_stream_wrapped( $file_path ) ) {
			ewww_image_optimizer_check_table_as3cf( $meta, $id, $file_path );
		}
		if ( ! ewwwio_is_file( $file_path ) && class_exists( 'wpCloud\StatelessMedia\EWWW' ) && ! empty( $meta['gs_link'] ) ) {
			$file_path = ewww_image_optimizer_remote_fetch( $id, $meta );
		}
		// If the local file is missing and we have valid metadata, see if we can fetch via CDN.
		if ( ! ewwwio_is_file( $file_path ) || ewww_image_optimizer_stream_wrapped( $file_path ) ) {
			$file_path = ewww_image_optimizer_remote_fetch( $id, $meta );
			if ( ! $file_path ) {
				ewwwio_debug_message( 'could not retrieve path' );
				return;
			}
		}
		ewwwio_debug_message( "retrieved file path: $file_path" );
		$type            = ewww_image_optimizer_mimetype( $file_path, 'i' );
		$supported_types = array(
			'image/jpeg',
			'image/png',
			'image/gif',
			'application/pdf',
			'image/svg+xml',
		);
		if ( ! in_array( $type, $supported_types, true ) ) {
			ewwwio_debug_message( "mimetype not supported: $id" );
			return;
		}

		// Queue the full-size image.
		$this->queue_single_size( $id, $size, $file_path, $item );
		// Then disable the conversion flag for all sub-sizes and derivatives.
		$item['convert_once'] = false;

		$hidpi_path = ewww_image_optimizer_get_hidpi_path( $file_path, true );
		$size      .= '-retina';
		if ( $hidpi_path ) {
			$this->queue_single_size( $id, $size, $hidpi_path, $item );
		}

		$base_dir = trailingslashit( dirname( $file_path ) );
		// Resized versions, so we can continue.
		if ( isset( $meta['sizes'] ) && ewww_image_optimizer_iterable( $meta['sizes'] ) ) {
			$disabled_sizes = ewww_image_optimizer_get_option( 'ewww_image_optimizer_disable_resizes_opt', false, true );
			ewwwio_debug_message( 'processing resizes' );
			// Process each resized version.
			$processed = array();
			foreach ( $meta['sizes'] as $size => $data ) {
				ewwwio_debug_message( "processing size: $size" );
				if ( strpos( $size, 'webp' ) === 0 ) {
					continue;
				}
				if ( ! empty( $disabled_sizes[ $size ] ) ) {
					continue;
				}
				if ( ! empty( $disabled_sizes['pdf-full'] ) && 'full' === $size ) {
					continue;
				}
				if ( empty( $data['file'] ) ) {
					continue;
				}
				// Check through all the sizes we've processed so far.
				foreach ( $processed as $proc => $scan ) {
					// If a previous resize had identical dimensions.
					if ( $scan['height'] === $data['height'] && $scan['width'] === $data['width'] ) {
						// We found a duplicate resize, so...
						// Point this resize at the same image as the previous one.
						$meta['sizes'][ $size ]['file']      = $meta['sizes'][ $proc ]['file'];
						$meta['sizes'][ $size ]['mime-type'] = $meta['sizes'][ $proc ]['mime-type'];
						continue( 2 );
					}
				}
				// If this is a unique size.
				$resize_path = str_replace( wp_basename( $file_path ), $data['file'], $file_path );
				if ( empty( $resize_path ) ) {
					ewwwio_debug_message( 'strange... $resize_path was empty' );
					continue;
				}
				$resize_path = path_join( $upload_path, $resize_path );
				if ( 'application/pdf' === $type && 'full' === $size ) {
					$size = 'pdf-full';
				}
				// Because some SVG plugins populate the resizes with the original path (since SVG is "scalable", of course).
				// Though it could happen for other types perhaps...
				if ( $resize_path === $file_path ) {
					continue;
				}

				$this->queue_single_size( $id, $size, $resize_path, $item );

				// Optimize retina images, if they exist.
				if ( function_exists( 'wr2x_get_retina' ) ) {
					$retina_path = wr2x_get_retina( $resize_path );
				} else {
					$retina_path = false;
				}
				if ( $retina_path && ewwwio_is_file( $retina_path ) ) {
					$this->queue_single_size( $id, $size . '-retina', $retina_path, $item );
				} else {
					$hidpi_path = ewww_image_optimizer_get_hidpi_path( $resize_path, true );
					if ( $hidpi_path ) {
						$this->queue_single_size( $id, $size . '-retina', $hidpi_path, $item );
					}
				}
				// Store info on the sizes we've processed, so we can check the list for duplicate sizes.
				$processed[ $size ]['width']  = $data['width'];
				$processed[ $size ]['height'] = $data['height'];
			} // End foreach().
		} // End if().

		// Original image detected.
		if ( isset( $meta['original_image'] ) && ewww_image_optimizer_get_option( 'ewww_image_optimizer_include_originals' ) ) {
			// Meta sizes don't contain a path, so we calculate one.
			$resize_path = trailingslashit( dirname( $file_path ) ) . $meta['original_image'];

			$this->queue_single_size( $id, 'original_image', $resize_path, $item );
		} // End if().

		// Process size from a custom theme.
		if ( isset( $meta['image_meta']['resized_images'] ) && ewww_image_optimizer_iterable( $meta['image_meta']['resized_images'] ) ) {
			$imagemeta_resize_pathinfo = pathinfo( $file_path );
			$imagemeta_resize_path     = '';
			foreach ( $meta['image_meta']['resized_images'] as $imagemeta_resize ) {
				$imagemeta_resize_path = $imagemeta_resize_pathinfo['dirname'] . '/' . $imagemeta_resize_pathinfo['filename'] . '-' . $imagemeta_resize . '.' . $imagemeta_resize_pathinfo['extension'];

				$this->queue_single_size( $id, '', $imagemeta_resize_path, $item );
			}
		}

		// And another custom theme.
		if ( isset( $meta['custom_sizes'] ) && ewww_image_optimizer_iterable( $meta['custom_sizes'] ) ) {
			$custom_sizes_pathinfo = pathinfo( $file_path );
			$custom_size_path      = '';
			foreach ( $meta['custom_sizes'] as $custom_size ) {
				$custom_size_path = $custom_sizes_pathinfo['dirname'] . '/' . $custom_size['file'];

				$this->queue_single_size( $id, '', $custom_size_path, $item );
			}
		}

		ewwwio()->background_image->dispatch();
	}


	/**
	 * Runs failure routine for an item from the Media Library queue.
	 *
	 * @access protected
	 *
	 * @param array $item The id of the attachment, how many attempts have been made to process
	 *                    the item and whether it is a new upload.
	 */
	protected function failure( $item ) {
		if ( empty( $item['id'] ) ) {
			return;
		}
		$file_path = false;
		$meta      = wp_get_attachment_metadata( $item['id'] );
		if ( ! empty( $meta ) ) {
			list( $file_path, $upload_path ) = ewww_image_optimizer_attachment_path( $meta, $item['id'] );
		}

		if ( $file_path ) {
			ewww_image_optimizer_add_file_exclusion( $file_path );
		}
	}
}
