<?php

declare( strict_types=1 );

namespace Moksafocou\Support\Qr;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a QrEncoder module matrix to a standalone SVG string.
 *
 * The SVG markup is assembled exclusively from plugin-computed integers and the
 * two hard-coded colour literals — the coupon code / share URL is NEVER written
 * into the markup (it lives only in the dark-module geometry), so there is no
 * payload-driven XSS surface and the output cannot be escaped with esc_html /
 * wp_kses without corrupting it. A 4-module quiet zone sits INSIDE the viewBox
 * (scanners need it), and dark modules are emitted as one crisp-edged <path>.
 */
final class QrSvg {

	/** Quiet-zone width in modules (QR spec minimum is 4). */
	private const QUIET = 4;

	private const DARK  = '#000000';
	private const LIGHT = '#ffffff';

	/**
	 * @param string $data     Payload to encode (final URL byte form).
	 * @param int    $px_scale Pixels per module for the width/height attributes.
	 * @return string|\WP_Error SVG markup, or the encoder's error.
	 */
	public static function render( string $data, int $px_scale = 8 ) {
		$matrix = QrEncoder::encode( $data );
		if ( $matrix instanceof \WP_Error ) {
			return $matrix;
		}
		return self::from_matrix( $matrix, $px_scale );
	}

	/**
	 * @param array<int,array<int,int>> $matrix
	 */
	public static function from_matrix( array $matrix, int $px_scale = 8 ): string {
		$count    = count( $matrix );
		$dim      = $count + ( 2 * self::QUIET );
		$px_scale = max( 1, min( 40, $px_scale ) );
		$px       = $dim * $px_scale;

		$path = '';
		foreach ( $matrix as $y => $row ) {
			foreach ( $row as $x => $module ) {
				if ( 1 === $module ) {
					$mx    = $x + self::QUIET;
					$my    = $y + self::QUIET;
					$path .= 'M' . $mx . ',' . $my . 'h1v1h-1z';
				}
			}
		}

		return sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%1$d" viewBox="0 0 %2$d %2$d" shape-rendering="crispEdges" role="img" aria-label="QR code">'
			. '<rect width="%2$d" height="%2$d" fill="%3$s"/>'
			. '<path d="%4$s" fill="%5$s"/>'
			. '</svg>',
			$px,
			$dim,
			self::LIGHT,
			$path,
			self::DARK
		);
	}
}
