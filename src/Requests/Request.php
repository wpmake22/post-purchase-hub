<?php
/**
 * Request model.
 *
 * @package PostPurchaseHub
 */

declare( strict_types = 1 );

namespace PostPurchaseHub\Requests;

/**
 * One customer-initiated request, as stored in `pph_requests`.
 *
 * Immutable and storage-free: it holds no database handle and performs no
 * queries, so it can be constructed in a test, handed to a template, or built
 * from a row without dragging persistence along with it.
 *
 * `amount` stays a string. The column is DECIMAL(19,4) and money that has been
 * through a float is money you cannot reconcile.
 *
 * @since 0.2.0
 */
final class Request {

	const TYPE_CANCELLATION = 'cancellation';
	const TYPE_RETURN       = 'return';
	const TYPE_HELP         = 'help';

	const STATUS_PENDING   = 'pending';
	const STATUS_APPROVED  = 'approved';
	const STATUS_DECLINED  = 'declined';
	const STATUS_WITHDRAWN = 'withdrawn';
	const STATUS_COMPLETED = 'completed';

	const SOURCE_ACCOUNT      = 'account';
	const SOURCE_GUEST_TOKEN  = 'guest_token';
	const SOURCE_GUEST_LOOKUP = 'guest_lookup';
	const SOURCE_ADMIN        = 'admin';

	/**
	 * Cap on the stored customer note, per docs/SPEC.md Phase 7.
	 *
	 * @var int
	 */
	const NOTE_MAX_LENGTH = 2000;

	/**
	 * Constructor.
	 *
	 * @since 0.2.0
	 *
	 * @param int         $id                  Row id, 0 for an unsaved request.
	 * @param int         $order_id            Order this request belongs to.
	 * @param int         $customer_id         Customer user id, 0 for a guest.
	 * @param string      $customer_email_hash SHA-256 of the normalised billing email.
	 * @param string      $type                One of the values from types().
	 * @param string      $status              One of the values from statuses().
	 * @param string|null $reason_code         Whitelisted reason code.
	 * @param string|null $customer_note       Sanitised customer note.
	 * @param string|null $admin_note          Internal merchant note.
	 * @param string|null $amount              Decimal string, informational only in 1.0.
	 * @param string|null $currency            ISO 4217 code.
	 * @param string      $source              One of the values from sources().
	 * @param string      $created_at          Creation time, UTC `Y-m-d H:i:s`.
	 * @param string      $updated_at          Last update time, UTC `Y-m-d H:i:s`.
	 * @param string|null $resolved_at         Resolution time, UTC `Y-m-d H:i:s`.
	 * @param int|null    $resolved_by         User id that resolved it.
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $order_id,
		public readonly int $customer_id,
		public readonly string $customer_email_hash,
		public readonly string $type,
		public readonly string $status,
		public readonly ?string $reason_code,
		public readonly ?string $customer_note,
		public readonly ?string $admin_note,
		public readonly ?string $amount,
		public readonly ?string $currency,
		public readonly string $source,
		public readonly string $created_at,
		public readonly string $updated_at,
		public readonly ?string $resolved_at,
		public readonly ?int $resolved_by
	) {}

	/**
	 * Builds a request from a database row.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $row Row as returned by the repository.
	 * @return self
	 */
	public static function from_row( array $row ): self {
		return new self(
			(int) ( $row['id'] ?? 0 ),
			(int) ( $row['order_id'] ?? 0 ),
			(int) ( $row['customer_id'] ?? 0 ),
			(string) ( $row['customer_email_hash'] ?? '' ),
			(string) ( $row['type'] ?? '' ),
			(string) ( $row['status'] ?? '' ),
			self::nullable_string( $row['reason_code'] ?? null ),
			self::nullable_string( $row['customer_note'] ?? null ),
			self::nullable_string( $row['admin_note'] ?? null ),
			self::nullable_string( $row['amount'] ?? null ),
			self::nullable_string( $row['currency'] ?? null ),
			(string) ( $row['source'] ?? '' ),
			(string) ( $row['created_at'] ?? '' ),
			(string) ( $row['updated_at'] ?? '' ),
			self::nullable_string( $row['resolved_at'] ?? null ),
			isset( $row['resolved_by'] ) ? (int) $row['resolved_by'] : null
		);
	}

	/**
	 * Returns the request as a column-keyed array.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'                  => $this->id,
			'order_id'            => $this->order_id,
			'customer_id'         => $this->customer_id,
			'customer_email_hash' => $this->customer_email_hash,
			'type'                => $this->type,
			'status'              => $this->status,
			'reason_code'         => $this->reason_code,
			'customer_note'       => $this->customer_note,
			'admin_note'          => $this->admin_note,
			'amount'              => $this->amount,
			'currency'            => $this->currency,
			'source'              => $this->source,
			'created_at'          => $this->created_at,
			'updated_at'          => $this->updated_at,
			'resolved_at'         => $this->resolved_at,
			'resolved_by'         => $this->resolved_by,
		);
	}

	/**
	 * Whether the request is still awaiting a merchant decision.
	 *
	 * @since 0.2.0
	 *
	 * @return bool
	 */
	public function is_open(): bool {
		return self::STATUS_PENDING === $this->status;
	}

	/**
	 * Request types this install accepts.
	 *
	 * `return` is listed because the column and the queue are shared; the action
	 * that creates one ships in a later version. Extensions add their own types
	 * here rather than writing unrecognised values into the table.
	 *
	 * @since 0.2.0
	 *
	 * @return string[]
	 */
	public static function types(): array {
		$defaults = array( self::TYPE_CANCELLATION, self::TYPE_RETURN, self::TYPE_HELP );

		/**
		 * Filters the request types this install accepts.
		 *
		 * @since 0.2.0
		 *
		 * @param string[] $types Accepted type slugs.
		 */
		$types = apply_filters( 'pph_request_types', $defaults );

		return self::narrow( $types, $defaults );
	}

	/**
	 * Request statuses this install accepts.
	 *
	 * @since 0.2.0
	 *
	 * @return string[]
	 */
	public static function statuses(): array {
		$defaults = array(
			self::STATUS_PENDING,
			self::STATUS_APPROVED,
			self::STATUS_DECLINED,
			self::STATUS_WITHDRAWN,
			self::STATUS_COMPLETED,
		);

		/**
		 * Filters the request statuses this install accepts.
		 *
		 * @since 0.2.0
		 *
		 * @param string[] $statuses Accepted status slugs.
		 */
		$statuses = apply_filters( 'pph_request_statuses', $defaults );

		return self::narrow( $statuses, $defaults );
	}

	/**
	 * Origins a request can be created from, kept for abuse forensics.
	 *
	 * @since 0.2.0
	 *
	 * @return string[]
	 */
	public static function sources(): array {
		$defaults = array( self::SOURCE_ACCOUNT, self::SOURCE_GUEST_TOKEN, self::SOURCE_GUEST_LOOKUP, self::SOURCE_ADMIN );

		/**
		 * Filters the sources a request may be created from.
		 *
		 * @since 0.2.0
		 *
		 * @param string[] $sources Accepted source slugs.
		 */
		$sources = apply_filters( 'pph_request_sources', $defaults );

		return self::narrow( $sources, $defaults );
	}

	/**
	 * Reduces a filtered vocabulary to slugs the column can hold.
	 *
	 * A filter that returns junk must not widen what reaches the table, so the
	 * result is narrowed to non-empty strings inside the column length, and an
	 * empty result falls back rather than rejecting every write.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed    $slugs    Filtered value.
	 * @param string[] $defaults Defaults to fall back to.
	 * @return string[]
	 */
	private static function narrow( $slugs, array $defaults ): array {
		if ( ! is_array( $slugs ) ) {
			return $defaults;
		}

		$clean = array_filter(
			array_map(
				static function ( $slug ): string {
					return is_scalar( $slug ) ? substr( (string) $slug, 0, 20 ) : '';
				},
				$slugs
			)
		);

		$clean = array_values( array_unique( $clean ) );

		return $clean ? $clean : $defaults;
	}

	/**
	 * Casts a value to a string, preserving null.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private static function nullable_string( $value ): ?string {
		return null === $value ? null : (string) $value;
	}
}
