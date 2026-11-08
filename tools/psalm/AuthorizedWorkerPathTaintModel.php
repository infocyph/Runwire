<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Psalm;

use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use Psalm\Plugin\EventHandler\Event\AddRemoveTaintsEvent;
use Psalm\Plugin\EventHandler\RemoveTaintsInterface;
use Psalm\Type\TaintKind;

/**
 * Model the narrow, authenticated executor-filesystem capability boundary.
 *
 * Only the return from guardFilePath() in the private subprocess is trusted
 * for filesystem target and URL-wrapper taints. A caller's raw argument,
 * failed guard, or any other function remains fully tainted.
 *
 * The worker verifies a per-executor HMAC before decoding a request, and
 * guardFilePath validates the local path, wrappers, optional canonical root,
 * symlink traversal and permissions before returning its authorized result.
 * Its unscoped mode is an explicit full-filesystem capability granted by the
 * owning ProcessExecutor, never an independently accessible HTTP endpoint.
 */
final class AuthorizedWorkerPathTaintModel implements RemoveTaintsInterface
{
    #[\Override]
    public static function removeTaints(AddRemoveTaintsEvent $event): int
    {
        $expr = $event->getExpr();
        if (!$expr instanceof FuncCall || !$expr->name instanceof Name) {
            return 0;
        }

        $name = strtolower($expr->name->toString());
        if ($name !== 'guardfilepath' && $name !== 'infocyph\\runwire\\network\\internal\\guardfilepath') {
            return 0;
        }

        $source = str_replace('\\', '/', $event->getStatementsSource()->getFilePath());
        if (!str_ends_with($source, '/tools/executor-worker.php')) {
            return 0;
        }

        return TaintKind::INPUT_FILE | TaintKind::INPUT_SSRF;
    }
}
