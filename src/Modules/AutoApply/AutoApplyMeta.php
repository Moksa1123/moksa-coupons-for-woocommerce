<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\AutoApply;

use Moksafocou\Coupon\Meta\Keys;
use Moksafocou\Support\CouponIndex;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for the auto-apply flag. The per-coupon meta (_moksafocou_auto_apply) is
 * canonical; which coupons carry it is answered by the coupon index (Support\CouponIndex, a private
 * taxonomy kept current from the meta hooks), so the cart loop never meta-queries every coupon.
 */
final class AutoApplyMeta {

	public static function is_enabled( int $coupon_id ): bool {
		return 'yes' === get_post_meta( $coupon_id, Keys::AUTO_APPLY, true );
	}

	/**
	 * Per-request memo of ids() (the cart asks several times per request).
	 *
	 * @var array<int,int>|null
	 */
	private static ?array $ids = null;

	/** @return array<int,int> Published auto-apply coupon IDs. */
	public static function ids(): array {
		if ( null === self::$ids ) {
			self::$ids = array();
			foreach ( CouponIndex::ids( 'auto_apply:1' ) as $id ) {
				if ( 'publish' === get_post_status( $id ) ) {
					self::$ids[] = $id;
				}
			}
		}
		return self::$ids;
	}

	/**
	 * Set / clear the flag. An explicit false (not just absence) is required to un-toggle —
	 * callers always pass a bool. The index follows through the meta hooks.
	 */
	public static function write( int $coupon_id, bool $enable ): void {
		if ( $enable ) {
			update_post_meta( $coupon_id, Keys::AUTO_APPLY, 'yes' );
		} else {
			delete_post_meta( $coupon_id, Keys::AUTO_APPLY );
		}
		self::$ids = null;
		CouponIndex::flush();
	}

	/* ---------------- eligibility (pure) ---------------- */

	/**
	 * Pure eligibility test: a coupon must be published and free of usage limits and
	 * email restrictions to be auto-applied (those interact badly with auto-apply).
	 *
	 * @param string            $status               Coupon post status.
	 * @param int               $usage_limit          Overall usage limit.
	 * @param int               $usage_limit_per_user Per-user usage limit.
	 * @param array<int,string> $emails               Email restrictions.
	 */
	public static function eligible_props( string $status, int $usage_limit, int $usage_limit_per_user, array $emails ): bool {
		return 'publish' === $status
			&& $usage_limit <= 0
			&& $usage_limit_per_user <= 0
			&& array() === array_filter( $emails, static fn( $e ): bool => '' !== trim( (string) $e ) );
	}

	public static function eligible( \WC_Coupon $coupon ): bool {
		return self::eligible_props(
			get_post_status( $coupon->get_id() ) ?: '',
			(int) $coupon->get_usage_limit(),
			(int) $coupon->get_usage_limit_per_user(),
			(array) $coupon->get_email_restrictions()
		);
	}

	/**
	 * Why a coupon ticked for auto-apply will not be auto-applied, one sentence per reason.
	 *
	 * The rule is deliberate — auto-apply runs when the cart opens, before the shop knows who the
	 * customer is, so it cannot check a per-customer limit or an email list. What was not
	 * deliberate is that ticking the box on such a coupon saved without complaint and the coupon
	 * then simply never appeared; em925's 新客 NT$100 (one per customer) is the case that surfaced
	 * it. The publish status is left out: a draft not applying is self-evident while editing.
	 *
	 * Mirrors eligible_props(), so the explanation can never disagree with the decision.
	 *
	 * @return array<int,string> Empty when nothing stands in the way.
	 */
	public static function blockers( \WC_Coupon $coupon ): array {
		$out = array();

		if ( (int) $coupon->get_usage_limit_per_user() > 0 ) {
			$out[] = __( 'It has a "usage limit per user". Auto-apply happens when the cart opens, before the shop knows who the customer is, so it cannot check whether this customer has already used it.', 'moksa-coupons-for-woocommerce' );
		}
		if ( array() !== array_filter( (array) $coupon->get_email_restrictions(), static fn( $e ): bool => '' !== trim( (string) $e ) ) ) {
			$out[] = __( 'It is restricted to certain email addresses. The customer\'s email is not known until checkout, long after auto-apply has run.', 'moksa-coupons-for-woocommerce' );
		}
		if ( (int) $coupon->get_usage_limit() > 0 ) {
			// Not "it would use up the limit": WooCommerce counts usage when an order is placed, not
			// when a coupon sits in a cart. The real cost is what happens once the limit is reached.
			$out[] = __( 'It has an overall "usage limit". Once the limit is reached the coupon stops being valid, and WooCommerce removes an invalid coupon from the cart with an error message — which every shopper would see the moment their cart opened.', 'moksa-coupons-for-woocommerce' );
		}

		return $out;
	}

	/* ---------------- stale auto-applied coupons (pure) ---------------- */

	/**
	 * Which of the cart's coupons to take back out: the ones THIS plugin put there that can no
	 * longer apply. A code the customer typed is never in $ours, so it is never touched — WooCommerce
	 * keeps its own say over those, notice and all.
	 *
	 * @param array<int,string>      $applied  Codes currently in the cart.
	 * @param array<int,string>      $ours     Codes this plugin auto-applied for this session.
	 * @param callable(string): bool $is_valid Whether a code can still apply to this cart.
	 * @return array<int,string> Codes to remove, in cart order.
	 */
	public static function stale( array $applied, array $ours, callable $is_valid ): array {
		$out = array();
		foreach ( $applied as $code ) {
			$code = (string) $code;
			if ( in_array( $code, $ours, true ) && ! $is_valid( $code ) ) {
				$out[] = $code;
			}
		}
		return $out;
	}
}
