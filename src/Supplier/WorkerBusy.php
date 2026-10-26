<?php
declare(strict_types=1);
namespace SimpleStore\Supplier;

/** Another worker owns the queue; callers may retry without changing its work. */
final class WorkerBusy extends \InvalidArgumentException
{
}
