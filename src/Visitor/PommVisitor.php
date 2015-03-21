<?php

namespace RulerZ\Visitor;

use Hoa\Ruler\Model as AST;
use Hoa\Visitor\Element as VisitorElement;
use Hoa\Visitor\Visit as Visitor;

use RulerZ\Exception\OperatorNotFoundException;

class PommVisitor implements Visitor
{
    use Polyfill\CustomOperators;

    /**
     * Allow star operator.
     *
     * @var bool
     */
    private $allowStarOperator = true;

    /**
     * Constructor.
     *
     * @param bool $allowStarOperator Whether to allow the star operator or not (ie: implicit support of unknown operators).
     */
    public function __construct($allowStarOperator = true)
    {
        $this->allowStarOperator = (bool) $allowStarOperator;

        $this->setOperator('and',  function ($a, $b) { return sprintf('%s AND %s', $a, $b); });
        $this->setOperator('or',   function ($a, $b) { return sprintf('%s OR %s', $a, $b); });
        $this->setOperator('xor',  function ($a, $b) { return sprintf('%s # %s', $a, $b); });
        $this->setOperator('not',  function ($a)     { return sprintf('NOT (%s)', $a); });
        $this->setOperator('=',    function ($a, $b) { return sprintf('%s = %s', $a, $b); });
        $this->setOperator('!=',   function ($a, $b) { return sprintf('%s != %s', $a, $b); });
        $this->setOperator('>',    function ($a, $b) { return sprintf('%s > %s', $a,  $b); });
        $this->setOperator('>=',   function ($a, $b) { return sprintf('%s >= %s', $a,  $b); });
        $this->setOperator('<',    function ($a, $b) { return sprintf('%s < %s', $a,  $b); });
        $this->setOperator('<=',   function ($a, $b) { return sprintf('%s <= %s', $a,  $b); });
        $this->setOperator('in',   function ($a, $b) { return sprintf('%s IN %s', $a, $b[0] === '(' ? $b : '('.$b.')'); });
        $this->setOperator('like', function ($a, $b) { return sprintf('%s LIKE %s', $a, $b); });
    }

    /**
     * Visit an element.
     *
     * @param \VisitorElement $element Element to visit.
     * @param mixed           &$handle Handle (reference).
     * @param mixed           $eldnah  Handle (not reference).
     *
     * @return string The DQL code for the given rule.
     */
    public function visit(VisitorElement $element, &$handle = null, $eldnah = null)
    {
        if ($element instanceof AST\Model) {
            return $this->visitModel($element, $handle, $eldnah);
        }

        if ($element instanceof AST\Operator) {
            return $this->visitOperator($element, $handle, $eldnah);
        }

        if ($element instanceof AST\Bag\Scalar) {
            return $this->visitScalar($element, $handle, $eldnah);
        }

        if ($element instanceof AST\Bag\RulerArray) {
            return $this->visitArray($element, $handle, $eldnah);
        }

        if ($element instanceof AST\Bag\Context) {
            return $this->visitContext($element, $handle, $eldnah);
        }

        throw new \LogicException(sprintf('Element of type "%s" not handled', get_class($element)));
    }

    /**
     * Visit a model
     *
     * @param AST\Model $element Element to visit.
     * @param mixed     &$handle Handle (reference).
     * @param mixed     $eldnah  Handle (not reference).
     *
     * @return string
     */
    public function visitModel(AST\Model $element, &$handle = null, $eldnah = null)
    {
        return $element->getExpression()->accept($this, $handle, $eldnah);
    }

    /**
     * Visit a context (ie: a column access or a parameter)
     *
     * @param AST\Bag\Context $element Element to visit.
     * @param mixed           &$handle Handle (reference).
     * @param mixed           $eldnah  Handle (not reference).
     *
     * @return string
     */
    private function visitContext(AST\Bag\Context $element, &$handle = null, $eldnah = null)
    {
        $name = $element->getId();

        // parameter
        if ($name[0] === ':') {
            return '$*';
        }

        return $element->getId();
    }

    /**
     * Visit a scalar
     *
     * @param AST\Bag\Scalar $element Element to visit.
     * @param mixed          &$handle Handle (reference).
     * @param mixed          $eldnah  Handle (not reference).
     *
     * @return string
     */
    private function visitScalar(AST\Bag\Scalar $element, &$handle = null, $eldnah = null)
    {
        $value = $element->getValue();

        return is_numeric($value) ? $value : sprintf("'%s'", $value);
    }

    /**
     * Visit an array
     *
     * @param AST\Bag\RulerArray $element Element to visit.
     * @param mixed              &$handle Handle (reference).
     * @param mixed              $eldnah  Handle (not reference).
     *
     * @return string
     */
    private function visitArray(AST\Bag\RulerArray $element, &$handle = null, $eldnah = null)
    {
        $out = array_map(function ($item) use ($handle, $eldnah) {
            return $item->accept($this, $handle, $eldnah);
        }, $element->getArray());

        return sprintf('(%s)', implode(', ', $out));
    }

    /**
     * Visit an operator
     *
     * @param AST\Operator $element Element to visit.
     * @param mixed        &$handle Handle (reference).
     * @param mixed        $eldnah  Handle (not reference).
     *
     * @return string
     */
    private function visitOperator(AST\Operator $element, &$handle = null, $eldnah = null)
    {
        try {
            $xcallable = $this->getOperator($element->getName());
        } catch (OperatorNotFoundException $e) {
            if (!$this->allowStarOperator) {
                throw $e;
            }

            $xcallable = $this->getStarOperator($element);
        }

        $arguments = array_map(function ($argument) use ($handle, $eldnah) {
            return $argument->accept($this, $handle, $eldnah);
        }, $element->getArguments());

        return $xcallable->distributeArguments($arguments);
    }

    /**
     * Return a "*" or "catch all" operator.
     *
     * @param Visitor\Element $element The node representing the operator.
     *
     * @return \Hoa\Core\Consistency\Xcallable
     */
    private function getStarOperator(AST\Operator $element)
    {
        return xcallable(function () use ($element) {
            return sprintf('%s(%s)', $element->getName(), implode(', ', func_get_args()));
        });
    }
}
