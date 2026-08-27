<?php
if ( ! class_exists( 'ALAsset_Content_Hash_Sink' ) ) :
	class ALAsset_Content_Hash_Sink {
		const FLUSH_BYTES = 32768;

		private $hash;

		private $buffer = '';

		private $captured_content;

		public function __construct( $capture_content = false ) {
			if ( ! self::is_supported() ) {
				throw new RuntimeException( 'MD5 hash functions are unavailable.' );
			}

			$this->hash = hash_init( 'md5' );
			if ( false === $this->hash ) {
				throw new RuntimeException( 'MD5 hash context could not be created.' );
			}
			$this->captured_content = $capture_content ? '' : null;
		}

		public static function is_supported() {
			return function_exists( 'hash_init' )
				&& function_exists( 'hash_update' )
				&& function_exists( 'hash_final' );
		}

		public function append( $chunk ) {
			if ( '' === $chunk ) {
				return;
			}

			$this->buffer .= $chunk;
			if ( null !== $this->captured_content ) {
				$this->captured_content .= $chunk;
			}
			if ( self::FLUSH_BYTES <= strlen( $this->buffer ) ) {
				$this->flush();
			}
		}

		public function digest() {
			if ( ! $this->flush() ) {
				return false;
			}

			return hash_final( $this->hash );
		}

		public function content() {
			return $this->captured_content;
		}

		private function flush() {
			if ( '' === $this->buffer ) {
				return true;
			}

			if ( ! hash_update( $this->hash, $this->buffer ) ) {
				return false;
			}
			$this->buffer = '';
			return true;
		}
	}
endif;
