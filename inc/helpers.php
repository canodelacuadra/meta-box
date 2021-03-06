<?php
/**
 * This file contains all helpers/public functions
 * that can be used both on the back-end or front-end
 */

// Prevent loading this file directly
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'RWMB_Helper' ) )
{
	/**
	 * Wrapper class for helper functions
	 */
	class RWMB_Helper
	{
		/**
		 * Do actions when class is loaded
		 *
		 * @return void
		 */
		static function on_load()
		{
			add_shortcode( 'rwmb_meta', array( __CLASS__, 'shortcode' ) );
		}

		/**
		 * Shortcode to display meta value
		 *
		 * @param $atts Array of shortcode attributes, same as meta() function, but has more "meta_key" parameter
		 *
		 * @see meta() function below
		 *
		 * @return string
		 */
		static function shortcode( $atts )
		{
			$atts = wp_parse_args( $atts, array(
				'type'    => 'text',
				'post_id' => get_the_ID(),
			) );
			if ( empty( $atts['meta_key'] ) )
				return '';

			$meta = self::meta( $atts['meta_key'], $atts, $atts['post_id'] );

			// Get uploaded files info
			if ( in_array( $atts['type'], array( 'file', 'file_advanced' ) ) )
			{
				$content = '<ul>';
				foreach ( $meta as $file )
				{
					$content .= sprintf(
						'<li><a href="%s" title="%s">%s</a></li>',
						$file['url'],
						$file['title'],
						$file['name']
					);
				}
				$content .= '</ul>';
			}

			// Get uploaded images info
			elseif ( in_array( $atts['type'], array( 'image', 'plupload_image', 'thickbox_image', 'image_advanced' ) ) )
			{
				$content = '<ul>';
				foreach ( $meta as $image )
				{
					// Link thumbnail to full size image?
					if ( isset( $atts['link'] ) && $atts['link'] )
					{
						$content .= sprintf(
							'<li><a href="%s" title="%s"><img src="%s" alt="%s" title="%s" /></a></li>',
							$image['full_url'],
							$image['title'],
							$image['url'],
							$image['alt'],
							$image['title']
						);
					}
					else
					{
						$content .= sprintf(
							'<li><img src="%s" alt="%s" title="%s" /></li>',
							$image['url'],
							$image['alt'],
							$image['title']
						);
					}
				}
				$content .= '</ul>';
			}

			// Get post terms
			elseif ( 'taxonomy' == $atts['type'] )
			{
				$content = '<ul>';
				foreach ( $meta as $term )
				{
					$content .= sprintf(
						'<li><a href="%s" title="%s">%s</a></li>',
						get_term_link( $term, $atts['taxonomy'] ),
						$term->name,
						$term->name
					);
				}
				$content .= '</ul>';
			}

			// Normal multiple fields: checkbox_list, select with multiple values
			elseif ( is_array( $meta ) )
			{
				$content = '<ul><li>' . implode( '</li><li>', $meta ) . '</li></ul>';
			}

			else
			{
				$content = $meta;
			}

			return apply_filters( 'rwmb_shortcode', $content );
		}

		/**
		 * Find field by field ID
		 * This function finds field in meta boxes registered by 'rwmb_meta_boxes' filter
		 * Note: if users use old code to add meta boxes, this function might not work properly
		 * @param  string $id Field ID
		 * @return array|false Field params (array) if success. False otherwise.
		 */
		static function find_field( $id )
		{
			$found = false;

			// Get all meta boxes registered with 'rwmb_meta_boxes' hook
			$meta_boxes = apply_filters( 'rwmb_meta_boxes', array() );

			// Find field
			foreach ( $meta_boxes as $meta_box )
			{
				foreach ( $meta_box['fields'] as $field )
				{
					if ( $key == $field['id'] )
					{
						$found = true;
						break;
					}
				}
			}

			// If field doesn't exist, return false
			if ( ! $found )
			{
				return false;
			}

			// Normalize field to make sure all params are set properly
			$field = wp_parse_args( $field, array(
				'id'          => '',
				'multiple'    => false,
				'clone'       => false,
				'std'         => '',
				'desc'        => '',
				'format'      => '',
				'before'      => '',
				'after'       => '',
				'field_name'  => isset( $field['id'] ) ? $field['id'] : '',
				'required'    => false,
				'placeholder' => '',
			) );
			$field = call_user_func( array( RW_Meta_Box::get_class_name( $field ), 'normalize_field' ), $field );

			return $field;
		}

		/**
		 * Get post meta
		 *
		 * @param string   $key     Meta key. Required.
		 * @param int|null $post_id Post ID. null for current post. Optional
		 * @param array    $args    Array of arguments. Optional.
		 *
		 * @return mixed
		 */
		static function meta( $key, $args = array(), $post_id = null )
		{
			$post_id = empty( $post_id ) ? get_the_ID() : $post_id;

			$args = wp_parse_args( $args, array(
				'type' => 'text',
			) );

			// Set 'multiple' for fields based on 'type'
			if ( ! isset( $args['multiple'] ) )
				$args['multiple'] = in_array( $args['type'], array( 'checkbox_list', 'file', 'file_advanced', 'image', 'image_advanced', 'plupload_image', 'thickbox_image' ) );

			$meta = get_post_meta( $post_id, $key, ! $args['multiple'] );

			// Get uploaded files info
			if ( in_array( $args['type'], array( 'file', 'file_advanced' ) ) )
			{
				if ( is_array( $meta ) && ! empty( $meta ) )
				{
					$files = array();
					foreach ( $meta as $id )
					{
						// Get only info of existing attachments
						if ( get_attached_file( $id ) )
						{
							$files[$id] = self::file_info( $id );
						}
					}
					$meta = $files;
				}
			}

			// Get uploaded images info
			elseif ( in_array( $args['type'], array( 'image', 'plupload_image', 'thickbox_image', 'image_advanced' ) ) )
			{
				global $wpdb;

				$meta = $wpdb->get_col( $wpdb->prepare( "
					SELECT meta_value FROM $wpdb->postmeta
					WHERE post_id = %d AND meta_key = '%s'
					ORDER BY meta_id ASC
				", $post_id, $key ) );

				if ( is_array( $meta ) && ! empty( $meta ) )
				{
					$images = array();
					foreach ( $meta as $id )
					{
						// Get only info of existing attachments
						if ( get_attached_file( $id ) )
						{
							$images[$id] = self::image_info( $id, $args );
						}
					}
					$meta = $images;
				}
			}

			// Get terms
			elseif ( 'taxonomy_advanced' == $args['type'] )
			{
				if ( ! empty( $args['taxonomy'] ) )
				{
					$term_ids = array_map( 'intval', array_filter( explode( ',', $meta . ',' ) ) );

					// Allow to pass more arguments to "get_terms"
					$func_args = wp_parse_args( array(
						'include'    => $term_ids,
						'hide_empty' => false,
					), $args );
					unset( $func_args['type'], $func_args['taxonomy'], $func_args['multiple'] );
					$meta = get_terms( $args['taxonomy'], $func_args );
				}
				else
				{
					$meta = array();
				}
			}

			// Get post terms
			elseif ( 'taxonomy' == $args['type'] )
			{
				$meta = empty( $args['taxonomy'] ) ? array() : wp_get_post_terms( $post_id, $args['taxonomy'] );
			}

			// Get map
			elseif ( 'map' == $args['type'] )
			{
				$meta = self::map( $key, $args, $post_id );
			}

			return apply_filters( 'rwmb_meta', $meta, $key, $args, $post_id );
		}

		/**
		 * Get uploaded file information
		 *
		 * @param int $id Attachment file ID (post ID). Required.
		 *
		 * @return array|bool False if file not found. Array of (id, name, path, url) on success
		 */
		static function file_info( $id )
		{
			$path = get_attached_file( $id );

			return array(
				'ID'    => $id,
				'name'  => basename( $path ),
				'path'  => $path,
				'url'   => wp_get_attachment_url( $id ),
				'title' => get_the_title( $id ),
			);
		}

		/**
		 * Get uploaded image information
		 *
		 * @param int   $id   Attachment image ID (post ID). Required.
		 * @param array $args Array of arguments (for size). Required.
		 *
		 * @return array|bool False if file not found. Array of (id, name, path, url) on success
		 */
		static function image_info( $id, $args = array() )
		{
			$args = wp_parse_args( $args, array(
				'size' => 'thumbnail',
			) );

			$img_src = wp_get_attachment_image_src( $id, $args['size'] );
			if ( empty( $img_src ) )
				return false;

			$attachment = get_post( $id );
			$path       = get_attached_file( $id );

			return array(
				'ID'          => $id,
				'name'        => basename( $path ),
				'path'        => $path,
				'url'         => $img_src[0],
				'width'       => $img_src[1],
				'height'      => $img_src[2],
				'full_url'    => wp_get_attachment_url( $id ),
				'title'       => $attachment->post_title,
				'caption'     => $attachment->post_excerpt,
				'description' => $attachment->post_content,
				'alt'         => get_post_meta( $id, '_wp_attachment_image_alt', true ),
			);
		}

		/**
		 * Display map using Google API
		 *
		 * @param  string   $key     Meta key
		 * @param  array    $args    Map parameter
		 * @param  int|null $post_id Post ID
		 *
		 * @return string
		 */
		static function map( $key, $args = array(), $post_id = null )
		{
			$post_id = empty( $post_id ) ? get_the_ID() : $post_id;
			$loc     = get_post_meta( $post_id, $key, true );
			if ( ! $loc )
				return '';

			$parts = array_map( 'trim', explode( ',', $loc ) );

			// No zoom entered, set it to 14 by default
			if ( count( $parts ) < 3 )
				$parts[2] = 14;

			// Map parameters
			$args               = wp_parse_args( $args, array(
				'width'        => '640px',
				'height'       => '480px',
				'marker'       => true, // Display marker?
				'marker_title' => '', // Marker title, when hover
				'info_window'  => '', // Content of info window (when click on marker). HTML allowed
				'js_options'   => array(),
			) );
			$args['js_options'] = wp_parse_args( $args['js_options'], array(
				'zoom'      => $parts[2], // Default to 'zoom' level set in admin, but can be overwritten
				'mapTypeId' => 'ROADMAP', // Map type, see https://developers.google.com/maps/documentation/javascript/reference#MapTypeId
			) );

			// Counter to display multiple maps on same page
			static $counter = 0;

			$html = sprintf(
				'<div id="rwmb-map-canvas-%d" style="width:%s;height:%s"></div>',
				$counter,
				$args['width'],
				$args['height']
			);

			// Load Google Maps script only when needed
			$html .= '<script>if ( typeof google !== "object" || typeof google.maps !== "object" )
						document.write(\'<script src="//maps.google.com/maps/api/js?sensor=false"><\/script>\')</script>';
			$html .= '<script>
				( function()
				{
			';

			$html .= sprintf( '
				var center = new google.maps.LatLng( %s, %s ),
					mapOptions = %s,
					map;

				switch ( mapOptions.mapTypeId )
				{
					case "ROADMAP":
						mapOptions.mapTypeId = google.maps.MapTypeId.ROADMAP;
						break;
					case "SATELLITE":
						mapOptions.mapTypeId = google.maps.MapTypeId.SATELLITE;
						break;
					case "HYBRID":
						mapOptions.mapTypeId = google.maps.MapTypeId.HYBRID;
						break;
					case "TERRAIN":
						mapOptions.mapTypeId = google.maps.MapTypeId.TERRAIN;
						break;
				}
				mapOptions.center = center;
				map = new google.maps.Map( document.getElementById( "rwmb-map-canvas-%d" ), mapOptions );
				',
				$parts[0], $parts[1],
				json_encode( $args['js_options'] ),
				$counter
			);

			if ( $args['marker'] )
			{
				$html .= sprintf( '
					var marker = new google.maps.Marker( {
						position: center,
						map: map%s
					} );',
					$args['marker_title'] ? ', title: "' . $args['marker_title'] . '"' : ''
				);

				if ( $args['info_window'] )
				{
					$html .= sprintf( '
						var infoWindow = new google.maps.InfoWindow( {
							content: "%s"
						} );

						google.maps.event.addListener( marker, "click", function()
						{
							infoWindow.open( map, marker );
						} );',
						$args['info_window']
					);
				}
			}

			$html .= '} )();
				</script>';

			$counter ++;

			return $html;
		}
	}

	RWMB_Helper::on_load();
}

if( ! function_exists( 'rwmb_meta' ) ) 
{
  /**
   * Get post meta
   *
   * @param string   $key     Meta key. Required.
   * @param int|null $post_id Post ID. null for current post. Optional
   * @param array    $args    Array of arguments. Optional.
   *
   * @return mixed
   */
  function rwmb_meta( $key, $args = array(), $post_id = null )
  {
  	return RWMB_Helper::meta( $key, $args, $post_id );
  }
}

if( ! function_exists( 'rwmb_get_field' ) ) 
{
  /**
   * Get value of custom field.
   * This is used to replace old version of rwmb_meta key.
   *
   * @param  string   $key     Meta key. Required.
   * @param  int|null $post_id Post ID. null for current post. Optional.
   * @return mixed             false if field doesn't exist. Field value otherwise.
   */
  function rwmb_get_field( $key, $post_id = null )
  {
  	$field = RWMB_Helper::find_field( $key );

  	// Get field value
  	return $field ? RWMB_Helper::meta( $key, $field, $post_id ) : false;
  }
}

if( ! function_exists( 'rwmb_the_field' ) ) 
{
  /**
   * Display the value of a field
   *
   * @param  string   $key     Meta key. Required.
   * @param  int|null $post_id Post ID. null for current post. Optional.
   * @param  bool     $echo    Display field meta value? Default `true` which works in almost all cases. We use `false` for the [rwmb_meta] shortcode
   *
   * @return string
   */
  function rwmb_the_field( $key, $post_id = null, $echo = true )
  {
  	// Find field
  	$field = RWMB_Helper::find_field( $key );
  	if ( ! $field )
  		return;

  	// Get field meta value
  	$meta = RWMB_Helper::meta( $key, $field, $post_id );
  	if ( empty( $meta ) )
  		return;

  	// Default output is meta value
  	$output = $meta;

  	switch ( $field['type'] )
  	{
  		case 'checkbox':
  			$output = $field['name'];
  			break;
  		case 'radio':
  			$output = $field['options'][$meta];
  			break;
  		case 'file':
  		case 'file_advanced':
  			$output = '<ul>';
  			foreach ( $meta as $file )
  			{
  				$output .= sprintf(
  					'<li><a href="%s" title="%s">%s</a></li>',
  					$file['url'],
  					$file['title'],
  					$file['name']
  				);
  			}
  			$output .= '</ul>';
  			break;
  		case 'image':
  		case 'plupload_image':
  		case 'thickbox_image':
  		case 'image_advanced':
  			$output = '<ul>';
  			foreach ( $meta as $image )
  			{
  				$output .= sprintf(
  					'<li><img src="%s" alt="%s" title="%s" /></li>',
  					$image['url'],
  					$image['alt'],
  					$image['title']
  				);
  			}
  			$output .= '</ul>';
  			break;
  		case 'taxonomy':
  			$output = '<ul>';
  			foreach ( $meta as $term )
  			{
  				$output .= sprintf(
  					'<li><a href="%s" title="%s">%s</a></li>',
  					get_term_link( $term, $field['taxonomy'] ),
  					$term->name,
  					$term->name
  				);
  			}
  			$output .= '</ul>';
  			break;
  		default:
  			if ( is_array( $meta ) )
  			{
  				$output = '<ul><li>' . implode( '</li><li>', $meta ) . '</li></ul>';
  			}
  	}

  	if ( $echo )
  		echo $output;

  	return $output;
  }
}
