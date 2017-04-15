<?php

/**
 * Plugin Name: Check keywords density
 * Plugin URI: http://www.wpriders.com
 * Description: This plugin will add to POSTS a new metabox which will help you check keyword density based on editor text content. There no settings for this plugin. Install it and then look into POSTS.
 * Version: 1.0.0
 * Author: Mihai Irodiu from WPRiders
 * Author URI: http://www.wpriders.com
 * License: GPL2
 */
class WPR_Keywords_Check {

	public $_keywords, $_text, $_results = array();

	function __construct() {
		// Ajax call will only respond if the user is authenticated
		add_action( 'wp_ajax_process_get_results', array( &$this, 'process_results_ajax' ) );
	}

	/*
	 * Set the keywords variable
	 */
	public function set_user_input_keywords( $keywords ) {
		if ( ! isset( $keywords ) || empty( $keywords ) ) {
			$keywords = '';
		}
		$this->_keywords = $this->sanitize_process_keywords( $keywords );
	}

	/**
	 * Capture the text content into class variable and
	 * try to clean the code using HTML Purifier,
	 * removing extra spaces and HTML tags that can prevent
	 * the code from working the right way
	 *
	 * @access public
	 *
	 * @param string $text The TinyMCE content that comes from AJAX.
	 *
	 * @return string The sanitized content.
	 */
	public function set_content_text( $text ) {
		if ( ! isset( $text ) || empty( $text ) ) {
			$text = '';
		}

		if ( '' !== trim( $text ) ) {

			// Cleaning up the code and fixing some issues
			$text = rawurldecode( $text );
			$text = htmlspecialchars_decode( $text );
			$text = stripslashes( $text );
			$text = str_replace( '<li', ' <li', $text );
			require_once 'library/HTMLPurifier.auto.php';
			$config = HTMLPurifier_Config::createDefault();

			$config->set( 'HTML.Allowed', 'p' );
			$config->set( 'AutoFormat.AutoParagraph', true );
			$config->set( 'CSS.AllowedProperties', '' );
			$config->set( 'HTML.TidyLevel', 'light' );
			$config->set( 'Output.Newline', '' );
			$config->set( 'Output.TidyFormat', true );

			$purifier   = new HTMLPurifier( $config );
			$clean_html = $purifier->purify( $text );

			$clean_html = str_replace( '</p><p>', ' ', $clean_html );
			$clean_html = str_replace( '<p>', ' ', $clean_html );
			$clean_html = str_replace( '</p>', ' ', $clean_html );
			$clean_html = preg_replace( '/\s+/', ' ', $clean_html );

			$this->_text = $clean_html;
		} else {
			$this->_text = '';
		}
	}

	/**
	 * Returns and array with the keywords that come from AJAX.
	 * Sanitizing them.
	 *
	 * @access public
	 *
	 * @param string $keywords The keywords that comes from AJAX.
	 *
	 * @return string The an array with the keywords.
	 */
	public function sanitize_process_keywords( $keywords ) {
		$keywords_array = array();
		if ( ! is_array( $keywords ) ) {
			$keywords = urldecode( $keywords );
			// create an array with the keywords, they must be delimited by ","
			$keywords_array = explode( ',', $keywords );
		} else {
			$keywords_array = array_map( 'urldecode', $keywords_array );
		}

		$keywords_array = array_map( function ( $item ) {
			return htmlspecialchars_decode( $item, ENT_QUOTES );
		}, $keywords_array ); //htmlspecialchars_decode

		$keywords_array = array_map( 'stripslashes', $keywords_array );
		$keywords_array = array_map( 'trim', $keywords_array );
		$keywords_array = array_diff( $keywords_array, array( '' ) );

		return $keywords_array;
	}

	/**
	 * Let's process the information we received and sanitized so far.
	 * This method can be called without AJAX somewhere else if you need it.
	 *
	 * @access public
	 *
	 * @return mixed The an array with the word list.
	 */
	public function process_results( $processType = '' ) {
		$words_list_array      = $this->words_list_fetch();
		$count_words_text      = count( $words_list_array );
		$keywords_found_values = array();

		if ( is_array( $this->_keywords ) && count( $this->_keywords ) > 0 ) {

			// all is good, let's proceed
			foreach ( $this->_keywords as $index => $word ) {

				// Return information about words used in a string
				// 0 - returns the number of words found
				// A list of additional characters which will be considered as 'word'
				$keyword_word_count = str_word_count( (string) $word, 0, '0..9/.:+-' );
				$word_counts        = 0;
				// if it's a single word we will use the following code
				if ( $keyword_word_count === 1 ) {
					$word_counts = array_reduce( $words_list_array, function ( $v, $n ) use ( $word ) {
						return $v + ( strtolower( $n ) === strtolower( $word ) );
					}, 0 );
					// if the keyword is formed from 2 or more words we will use RegExp
				} else {
					$word    = preg_quote( $word, '/' );
					$pattern = "/($word)/i";
					preg_match_all( $pattern, $this->_text, $matches );
					$matches = array_pop( $matches );
					if ( ! empty( $matches ) ) {
						$word_counts = count( $matches );
					}
				}

				$occurrence_percent = 0;
				if ( $word_counts > 0 && $count_words_text > 0 ) {
					$occurrence_percent = ( $word_counts / $count_words_text ) * 100 * $keyword_word_count;
					$occurrence_percent = round( $occurrence_percent, 2 );
				}

				$stading_word = 'very_low';
				if ( $occurrence_percent > 0.75 && $occurrence_percent < 3.5 ) {
					$stading_word = 'very_good';
				} elseif ( $occurrence_percent > 3.5 ) {
					$stading_word = 'very_high';
				}
				$keywords_found_values[] = array(
					'occurrences' => $occurrence_percent,
					'word'        => stripslashes( $word ),
					'standing'    => $stading_word,
					'found'       => $word_counts
				);
			}

			if ( $processType === 'ajax' ) {
				wp_send_json_success( $keywords_found_values );
			} else {
				return $keywords_found_values;
			}

		} else {
			if ( $processType === 'ajax' ) {
				wp_send_json_error( __( 'The keywords are not set, please fill the keywords separated by comma', 'wpriders_kdc' ) );
			} else {
				return __( 'The keywords are not set, please fill the keywords separated by coma', 'wpriders_kdc' );
			}
		}

		return null;
	}

	/**
	 * Capturing the content from AJAX $_POST.
	 * Saving the text content into $this->_text;
	 * Saving the keywords content into $this->_keywords;
	 *
	 * @access public
	 *
	 * @return string The an array with the word list.
	 */
	public function process_results_ajax() {
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			$this->set_user_input_keywords( $_POST['keywords'] );
			$this->set_content_text( $_POST['text'] );

			$this->process_results( 'ajax' );
		}
		wp_send_json_error( __( 'This is not a proper wordpress ajax request', 'wpriders_kdc' ) );
	}


	/**
	 * Returns and array of words from the post content.
	 *
	 * @access public
	 *
	 * @return array The array with the words list.
	 */
	public function words_list_fetch() {
		$stop_words = array(
			'',
			'-'
		);

		$word_list = array();
		if ( '' !== trim( $this->_text ) ) {
			preg_match_all( "/([a-z]{1,1}|[\w\d\:\.\'\-\/]+[\w\d])+/i", $this->_text, $matches, PREG_PATTERN_ORDER );
			$word_list = $matches[0];
			$word_list = array_map( 'trim', $word_list );
			$word_list = array_diff( $word_list, $stop_words );
		}

		return $word_list;
	}

	/**
	 * Properly strip all HTML tags including script and style, based on wordpress function wp_strip_all_tags
	 *
	 * @param string $string String containing HTML tags
	 * @param bool $remove_breaks Optional. Whether to remove left over line breaks and white space chars
	 *
	 * @return string The processed string.
	 */
	public function wpr_strip_all_tags( $string, $remove_breaks = false ) {
		$string = preg_replace( '/(<\/[^>]+?>)(<[^>\/][^>]*?>)/', '$1 $2', $string ); // strip_tags has a bug where it unites 2 words if they have html tags between them and no space, this should fix that (ex: https://regex101.com/r/dK0aJ4/1)
		$string = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $string );
		$string = strip_tags( $string );

		if ( $remove_breaks ) {
			$string = preg_replace( '/[\r\n\t ]+/', ' ', $string );
		}

		return trim( $string );
	}
}

$wpriders_kdc = new WPR_Keywords_Check();


/**
 * On post edit or new post screen, let's call the metabox
 *
 * @return mixed metabox
 */
function call_add_meta_box_keywords() {
	new WPR_Keywords_Metabox();
}


function check_for_mail_class() {
	if ( is_admin() ) {
		add_action( 'load-post.php', 'call_add_meta_box_keywords' );
		add_action( 'load-post-new.php', 'call_add_meta_box_keywords' );
	}
}

add_action( 'admin_init', 'check_for_mail_class' );


/**
 * The Class for MetaBox
 */
class WPR_Keywords_Metabox {

	/**
	 * Hook into the appropriate actions when the class is constructed.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post', array( $this, 'save' ) );
	}

	/**
	 * Adds the meta box container.
	 */
	public function add_meta_box( $post_type ) {
		$post_types = array( 'post' );   //limit meta box to certain post types
		if ( in_array( $post_type, $post_types ) ) {
			// Add the metabox, https://developer.wordpress.org/reference/functions/add_meta_box/
			add_meta_box(
				'wpriders-keyboard-density-check'
				, __( 'Keywords density check', 'wpriders_keyboard_density_check' )
				, array( $this, 'render_meta_box_content' )
				, $post_type
				, 'advanced'
				, 'high'
			);
		}
	}

	/**
	 * Save the meta when the post is saved.
	 *
	 * @param int $post_id The ID of the post being saved.
	 */
	public function save( $post_id ) {

		/*
		* We need to verify this came from the our screen and with proper authorization,
		* because save_post can be triggered at other times.
		*/

		// Check if our nonce is set.
		if ( ! isset( $_POST['wpriders_keyboard_density_check_nonce'] ) ) {
			return $post_id;
		}

		$nonce = $_POST['wpriders_keyboard_density_check_nonce'];

		// Verify that the nonce is valid.
		if ( ! wp_verify_nonce( $nonce, 'wpriders_keyboard_density_check' ) ) {
			return $post_id;
		}

		// If this is an autosave, our form has not been submitted,
		// so we don't want to do anything.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return $post_id;
		}

		// Check the user's permissions.
		if ( 'page' === $_POST['post_type'] ) {

			if ( ! current_user_can( 'edit_page', $post_id ) ) {
				return $post_id;
			}

		} else {

			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return $post_id;
			}
		}

		/* OK, its safe for us to save the data now. */

		// Sanitize the user input.
		$mydata = sanitize_text_field( $_POST['keywords_density_check_list'] );

		// Update the meta field.
		update_post_meta( $post_id, '_keywords_density_check_list', $mydata );
	}


	/**
	 * Render Meta Box content.
	 *
	 * @param WP_Post $post The post object.
	 */
	public function render_meta_box_content( $post ) {

		// Add an nonce field so we can check for it later.
		wp_nonce_field( 'wpriders_keyboard_density_check', 'wpriders_keyboard_density_check_nonce' );

		// Use get_post_meta to retrieve an existing value from the database.
		$value = get_post_meta( $post->ID, '_keywords_density_check_list', true );

		// Display the form, using the current value.
		echo '<label for="keywords_density_check_list">';
		_e( 'Keywords', 'myplugin_textdomain' );
		echo '</label> ';
		echo '<input type="text" id="keywords_density_check_list" name="keywords_density_check_list"';
		echo ' value="' . esc_attr( $value ) . '" style="width:100%" />';
		echo '<br/>';
		?>
        <button id="wpriders_check_keywords_density" class="button button-primary">Check keywords density</button>
        <br/>
        <br/>
        <table id="wpriders_keywords_report">

        </table>
        <br/>
        <br/>
        <div class="very_low legend_block">&nbsp;</div> Very low density &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
        <div class="very_good legend_block">&nbsp;</div> This looks good &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
        <div class="very_high legend_block">&nbsp;</div> Very High density &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
        <style>
            #wpriders_keywords_report {
                border-collapse: collapse;
            }

            #wpriders_keywords_report td {
                padding: 4px 20px;
            }

            #wpriders_keywords_report th,
            #wpriders_keywords_report td {
                border: 1px solid #C1C1C1;
            }

            .legend_block {
                width: 20px;
                height: 20px;
                -webkit-border-radius: 40px;
                -moz-border-radius: 40px;
                border-radius: 40px;
                display: inline-block;
            }

            .very_low {
                background-color: #FF6600;
            }

            .very_good {
                background-color: #53863B;
            }

            .very_high {
                background-color: #FF1E02;
            }

            /* new colors added also to the text response */
            .very_low_c {
                color: #FF6600;
            }

            .very_good_c {
                color: #53863B;
            }

            .very_high_c {
                color: #FF1E02;
            }
        </style>
        <script type="application/javascript">
            jQuery(document).ready(function () {
                var wpriders_density_check = jQuery('#wpriders-keyboard-density-check');

                wpriders_density_check.insertBefore(jQuery('#postdivrich'));
                wpriders_density_check.on('click', '#wpriders_check_keywords_density', function (e) {
                    e.preventDefault();
                    jQuery('#wpriders_keywords_report').empty();
                    wpriders_jquery_check_keywords();
                });

                function wpriders_jquery_check_keywords() {
                    //visual editor is required, we click it to make sure you are doing everything ok
                    jQuery("#content-tmce").click();

                    var keywords_get_wpr = fixed_Encode_URI_Component(jQuery('#keywords_density_check_list').val());
                    var content_get_wpr = fixed_Encode_URI_Component(tinyMCE.get('content').getContent());

                    jQuery.ajax({
                        type: 'POST',
                        url: ajaxurl,
                        cache: false,
                        data: {action: "process_get_results", keywords: keywords_get_wpr, text: content_get_wpr},
                        success: function (response) {
                            var table_add = "<tr style='background-color:#464646; color:white; font-weight: 800'>" +
                                "<td>Keyword</td>" +
                                "<td>Density</td>" +
                                "<td>Occurrences</td>" +
                                "</tr>";
                            jQuery('#wpriders_keywords_report').append(table_add);
                            if (response.success) {
                                jQuery.each(response.data, function (i, item) {

                                    var title_color = "";
                                    if (item.standing === 'very_low') {
                                        title_color = ' class="very_low_c" ';
                                    } else if (item.standing === 'very_good') {
                                        title_color = ' class="very_good_c" ';
                                    } else if (item.standing === 'very_high') {
                                        title_color = ' class="very_high_c" ';
                                    }
                                    var text_raport_title = '<span ' + title_color + '><strong>' + item.word + '</strong></span>';
                                    var text_raport = "<tr><td>" + text_raport_title + "</td><td>" + item.occurrences + "%</td><td>" + item.found + "</td></tr>";
                                    jQuery('#wpriders_keywords_report').append(text_raport);
                                });
                            }
                        },
                        error: function (response) {

                        }
                    });
                }

            });

            function fixed_Encode_URI_Component(str) {
                return encodeURI(str);
            }
        </script>
		<?php

	}
}
