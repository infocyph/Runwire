<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Psalm;

use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use Psalm\CodeLocation;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\DataFlow\DataFlowNode;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\Type\TaintKind;

/**
 * Model the one authorized filesystem-path return in the local executor.
 *
 * An owning ProcessExecutor signs each private IPC message with an HMAC
 * verified before the worker decodes the request. After guardFilePath()
 * validates the path and configured root, only the FILE and SSRF taints
 * cease to apply. Every other taint kind, raw input and path outside that
 * exact worker call remain visible in the same Psalm data-flow graph.
 */
final class AuthorizedWorkerPathTaintModel implements AfterExpressionAnalysisInterface
{
    #[\Override]
    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        $expr = $event->getExpr();
        if (!$expr instanceof FuncCall || !$expr->name instanceof Name) {
            return null;
        }

        $name = strtolower($expr->name->toString());
        if ($name !== 'guardfilepath' && $name !== 'infocyph\\runwire\\network\\internal\\guardfilepath') {
            return null;
        }

        $source = $event->getStatementsSource();
        if (!$source instanceof StatementsAnalyzer
            || !str_ends_with(str_replace('\\', '/', $source->getFilePath()), '/tools/executor-worker.php')) {
            return null;
        }

        $graph = $source->getTaintFlowGraphWithSuppressed();
        $provider = $source->getNodeTypeProvider();
        $type = $provider->getType($expr);
        if ($graph === null || $type === null || $type->parent_nodes === []) {
            return null;
        }

        $validated = DataFlowNode::getForAssignment(
            'authenticated-executor-file-path',
            new CodeLocation($source, $expr),
        );
        $graph->addNode($validated);
        foreach ($type->parent_nodes as $parent) {
            $graph->addPath(
                $parent,
                $validated,
                'validated-worker-file-path',
                0,
                TaintKind::INPUT_FILE | TaintKind::INPUT_SSRF,
            );
        }

        $provider->setType($expr, $type->setParentNodes([$validated->id => $validated]));

        return null;
    }
}
