<?php
require_once __DIR__ . '/asset_content_hash_sink.php';

if ( ! class_exists( 'ALAsset_Content_Normalizer' ) ) :
	class ALAsset_Content_Normalizer {
		const NORMALIZATION_VERSION = 1;

		const WHITESPACE_MASK = " \t\n\v\f\r";

		const CSS_SPECIAL_MASK = " \t\n\v\f\r'\"/";

		const REGEX_PREFIX_BYTES = '([{=,:;!?&|+-*%^~<>';

		public static function normalized_md5( $content, $type, $deadline = null ) {
			if ( ! is_string( $content ) || ( 'css' !== $type && 'js' !== $type ) || ! ALAsset_Content_Hash_Sink::is_supported() ) {
				return false;
			}

			try {
				$sink = new ALAsset_Content_Hash_Sink();
				return 'css' === $type ? self::normalize_css( $content, $deadline, $sink ) : self::normalize_js( $content, $deadline, $sink );
			} catch ( Throwable $error ) {
				return false;
			}
		}

		public static function normalize_with_content( $content, $type, $deadline = null ) {
			if ( ! is_string( $content ) || ( 'css' !== $type && 'js' !== $type ) || ! ALAsset_Content_Hash_Sink::is_supported() ) {
				return false;
			}

			try {
				$sink   = new ALAsset_Content_Hash_Sink( true );
				$digest = 'css' === $type ? self::normalize_css( $content, $deadline, $sink ) : self::normalize_js( $content, $deadline, $sink );
			} catch ( Throwable $error ) {
				return false;
			}

			if ( false === $digest ) {
				return false;
			}

			return array(
				'digest'  => $digest,
				'content' => $sink->content(),
			);
		}

		private static function normalize_css( $content, $deadline, $sink ) {
			$pending_whitespace = false;
			$has_output = false;
			$last_significant_byte = null;
			$length = strlen( $content );
			$i = 0;
			$next_deadline_check = 0;

			while ( $i < $length ) {
				if ( $i >= $next_deadline_check ) {
					if ( self::deadline_exceeded( $deadline ) ) {
						return false;
					}
					$next_deadline_check = $i + 4096;
				}

				$byte = ord( $content[$i] );
				if ( self::is_ascii_whitespace( $byte ) ) {
					if ( $has_output ) {
						$pending_whitespace = true;
					}
					$i += strspn( $content, self::WHITESPACE_MASK, $i );
				} elseif ( $byte === 39 || $byte === 34 ) {
					self::append_pending_whitespace( $sink, $pending_whitespace, 'css', $last_significant_byte, $byte );
					$pending_whitespace = false;
					$next_index = self::append_quoted( $sink, $content, $i, $byte );
					if ( $next_index === false ) {
						return false;
					}
					$i = $next_index;
					$has_output = true;
					$last_significant_byte = $byte;
				} elseif ( $byte === 47 && $i + 1 < $length && ord( $content[$i + 1] ) === 42 ) {
					$comment_end = strpos( $content, '*/', $i + 2 );
					if ( $comment_end === false ) {
						return false;
					}
					$i = $comment_end + 2;
				} else {
					$span_length = strcspn( $content, self::CSS_SPECIAL_MASK, $i );
					if ( $span_length === 0 ) {
						$span_length = 1;
					}
					self::append_pending_whitespace( $sink, $pending_whitespace, 'css', $last_significant_byte, $byte );
					$pending_whitespace = false;
					$chunk = substr( $content, $i, $span_length );
					$sink->append( $chunk );
					$has_output = true;
					$last_significant_byte = ord( $chunk[$span_length - 1] );
					$i += $span_length;
				}
			}

			if ( self::deadline_exceeded( $deadline ) ) {
				return false;
			}
			return $sink->digest();
		}

		private static function normalize_js( $content, $deadline, $sink ) {
			$state = 'normal';
			$pending_whitespace = false;
			$has_output = false;
			$last_significant_byte = null;
			$current_word = '';
			$last_word = '';
			$template_expression_depths = array();
			$template_return_depths = array();
			$length = strlen( $content );
			$i = 0;
			$next_deadline_check = 0;
			$span_stop_mask = self::WHITESPACE_MASK . "'\"`/{}";

			while ( $i < $length ) {
				if ( $i >= $next_deadline_check ) {
					if ( self::deadline_exceeded( $deadline ) ) {
						return false;
					}
					$next_deadline_check = $i + 4096;
				}

				if ( $state === 'template' ) {
					$result = self::append_template_chunk( $sink, $content, $i, $template_expression_depths, $template_return_depths );
					if ( $result === false ) {
						return false;
					}
					$i = $result[0];
					$state = $result[1];
					$last_significant_byte = $result[2];
					if ( $state === 'normal' ) {
						$current_word = '';
					}
					continue;
				}
				if ( $state === 'regex' ) {
					$result = self::append_regex_chunk( $sink, $content, $i );
					if ( $result === false ) {
						return false;
					}
					$i = $result[0];
					$state = $result[1];
					$last_significant_byte = $result[2];
					continue;
				}

				$byte = ord( $content[$i] );
				$next_byte = $i + 1 < $length ? ord( $content[$i + 1] ) : null;
				if ( self::is_ascii_whitespace( $byte ) ) {
					self::finish_word( $current_word, $last_word );
					$pending_whitespace = $has_output;
					$i += strspn( $content, self::WHITESPACE_MASK, $i );
				} elseif ( $byte === 39 || $byte === 34 ) {
					self::finish_word( $current_word, $last_word );
					self::append_pending_whitespace( $sink, $pending_whitespace, 'js', $last_significant_byte, $byte );
					$pending_whitespace = false;
					$next_index = self::append_quoted( $sink, $content, $i, $byte );
					if ( $next_index === false ) {
						return false;
					}
					$i = $next_index;
					$has_output = true;
					$last_significant_byte = $byte;
				} elseif ( $byte === 96 ) {
					self::finish_word( $current_word, $last_word );
					self::append_pending_whitespace( $sink, $pending_whitespace, 'js', $last_significant_byte, $byte );
					$pending_whitespace = false;
					$sink->append( '`' );
					$has_output = true;
					$state = 'template';
					$template_return_depths[] = count( $template_expression_depths );
					$i++;
				} elseif ( $byte === 47 && $next_byte === 42 ) {
					self::finish_word( $current_word, $last_word );
					$pending_whitespace = $has_output;
					$comment_end = strpos( $content, '*/', $i + 2 );
					if ( $comment_end === false ) {
						return false;
					}
					$i = $comment_end + 2;
				} elseif ( $byte === 47 && $next_byte === 47 ) {
					self::finish_word( $current_word, $last_word );
					$pending_whitespace = $has_output;
					$line_length = strcspn( $content, "\r\n", $i + 2 );
					$line_end = $i + 2 + $line_length;
					$i = $line_end < $length ? $line_end + 1 : $length;
				} elseif ( $byte === 47 && self::regex_can_start( $last_significant_byte, $current_word, $last_word ) ) {
					self::finish_word( $current_word, $last_word );
					self::append_pending_whitespace( $sink, $pending_whitespace, 'js', $last_significant_byte, $byte );
					$pending_whitespace = false;
					$sink->append( '/' );
					$has_output = true;
					$state = 'regex';
					$i++;
				} else {
					$span_length = strcspn( $content, $span_stop_mask, $i );
					if ( $span_length === 0 ) {
						$span_length = 1;
					}
					self::append_pending_whitespace( $sink, $pending_whitespace, 'js', $last_significant_byte, $byte );
					$pending_whitespace = false;
					$chunk = substr( $content, $i, $span_length );
					$sink->append( $chunk );
					self::update_words_from_span( $current_word, $last_word, $chunk );
					$has_output = true;
					$last_significant_byte = ord( $chunk[$span_length - 1] );
					if ( !empty( $template_expression_depths ) && $span_length === 1 ) {
						$last_depth_index = count( $template_expression_depths ) - 1;
						if ( $byte === 123 ) {
							$template_expression_depths[$last_depth_index]++;
						} elseif ( $byte === 125 ) {
							$template_expression_depths[$last_depth_index]--;
							if ( $template_expression_depths[$last_depth_index] === 0 ) {
								array_pop( $template_expression_depths );
								$state = 'template';
							}
						}
					}
					$i += $span_length;
				}
			}

			if ( $state === 'template' || $state === 'regex' || !empty( $template_expression_depths ) || !empty( $template_return_depths ) || self::deadline_exceeded( $deadline ) ) {
				return false;
			}
			return $sink->digest();
		}

		private static function append_quoted( $sink, $content, $index, $quote_byte ) {
			$length = strlen( $content );
			$i = $index;
			$sink->append( $content[$i] );
			$i++;

			while ( $i < $length ) {
				$quote_index = strpos( $content, chr( $quote_byte ), $i );
				$escape_index = strpos( $content, '\\', $i );
				$marker = self::earliest_index( $quote_index, $escape_index );
				if ( $marker === false ) {
					return false;
				}
				if ( $marker > $i ) {
					$sink->append( substr( $content, $i, $marker - $i ) );
				}
				if ( ord( $content[$marker] ) === 92 ) {
					if ( $marker + 1 >= $length ) {
						return false;
					}
					$sink->append( substr( $content, $marker, 2 ) );
					$i = $marker + 2;
				} else {
					$sink->append( $content[$marker] );
					return $marker + 1;
				}
			}

			return false;
		}

		private static function append_template_chunk( $sink, $content, $index, &$expression_depths, &$return_depths ) {
			$length = strlen( $content );
			$i = $index;
			while ( $i < $length ) {
				$escape_index = strpos( $content, '\\', $i );
				$backtick_index = strpos( $content, '`', $i );
				$dollar_index = strpos( $content, '$', $i );
				$marker = self::earliest_index( $escape_index, $backtick_index, $dollar_index );
				if ( $marker === false ) {
					return false;
				}
				if ( $marker > $i ) {
					$sink->append( substr( $content, $i, $marker - $i ) );
				}
				$byte = ord( $content[$marker] );
				if ( $byte === 92 ) {
					if ( $marker + 1 >= $length ) {
						return false;
					}
					$sink->append( substr( $content, $marker, 2 ) );
					$i = $marker + 2;
				} elseif ( $byte === 36 && $marker + 1 < $length && ord( $content[$marker + 1] ) === 123 ) {
					$sink->append( '${' );
					$expression_depths[] = 1;
					return array( $marker + 2, 'normal', 123 );
				} elseif ( $byte === 96 ) {
					if ( empty( $return_depths ) ) {
						return false;
					}
					$sink->append( '`' );
					array_pop( $return_depths );
					return array( $marker + 1, 'normal', 96 );
				} else {
					$sink->append( '$' );
					$i = $marker + 1;
				}
			}

			return false;
		}

		private static function append_regex_chunk( $sink, $content, $index ) {
			$length = strlen( $content );
			$i = $index;
			$in_character_class = false;
			while ( $i < $length ) {
				$escape_index = strpos( $content, '\\', $i );
				$open_class_index = strpos( $content, '[', $i );
				$close_class_index = strpos( $content, ']', $i );
				$slash_index = strpos( $content, '/', $i );
				$marker = self::earliest_index( $escape_index, $open_class_index, $close_class_index, $slash_index );
				if ( $marker === false ) {
					return false;
				}
				if ( $marker > $i ) {
					$sink->append( substr( $content, $i, $marker - $i ) );
				}
				$byte = ord( $content[$marker] );
				if ( $byte === 92 ) {
					if ( $marker + 1 >= $length ) {
						return false;
					}
					$sink->append( substr( $content, $marker, 2 ) );
					$i = $marker + 2;
				} else {
					$sink->append( $content[$marker] );
					if ( $byte === 91 ) {
						$in_character_class = true;
					} elseif ( $byte === 93 ) {
						$in_character_class = false;
					} elseif ( $byte === 47 && !$in_character_class ) {
						return array( $marker + 1, 'normal', 47 );
					}
					$i = $marker + 1;
				}
			}

			return false;
		}

		private static function earliest_index() {
			$earliest = false;
			foreach ( func_get_args() as $index ) {
				if ( $index !== false && ( $earliest === false || $index < $earliest ) ) {
					$earliest = $index;
				}
			}
			return $earliest;
		}

		private static function append_pending_whitespace( $sink, $pending_whitespace, $type, $previous_byte, $next_byte ) {
			if ( !$pending_whitespace ) {
				return;
			}
			if ( $type === 'css' && self::css_structural_whitespace( $previous_byte, $next_byte ) ) {
				return;
			}
			$sink->append( ' ' );
		}

		private static function css_structural_whitespace( $previous_byte, $next_byte ) {
			return $previous_byte === 59 || $previous_byte === 123 || $previous_byte === 125 ||
				$next_byte === 59 || $next_byte === 123 || $next_byte === 125;
		}

		private static function deadline_exceeded( $deadline ) {
			return $deadline !== null && microtime( true ) >= $deadline;
		}

		private static function is_ascii_whitespace( $byte ) {
			return $byte === 9 || $byte === 10 || $byte === 11 || $byte === 12 || $byte === 13 || $byte === 32;
		}

		private static function is_word_byte( $byte ) {
			return ( $byte >= 48 && $byte <= 57 ) || ( $byte >= 65 && $byte <= 90 ) || ( $byte >= 97 && $byte <= 122 ) || $byte === 95 || $byte === 36 || $byte >= 128;
		}

		private static function finish_word( &$current_word, &$last_word ) {
			if ( $current_word !== '' ) {
				$last_word = $current_word;
				$current_word = '';
			}
		}

		private static function update_words_from_span( &$current_word, &$last_word, $chunk ) {
			$scan = $current_word === '' ? $chunk : $current_word . $chunk;
			$last_byte = ord( $scan[strlen( $scan ) - 1] );
			if ( self::is_word_byte( $last_byte ) ) {
				preg_match( '/([A-Za-z0-9_$\x80-\xFF]+)$/D', $scan, $matches );
				$current_word = $matches[1];
			} else {
				$current_word = '';
				if ( preg_match( '/([A-Za-z0-9_$\x80-\xFF]+)[^A-Za-z0-9_$\x80-\xFF]*$/D', $scan, $matches ) ) {
					$last_word = $matches[1];
				}
			}
		}

		private static function regex_can_start( $last_significant_byte, $current_word, $last_word ) {
			$word = $current_word !== '' ? $current_word : $last_word;
			if ( in_array( $word, array( 'return', 'throw', 'case', 'delete', 'void', 'typeof', 'instanceof', 'in', 'of', 'yield', 'await', 'else', 'do' ), true ) ) {
				return true;
			}
			if ( $last_significant_byte === null ) {
				return true;
			}
			return strpos( self::REGEX_PREFIX_BYTES, chr( $last_significant_byte ) ) !== false;
		}
	}
endif;
