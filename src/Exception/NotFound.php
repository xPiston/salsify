<?php

declare(strict_types=1);

namespace Piston\Salsify\Exception;

/**
 * 404.
 *
 * On a record, this is ambiguous in a way worth knowing: Salsify identifies a
 * record both by `salsify:id` - which *follows* the property holding the ID
 * role and therefore changes when that property changes - and by
 * `salsify:system_id`, which never changes. A 404 on an id that worked last
 * week usually means somebody edited the ID property, not that the record was
 * deleted.
 */
final class NotFound extends ApiError {}
