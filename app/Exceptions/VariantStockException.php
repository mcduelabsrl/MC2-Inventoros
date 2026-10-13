<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * A per-warehouse variant stock operation broke a business rule (units not
 * free, not yet consolidated, unknown warehouse...). Shown to the user like
 * any other BusinessRuleException.
 */
final class VariantStockException extends BusinessRuleException {}
