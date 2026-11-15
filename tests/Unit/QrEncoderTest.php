<?php

declare( strict_types=1 );

namespace Moksafocou\Tests\Unit;

use Moksafocou\Support\Qr\QrEncoder;
use Moksafocou\Support\Qr\QrSvg;
use PHPUnit\Framework\TestCase;

/**
 * Structural guards for the QR encoder. End-to-end scannability is proven
 * separately by a jsQR round-trip in the browser; these tests just stop the matrix
 * geometry / error handling from silently regressing.
 */
final class QrEncoderTest extends TestCase {

	public function test_empty_input_errors(): void {
		$this->assertInstanceOf( \WP_Error::class, QrEncoder::encode( '' ) );
	}

	public function test_oversized_input_errors(): void {
		$this->assertInstanceOf( \WP_Error::class, QrEncoder::encode( str_repeat( 'a', QrEncoder::MAX_BYTES + 1 ) ) );
	}

	public function test_matrix_is_square_and_binary(): void {
		$matrix = QrEncoder::encode( 'https://example.test/coupon/save10' );
		$this->assertIsArray( $matrix );
		$size = count( $matrix );
		// Must be a real QR side length 4*version+17 for some version 1–9 (21..69).
		$this->assertContains( $size, array( 21, 25, 29, 33, 37, 41, 45, 49, 53 ) );
		foreach ( $matrix as $row ) {
			$this->assertCount( $size, $row );
			foreach ( $row as $cell ) {
				$this->assertContains( $cell, array( 0, 1 ), 'modules must be 0 or 1' );
			}
		}
	}

	public function test_version_grows_with_payload(): void {
		$small = QrEncoder::encode( 'abc' );           // v1 → 21.
		$big   = QrEncoder::encode( str_repeat( 'x', 120 ) ); // needs a higher version.
		$this->assertSame( 21, count( $small ) );
		$this->assertGreaterThan( 21, count( $big ) );
	}

	public function test_top_left_finder_corner_is_dark(): void {
		$matrix = QrEncoder::encode( 'abc' );
		// The finder pattern's outer ring fills the symbol corner.
		$this->assertSame( 1, $matrix[0][0] );
		$this->assertSame( 1, $matrix[6][0] );
		$this->assertSame( 1, $matrix[0][6] );
	}

	public function test_svg_render_shape(): void {
		$svg = QrSvg::render( 'https://example.test/coupon/save10', 8 );
		$this->assertIsString( $svg );
		$this->assertStringStartsWith( '<svg', $svg );
		$this->assertStringContainsString( 'shape-rendering="crispEdges"', $svg );
		// The payload must NEVER appear as text in the markup (it lives in the matrix).
		$this->assertStringNotContainsString( 'save10', $svg );
		$this->assertStringNotContainsString( 'example.test', $svg );
	}

	public function test_svg_has_no_viewbox_and_only_lowercase_attributes(): void {
		// wp_kses() lower-cases attribute names; a standalone SVG ignores "viewbox". So the markup must
		// not depend on any mixed-case attribute (it is escaped with wp_kses() on output).
		$svg = (string) QrSvg::render( 'https://example.test/coupon/save10', 8 );
		$this->assertStringNotContainsStringIgnoringCase( 'viewbox', $svg );
		preg_match_all( '/\s([A-Za-z:-]+)="/', $svg, $m );
		foreach ( $m[1] as $attr ) {
			$this->assertSame( strtolower( $attr ), $attr, "attribute {$attr} is not lower-case" );
		}
	}

	public function test_svg_markup_matches_allowed_html(): void {
		$svg     = (string) QrSvg::render( 'https://example.test/coupon/save10', 8 );
		$allowed = QrSvg::allowed_html();
		preg_match_all( '/<([a-z]+)([^>]*)>/', $svg, $tags, PREG_SET_ORDER );
		foreach ( $tags as $tag ) {
			$this->assertArrayHasKey( $tag[1], $allowed, "element {$tag[1]} not allowed" );
			preg_match_all( '/\s([a-z:-]+)="/', $tag[2], $attrs );
			foreach ( $attrs[1] as $attr ) {
				$this->assertArrayHasKey( $attr, $allowed[ $tag[1] ], "{$tag[1]}[{$attr}] not allowed" );
			}
		}
	}

	public function test_svg_geometry_is_modules_times_scale(): void {
		// 2x2 matrix, dark at (0,0) and (1,1); quiet zone 4; scale 5 → modules at 20 and 25 px.
		$svg = QrSvg::from_matrix( array( array( 1, 0 ), array( 0, 1 ) ), 5 );
		$this->assertStringContainsString( 'width="50" height="50"', $svg, '(2 + 8) modules x 5 px' );
		$this->assertStringContainsString( 'd="M20,20h5v5h-5zM25,25h5v5h-5z"', $svg );
		$this->assertStringContainsString( '<rect width="50" height="50" fill="#ffffff" />', $svg, 'the " />" form wp_kses() writes' );
	}
}
