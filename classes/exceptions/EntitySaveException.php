<?php

/**
 * Exception thrown when an entity save operation fails.
 *
 * This exception is storage-agnostic - it wraps database errors but doesn't expose
 * database-specific details in its interface. This allows future NoSQL implementations
 * to use the same exception type.
 *
 * Entity write operations throw this exception on persistence errors.
 * Callers should map this exception to domain/API/form-level messages.
 */
class EntitySaveException extends Exception
{
	/**
	 * @param string $message Error message describing what went wrong
	 * @param string|null $entityClass The fully qualified entity class name (e.g., EntityUser::class)
	 * @param array<string, mixed>|null $data The data that failed to save
	 * @param Throwable|null $previous The underlying exception (e.g., PDOException)
	 */
	public function __construct(
		string $message,
		public readonly ?string $entityClass = null,
		public readonly ?array $data = null,
		?Throwable $previous = null
	) {
		parent::__construct($message, 0, $previous);
	}
}
