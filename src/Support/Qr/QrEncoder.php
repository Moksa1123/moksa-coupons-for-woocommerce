<?php

declare( strict_types=1 );

namespace Moksafocou\Support\Qr;

defined( 'ABSPATH' ) || exit;

/**
 * Self-contained QR Code matrix encoder (no third-party library, no external
 * service — the payload never leaves the server). The algorithm is a faithful
 * port of Project Nayuki's QR Code generator (MIT), reduced to exactly what a
 * coupon share-URL needs and hardened per the Phase 5 design review:
 *
 *  - Byte (8-bit) mode only — mode indicator 0100.
 *  - Error-correction level fixed at M (~15% recovery), the scanner default.
 *  - Versions 1–9 only. In byte mode the character-count indicator is 8 bits for
 *    v1–9 (16 bits only at v10+), so capping at v9 removes the 16-bit branch and
 *    its off-by-one boundary risk. v9-M holds 182 data codewords (~180 bytes) —
 *    far above any coupon URL. Oversized input returns a WP_Error (never truncated:
 *    a truncated URL is a wrong-destination QR).
 *  - Reed–Solomon ECC over GF(256) with primitive polynomial 0x11D, via table-free
 *    Russian-peasant multiplication (no hand-transcribed log tables to get wrong).
 *  - Mandatory multi-block ECC interleaving (the silent-corruption trap at v4/v5/v8/v9).
 *  - All 8 data masks are built and penalty-scored; the lowest-penalty mask wins.
 *
 * encode() returns the module matrix as a list of rows of 0/1 ints (1 = dark),
 * or a WP_Error. Correctness is gated end-to-end by a jsQR round-trip (see tests).
 */
final class QrEncoder {

	/** Hard input cap (bytes) checked before version selection. */
	public const MAX_BYTES = 256;

	/**
	 * Level-M block structure for v1–9: [ total_data_codewords, ec_codewords_per_block, [ [num_blocks, data_per_block], ... ] ].
	 *
	 * @var array<int,array{0:int,1:int,2:array<int,array{0:int,1:int}>}>
	 */
	private const BLOCKS_M = [
		1 => [ 16, 10, [ [ 1, 16 ] ] ],
		2 => [ 28, 16, [ [ 1, 28 ] ] ],
		3 => [ 44, 26, [ [ 1, 44 ] ] ],
		4 => [ 64, 18, [ [ 2, 32 ] ] ],
		5 => [ 86, 24, [ [ 2, 43 ] ] ],
		6 => [ 108, 16, [ [ 4, 27 ] ] ],
		7 => [ 124, 18, [ [ 4, 31 ] ] ],
		8 => [ 154, 22, [ [ 2, 38 ], [ 2, 39 ] ] ],
		9 => [ 182, 22, [ [ 3, 36 ], [ 2, 37 ] ] ],
	];

	/** Alignment-pattern centre coordinates per version (cartesian product, finders skipped). */
	private const ALIGN = [
		1 => [],
		2 => [ 6, 18 ],
		3 => [ 6, 22 ],
		4 => [ 6, 26 ],
		5 => [ 6, 30 ],
		6 => [ 6, 34 ],
		7 => [ 6, 22, 38 ],
		8 => [ 6, 24, 42 ],
		9 => [ 6, 26, 46 ],
	];

	/** @var array<int,int> Module size = 4*version + 17 → effective via $this->size. */

	/** @var int Symbol side length in modules. */
	private int $size = 0;

	/** @var int Chosen version 1–9. */
	private int $version = 0;

	/** @var array<int,array<int,int>> Module matrix (row-major, 0/1). */
	private array $modules = [];

	/** @var array<int,array<int,bool>> Function-module map (true = reserved, never masked). */
	private array $is_function = [];

	private function __construct() {}

	/**
	 * Encode a byte string into a QR module matrix.
	 *
	 * @param string $data Raw payload (already in its final URL byte form — do NOT
	 *                     esc_html / sanitize / lowercase it here, or `&` becomes
	 *                     `&amp;` and the scanned URL breaks).
	 * @return array<int,array<int,int>>|\WP_Error Rows of 0/1 (1 = dark), or error.
	 */
	public static function encode( string $data ) {
		$len = strlen( $data );
		if ( 0 === $len ) {
			return new \WP_Error( 'moksafocou_qr_empty', __( 'QR content cannot be empty.', 'moksafocou' ) );
		}
		if ( $len > self::MAX_BYTES ) {
			return new \WP_Error( 'moksafocou_qr_too_long', __( 'QR content is too long to generate.', 'moksafocou' ) );
		}

		$version = self::pick_version( $len );
		if ( null === $version ) {
			return new \WP_Error( 'moksafocou_qr_too_long', __( 'QR content is too long to generate.', 'moksafocou' ) );
		}

		$self          = new self();
		$self->version = $version;
		$self->size    = 4 * $version + 17;

		$codewords = $self->build_codewords( $data );

		$self->init_matrix();
		$self->draw_function_patterns();
		$self->draw_codewords( $codewords );
		$self->apply_best_mask();

		return $self->modules;
	}

	/**
	 * Smallest version 1–9 whose data capacity holds the byte payload (header = mode
	 * 4 bits + 8-bit count indicator), or null when it does not fit in v9-M.
	 */
	private static function pick_version( int $byte_len ): ?int {
		$need_bits = 4 + 8 + ( 8 * $byte_len );
		foreach ( self::BLOCKS_M as $version => $spec ) {
			if ( $need_bits <= $spec[0] * 8 ) {
				return $version;
			}
		}
		return null;
	}

	/*
	---------------------------------------------------------------------
	 * Bitstream → codewords (with ECC + interleaving)
	 * ------------------------------------------------------------------- */

	/**
	 * Build the final interleaved data+ECC codeword sequence for the payload.
	 *
	 * @return array<int,int> Codewords (0–255), in placement order.
	 */
	private function build_codewords( string $data ): array {
		$spec      = self::BLOCKS_M[ $this->version ];
		$total_dcw = $spec[0];
		$ec_per    = $spec[1];
		$groups    = $spec[2];

		// --- bit string ---
		$bits  = '';
		$bits .= '0100';                                  // Byte mode indicator.
		$bits .= str_pad( decbin( strlen( $data ) ), 8, '0', STR_PAD_LEFT ); // 8-bit CCI (v<=9).
		for ( $i = 0, $n = strlen( $data ); $i < $n; $i++ ) {
			$bits .= str_pad( decbin( ord( $data[ $i ] ) ), 8, '0', STR_PAD_LEFT );
		}

		$capacity_bits = $total_dcw * 8;
		// Terminator: up to four 0 bits, not exceeding capacity.
		$bits .= str_repeat( '0', min( 4, $capacity_bits - strlen( $bits ) ) );
		// Pad to a byte boundary.
		if ( 0 !== strlen( $bits ) % 8 ) {
			$bits .= str_repeat( '0', 8 - ( strlen( $bits ) % 8 ) );
		}

		// Data codewords from the bit string.
		$data_cw = [];
		for ( $i = 0, $n = strlen( $bits ); $i < $n; $i += 8 ) {
			$data_cw[] = (int) bindec( substr( $bits, $i, 8 ) );
		}
		// Pad codewords with the alternating 0xEC / 0x11 fillers.
		$pad      = [ 0xEC, 0x11 ];
		$pad_need = $total_dcw - count( $data_cw );
		for ( $i = 0; $i < $pad_need; $i++ ) {
			$data_cw[] = $pad[ $i % 2 ];
		}

		// --- split into blocks, compute per-block ECC ---
		$divisor    = self::rs_divisor( $ec_per );
		$block_data = [];
		$block_ec   = [];
		$offset     = 0;
		foreach ( $groups as $group ) {
			[ $num_blocks, $data_per_block ] = $group;
			for ( $b = 0; $b < $num_blocks; $b++ ) {
				$chunk        = array_slice( $data_cw, $offset, $data_per_block );
				$offset      += $data_per_block;
				$block_data[] = $chunk;
				$block_ec[]   = self::rs_remainder( $chunk, $divisor );
			}
		}

		// --- interleave data, then ECC ---
		$result   = [];
		$max_data = 0;
		foreach ( $block_data as $chunk ) {
			$max_data = max( $max_data, count( $chunk ) );
		}
		for ( $i = 0; $i < $max_data; $i++ ) {
			foreach ( $block_data as $chunk ) {
				if ( $i < count( $chunk ) ) {
					$result[] = $chunk[ $i ];
				}
			}
		}
		for ( $i = 0; $i < $ec_per; $i++ ) {
			foreach ( $block_ec as $ec ) {
				$result[] = $ec[ $i ];
			}
		}

		return $result;
	}

	/*
	---------------------------------------------------------------------
	 * GF(256) Reed–Solomon (table-free, primitive polynomial 0x11D)
	 * ------------------------------------------------------------------- */

	/** Multiply two GF(256) elements (Russian-peasant, reduction 0x11D). */
	private static function gf_mul( int $x, int $y ): int {
		$z = 0;
		for ( $i = 7; $i >= 0; $i-- ) {
			$z  = ( $z << 1 ) ^ ( ( $z >> 7 ) * 0x11D );
			$z ^= ( ( $y >> $i ) & 1 ) * $x;
		}
		return $z & 0xFF;
	}

	/**
	 * Reed–Solomon generator polynomial coefficients of the given degree.
	 *
	 * @return array<int,int>
	 */
	private static function rs_divisor( int $degree ): array {
		$result                = array_fill( 0, $degree, 0 );
		$result[ $degree - 1 ] = 1;
		$root                  = 1;
		for ( $i = 0; $i < $degree; $i++ ) {
			for ( $j = 0; $j < $degree; $j++ ) {
				$result[ $j ] = self::gf_mul( $result[ $j ], $root );
				if ( $j + 1 < $degree ) {
					$result[ $j ] ^= $result[ $j + 1 ];
				}
			}
			$root = self::gf_mul( $root, 0x02 );
		}
		return $result;
	}

	/**
	 * Reed–Solomon remainder (the ECC codewords) for a data block.
	 *
	 * @param array<int,int> $data
	 * @param array<int,int> $divisor
	 * @return array<int,int>
	 */
	private static function rs_remainder( array $data, array $divisor ): array {
		$degree = count( $divisor );
		$result = array_fill( 0, $degree, 0 );
		foreach ( $data as $b ) {
			$factor = $b ^ $result[0];
			array_shift( $result );
			$result[] = 0;
			for ( $i = 0; $i < $degree; $i++ ) {
				$result[ $i ] ^= self::gf_mul( $divisor[ $i ], $factor );
			}
		}
		return $result;
	}

	/*
	---------------------------------------------------------------------
	 * Matrix construction
	 * ------------------------------------------------------------------- */

	private function init_matrix(): void {
		$this->modules     = array_fill( 0, $this->size, array_fill( 0, $this->size, 0 ) );
		$this->is_function = array_fill( 0, $this->size, array_fill( 0, $this->size, false ) );
	}

	private function set_function( int $x, int $y, bool $dark ): void {
		if ( $x < 0 || $y < 0 || $x >= $this->size || $y >= $this->size ) {
			return;
		}
		$this->modules[ $y ][ $x ]     = $dark ? 1 : 0;
		$this->is_function[ $y ][ $x ] = true;
	}

	private function draw_function_patterns(): void {
		// Timing patterns.
		for ( $i = 0; $i < $this->size; $i++ ) {
			$this->set_function( 6, $i, 0 === $i % 2 );
			$this->set_function( $i, 6, 0 === $i % 2 );
		}

		// Finder patterns (+ separators) at three corners.
		$this->draw_finder( 3, 3 );
		$this->draw_finder( $this->size - 4, 3 );
		$this->draw_finder( 3, $this->size - 4 );

		// Alignment patterns (skip the three that collide with finder centres).
		$centres = self::ALIGN[ $this->version ];
		$count   = count( $centres );
		foreach ( $centres as $ci => $cx ) {
			foreach ( $centres as $ri => $cy ) {
				$is_corner = ( 0 === $ci && 0 === $ri )
					|| ( 0 === $ci && $count - 1 === $ri )
					|| ( $count - 1 === $ci && 0 === $ri );
				if ( ! $is_corner ) {
					$this->draw_alignment( $cx, $cy );
				}
			}
		}

		// Reserve format + version areas (drawn for real after masking).
		$this->draw_format_bits( 0 );
		$this->draw_version_bits();
	}

	private function draw_finder( int $cx, int $cy ): void {
		for ( $dy = -4; $dy <= 4; $dy++ ) {
			for ( $dx = -4; $dx <= 4; $dx++ ) {
				$dist = max( abs( $dx ), abs( $dy ) );
				$this->set_function( $cx + $dx, $cy + $dy, 2 !== $dist && 4 !== $dist );
			}
		}
	}

	private function draw_alignment( int $cx, int $cy ): void {
		for ( $dy = -2; $dy <= 2; $dy++ ) {
			for ( $dx = -2; $dx <= 2; $dx++ ) {
				$this->set_function( $cx + $dx, $cy + $dy, 1 !== max( abs( $dx ), abs( $dy ) ) );
			}
		}
	}

	/** Draw (or reserve) the 15-bit format information for level M + the given mask. */
	private function draw_format_bits( int $mask ): void {
		// Level M field = 0b00; combine with the 3-bit mask, then BCH(15,5) + XOR 0x5412.
		$data = $mask; // (0 << 3) | mask.
		$rem  = $data;
		for ( $i = 0; $i < 10; $i++ ) {
			$rem = ( $rem << 1 ) ^ ( ( $rem >> 9 ) * 0x537 );
		}
		$bits = ( ( $data << 10 ) | $rem ) ^ 0x5412;

		// First copy (around the top-left finder).
		for ( $i = 0; $i <= 5; $i++ ) {
			$this->set_function( 8, $i, $this->get_bit( $bits, $i ) );
		}
		$this->set_function( 8, 7, $this->get_bit( $bits, 6 ) );
		$this->set_function( 8, 8, $this->get_bit( $bits, 7 ) );
		$this->set_function( 7, 8, $this->get_bit( $bits, 8 ) );
		for ( $i = 9; $i < 15; $i++ ) {
			$this->set_function( 14 - $i, 8, $this->get_bit( $bits, $i ) );
		}

		// Second copy (split across the other two finders).
		for ( $i = 0; $i < 8; $i++ ) {
			$this->set_function( $this->size - 1 - $i, 8, $this->get_bit( $bits, $i ) );
		}
		for ( $i = 8; $i < 15; $i++ ) {
			$this->set_function( 8, $this->size - 15 + $i, $this->get_bit( $bits, $i ) );
		}
		$this->set_function( 8, $this->size - 8, true ); // Always-dark module.
	}

	/** Draw the 18-bit version information (v7+ only), placed in two blocks. */
	private function draw_version_bits(): void {
		if ( $this->version < 7 ) {
			return;
		}
		$rem = $this->version;
		for ( $i = 0; $i < 12; $i++ ) {
			$rem = ( $rem << 1 ) ^ ( ( $rem >> 11 ) * 0x1F25 );
		}
		$bits = ( $this->version << 12 ) | $rem;

		for ( $i = 0; $i < 18; $i++ ) {
			$bit = $this->get_bit( $bits, $i );
			$a   = $this->size - 11 + ( $i % 3 );
			$b   = intdiv( $i, 3 );
			$this->set_function( $a, $b, $bit );
			$this->set_function( $b, $a, $bit );
		}
	}

	/** Place the data+ECC codeword bit-stream in the upward/downward zig-zag. */
	private function draw_codewords( array $codewords ): void {
		// Flatten codewords to a bit string + per-version remainder bits.
		$bits = '';
		foreach ( $codewords as $cw ) {
			$bits .= str_pad( decbin( $cw ), 8, '0', STR_PAD_LEFT );
		}
		$remainder = [
			1 => 0,
			2 => 7,
			3 => 7,
			4 => 7,
			5 => 7,
			6 => 7,
			7 => 0,
			8 => 0,
			9 => 0,
		];
		$bits     .= str_repeat( '0', $remainder[ $this->version ] );

		$len = strlen( $bits );
		$i   = 0;
		for ( $right = $this->size - 1; $right >= 1; $right -= 2 ) {
			if ( 6 === $right ) {
				$right = 5; // Skip the vertical timing column.
			}
			for ( $vert = 0; $vert < $this->size; $vert++ ) {
				for ( $j = 0; $j < 2; $j++ ) {
					$x      = $right - $j;
					$upward = 0 === ( ( $right + 1 ) & 2 );
					$y      = $upward ? $this->size - 1 - $vert : $vert;
					if ( ! $this->is_function[ $y ][ $x ] && $i < $len ) {
						$this->modules[ $y ][ $x ] = '1' === $bits[ $i ] ? 1 : 0;
						++$i;
					}
				}
			}
		}
	}

	/*
	---------------------------------------------------------------------
	 * Masking + penalty
	 * ------------------------------------------------------------------- */

	private function apply_best_mask(): void {
		$best_mask    = 0;
		$best_penalty = PHP_INT_MAX;

		for ( $mask = 0; $mask < 8; $mask++ ) {
			$this->apply_mask( $mask );
			$this->draw_format_bits( $mask );
			$penalty = $this->penalty_score();
			if ( $penalty < $best_penalty ) {
				$best_penalty = $penalty;
				$best_mask    = $mask;
			}
			$this->apply_mask( $mask ); // XOR again to revert (mask is its own inverse).
		}

		$this->apply_mask( $best_mask );
		$this->draw_format_bits( $best_mask );
	}

	private function apply_mask( int $mask ): void {
		for ( $y = 0; $y < $this->size; $y++ ) {
			for ( $x = 0; $x < $this->size; $x++ ) {
				if ( $this->is_function[ $y ][ $x ] ) {
					continue;
				}
				switch ( $mask ) {
					case 0:
						$invert = 0 === ( $x + $y ) % 2;
						break;
					case 1:
						$invert = 0 === $y % 2;
						break;
					case 2:
						$invert = 0 === $x % 3;
						break;
					case 3:
						$invert = 0 === ( $x + $y ) % 3;
						break;
					case 4:
						$invert = 0 === ( intdiv( $x, 3 ) + intdiv( $y, 2 ) ) % 2;
						break;
					case 5:
						$invert = 0 === ( ( $x * $y ) % 2 ) + ( ( $x * $y ) % 3 );
						break;
					case 6:
						$invert = 0 === ( ( ( $x * $y ) % 2 ) + ( ( $x * $y ) % 3 ) ) % 2;
						break;
					default:
						$invert = 0 === ( ( ( $x + $y ) % 2 ) + ( ( $x * $y ) % 3 ) ) % 2;
						break;
				}
				if ( $invert ) {
					$this->modules[ $y ][ $x ] ^= 1;
				}
			}
		}
	}

	/** ISO/IEC 18004 penalty score (rules N1–N4) of the current matrix. */
	private function penalty_score(): int {
		$size    = $this->size;
		$penalty = 0;

		// N1: runs of 5+ same-colour modules in each row and column.
		for ( $y = 0; $y < $size; $y++ ) {
			$run_colour = -1;
			$run_len    = 0;
			for ( $x = 0; $x < $size; $x++ ) {
				if ( $this->modules[ $y ][ $x ] === $run_colour ) {
					++$run_len;
					if ( 5 === $run_len ) {
						$penalty += 3;
					} elseif ( $run_len > 5 ) {
						++$penalty;
					}
				} else {
					$run_colour = $this->modules[ $y ][ $x ];
					$run_len    = 1;
				}
			}
		}
		for ( $x = 0; $x < $size; $x++ ) {
			$run_colour = -1;
			$run_len    = 0;
			for ( $y = 0; $y < $size; $y++ ) {
				if ( $this->modules[ $y ][ $x ] === $run_colour ) {
					++$run_len;
					if ( 5 === $run_len ) {
						$penalty += 3;
					} elseif ( $run_len > 5 ) {
						++$penalty;
					}
				} else {
					$run_colour = $this->modules[ $y ][ $x ];
					$run_len    = 1;
				}
			}
		}

		// N2: 2x2 blocks of the same colour.
		for ( $y = 0; $y < $size - 1; $y++ ) {
			for ( $x = 0; $x < $size - 1; $x++ ) {
				$c = $this->modules[ $y ][ $x ];
				if ( $c === $this->modules[ $y ][ $x + 1 ]
					&& $c === $this->modules[ $y + 1 ][ $x ]
					&& $c === $this->modules[ $y + 1 ][ $x + 1 ] ) {
					$penalty += 3;
				}
			}
		}

		// N3: finder-like 1:1:3:1:1 patterns (with a 4-module light run) in rows and columns.
		$pattern_a = [ 1, 0, 1, 1, 1, 0, 1, 0, 0, 0, 0 ];
		$pattern_b = [ 0, 0, 0, 0, 1, 0, 1, 1, 1, 0, 1 ];
		for ( $y = 0; $y < $size; $y++ ) {
			for ( $x = 0; $x <= $size - 11; $x++ ) {
				if ( $this->row_matches( $y, $x, $pattern_a ) || $this->row_matches( $y, $x, $pattern_b ) ) {
					$penalty += 40;
				}
			}
		}
		for ( $x = 0; $x < $size; $x++ ) {
			for ( $y = 0; $y <= $size - 11; $y++ ) {
				if ( $this->col_matches( $x, $y, $pattern_a ) || $this->col_matches( $x, $y, $pattern_b ) ) {
					$penalty += 40;
				}
			}
		}

		// N4: deviation of the dark-module ratio from 50%.
		$dark = 0;
		for ( $y = 0; $y < $size; $y++ ) {
			$dark += array_sum( $this->modules[ $y ] );
		}
		$total    = $size * $size;
		$percent  = ( $dark * 100 ) / $total;
		$dev      = (int) ( abs( $percent - 50 ) / 5 );
		$penalty += $dev * 10;

		return $penalty;
	}

	/**
	 * @param int            $y       Row index.
	 * @param int            $x       Starting column.
	 * @param array<int,int> $pattern Expected module run.
	 */
	private function row_matches( int $y, int $x, array $pattern ): bool {
		foreach ( $pattern as $k => $v ) {
			if ( $this->modules[ $y ][ $x + $k ] !== $v ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param int            $x       Column index.
	 * @param int            $y       Starting row.
	 * @param array<int,int> $pattern Expected module run.
	 */
	private function col_matches( int $x, int $y, array $pattern ): bool {
		foreach ( $pattern as $k => $v ) {
			if ( $this->modules[ $y + $k ][ $x ] !== $v ) {
				return false;
			}
		}
		return true;
	}

	private function get_bit( int $value, int $index ): bool {
		return 0 !== ( ( $value >> $index ) & 1 );
	}
}
