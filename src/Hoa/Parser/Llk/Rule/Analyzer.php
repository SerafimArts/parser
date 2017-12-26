<?php
/**
 * This file is part of Railt package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
declare(strict_types=1);

namespace Hoa\Compiler\Llk\Rule;

use Hoa\Compiler;
use Hoa\Compiler\Exception\Exception;
use Hoa\Iterator;

/**
 * Class \Hoa\Compiler\Llk\Rule\Analyzer.
 *
 * Analyze rules and transform them into atomic rules operations.
 *
 * @copyright  Copyright © 2007-2017 Hoa community
 * @license    New BSD License
 */
class Analyzer
{
    /**
     * PP lexemes.
     */
    protected static $_ppLexemes = [
        'default' => [
            'skip'         => '\s',
            'or'           => '\|',
            'zero_or_one'  => '\?',
            'one_or_more'  => '\+',
            'zero_or_more' => '\*',
            'n_to_m'       => '\{[0-9]+,[0-9]+\}',
            'zero_to_m'    => '\{,[0-9]+\}',
            'n_or_more'    => '\{[0-9]+,\}',
            'exactly_n'    => '\{[0-9]+\}',
            'skipped'      => '::[a-zA-Z_][a-zA-Z0-9_]*(\[\d+\])?::',
            'kept'         => '<[a-zA-Z_][a-zA-Z0-9_]*(\[\d+\])?>',
            'named'        => '[a-zA-Z_][a-zA-Z0-9_]*\(\)',
            'node'         => '#[a-zA-Z_][a-zA-Z0-9_]*(:[mM])?',
            'capturing_'   => '\(',
            '_capturing'   => '\)',
        ],
    ];

    /**
     * Lexer iterator.
     *
     * @var \Hoa\Iterator\Lookahead
     */
    protected $_lexer;

    /**
     * Tokens representing rules.
     *
     * @var array
     */
    protected $_tokens;

    /**
     * Rules.
     *
     * @var array
     */
    protected $_rules;
    /**
     * Parsed rules.
     *
     * @var array
     */
    protected $_parsedRules;
    /**
     * Counter to auto-name transitional rules.
     *
     * @var int
     */
    protected $_transitionalRuleCounter = 0;
    /**
     * Rule name being analyzed.
     *
     * @var string
     */
    private $_ruleName;

    /**
     * Constructor.
     *
     * @param   array $tokens Tokens.
     */
    public function __construct(array $tokens)
    {
        $this->_tokens = $tokens;
    }

    /**
     * Build the analyzer of the rules (does not analyze the rules).
     *
     * @param   array $rules Rule to be analyzed.
     * @return  void
     * @throws  \Hoa\Compiler\Exception
     */
    public function analyzeRules(array $rules)
    {
        if (empty($rules)) {
            throw new Compiler\Exception\Rule('No rules specified!', 0);
        }

        $this->_parsedRules = [];
        $this->_rules       = $rules;
        $lexer              = new Compiler\Llk\Lexer();

        foreach ($rules as $key => $value) {
            $this->_lexer = new Iterator\Lookahead($lexer->lexMe($value, static::$_ppLexemes));
            $this->_lexer->rewind();

            $this->_ruleName = $key;
            $nodeId          = null;

            if ('#' === $key[0]) {
                $nodeId = $key;
                $key    = \substr($key, 1);
            }

            $pNodeId = $nodeId;
            $rule    = $this->rule($pNodeId);

            if (null === $rule) {
                throw new Compiler\Exception(
                    'Error while parsing rule %s.',
                    1,
                    $key
                );
            }

            $zeRule = $this->_parsedRules[$rule];
            $zeRule->setName($key);
            $zeRule->setPPRepresentation($value);

            if (null !== $nodeId) {
                $zeRule->setDefaultId($nodeId);
            }

            unset($this->_parsedRules[$rule]);
            $this->_parsedRules[$key] = $zeRule;
        }

        return $this->_parsedRules;
    }

    /**
     * Implementation of “rule”.
     *
     * @return  mixed
     */
    protected function rule(&$pNodeId)
    {
        return $this->choice($pNodeId);
    }

    /**
     * Implementation of “choice”.
     *
     * @return  mixed
     */
    protected function choice(&$pNodeId)
    {
        $children = [];

        // concatenation() …
        $nNodeId = $pNodeId;
        $rule    = $this->concatenation($nNodeId);

        if (null === $rule) {
            return;
        }

        if (null !== $nNodeId) {
            $this->_parsedRules[$rule]->setNodeId($nNodeId);
        }

        $children[] = $rule;
        $others     = false;

        // … ( ::or:: concatenation() )*
        while ('or' === $this->_lexer->current()['token']) {
            $this->_lexer->next();
            $others  = true;
            $nNodeId = $pNodeId;
            $rule    = $this->concatenation($nNodeId);

            if (null === $rule) {
                return;
            }

            if (null !== $nNodeId) {
                $this->_parsedRules[$rule]->setNodeId($nNodeId);
            }

            $children[] = $rule;
        }

        $pNodeId = null;

        if (false === $others) {
            return $rule;
        }

        $name                      = $this->_transitionalRuleCounter++;
        $this->_parsedRules[$name] = new Choice($name, $children);

        return $name;
    }

    /**
     * Implementation of “concatenation”.
     *
     * @return  mixed
     */
    protected function concatenation(&$pNodeId)
    {
        $children = [];

        // repetition() …
        $rule = $this->repetition($pNodeId);

        if (null === $rule) {
            return;
        }

        $children[] = $rule;
        $others     = false;

        // … repetition()*
        while (null !== $r1 = $this->repetition($pNodeId)) {
            $children[] = $r1;
            $others     = true;
        }

        if (false === $others && null === $pNodeId) {
            return $rule;
        }

        $name                      = $this->_transitionalRuleCounter++;
        $this->_parsedRules[$name] = new Concatenation(
            $name,
            $children,
            null
        );

        return $name;
    }

    /**
     * Implementation of “repetition”.
     *
     * @return  mixed
     * @throws  \Hoa\Compiler\Exception
     */
    protected function repetition(&$pNodeId)
    {
        // simple() …
        $children = $this->simple($pNodeId);

        if (null === $children) {
            return;
        }

        // … quantifier()?
        switch ($this->_lexer->current()['token']) {
            case 'zero_or_one':
                $min = 0;
                $max = 1;
                $this->_lexer->next();

                break;

            case 'one_or_more':
                $min = 1;
                $max = -1;
                $this->_lexer->next();

                break;

            case 'zero_or_more':
                $min = 0;
                $max = -1;
                $this->_lexer->next();

                break;

            case 'n_to_m':
                $handle = \trim($this->_lexer->current()['value'], '{}');
                $nm     = \explode(',', $handle);
                $min    = (int)\trim($nm[0]);
                $max    = (int)\trim($nm[1]);
                $this->_lexer->next();

                break;

            case 'zero_to_m':
                $min = 0;
                $max = (int)\trim($this->_lexer->current()['value'], '{,}');
                $this->_lexer->next();

                break;

            case 'n_or_more':
                $min = (int)\trim($this->_lexer->current()['value'], '{,}');
                $max = -1;
                $this->_lexer->next();

                break;

            case 'exactly_n':
                $handle = \trim($this->_lexer->current()['value'], '{}');
                $min    = (int)$handle;
                $max    = $min;
                $this->_lexer->next();

                break;
        }

        // … <node>?
        if ('node' === $this->_lexer->current()['token']) {
            $pNodeId = $this->_lexer->current()['value'];
            $this->_lexer->next();
        }

        if (! isset($min)) {
            return $children;
        }

        if (-1 != $max && $max < $min) {
            throw new Compiler\Exception(
                'Upper bound %d must be greater or ' .
                'equal to lower bound %d in rule %s.',
                2,
                [$max, $min, $this->_ruleName]
            );
        }

        $name                      = $this->_transitionalRuleCounter++;
        $this->_parsedRules[$name] = new Repetition(
            $name,
            $min,
            $max,
            $children,
            null
        );

        return $name;
    }

    /**
     * Implementation of “simple”.
     *
     * @return  mixed
     * @throws  \Hoa\Compiler\Exception\Exception
     * @throws  \Hoa\Compiler\Exception\Rule
     */
    protected function simple(&$pNodeId)
    {
        if ($this->_lexer->current()['token'] === 'capturing_') {
            $this->_lexer->next();
            $rule = $this->choice($pNodeId);

            if ($rule === null) {
                return;
            }

            if ($this->_lexer->current()['token'] !== '_capturing') {
                return;
            }

            $this->_lexer->next();

            return $rule;
        }

        if ($this->_lexer->current()['token'] === 'skipped') {
            $tokenName = \trim($this->_lexer->current()['value'], ':');

            if (\substr($tokenName, -1) === ']') {
                $uId       = (int)\substr($tokenName, \strpos($tokenName, '[') + 1, -1);
                $tokenName = \substr($tokenName, 0, \strpos($tokenName, '['));
            } else {
                $uId = -1;
            }

            $exists = false;

            foreach ($this->_tokens as $namespace => $tokens) {
                foreach ($tokens as $token => $value) {
                    if (
                        $token === $tokenName ||
                        \strpos($token, $tokenName) === 0
                    ) {
                        $exists = true;

                        break 2;
                    }
                }
            }

            if (false == $exists) {
                throw new Exception(
                    'Token ::%s:: does not exist in rule %s.',
                    3,
                    [$tokenName, $this->_ruleName]
                );
            }

            $name                      = $this->_transitionalRuleCounter++;
            $this->_parsedRules[$name] = new Token(
                $name,
                $tokenName,
                null,
                $uId
            );
            $this->_lexer->next();

            return $name;
        }

        if ($this->_lexer->current()['token'] === 'kept') {
            $tokenName = \trim($this->_lexer->current()['value'], '<>');

            if (\substr($tokenName, -1) === ']') {
                $uId       = (int)\substr($tokenName, \strpos($tokenName, '[') + 1, -1);
                $tokenName = \substr($tokenName, 0, \strpos($tokenName, '['));
            } else {
                $uId = -1;
            }

            $exists = false;

            foreach ($this->_tokens as $namespace => $tokens) {
                foreach ($tokens as $token => $value) {
                    if (
                        $token === $tokenName ||
                        \substr($token, 0, (int)\strpos($token, ':')) === $tokenName
                    ) {
                        $exists = true;

                        break 2;
                    }
                }
            }

            if (false == $exists) {
                throw new Compiler\Exception(
                    'Token <%s> does not exist in rule %s.',
                    4,
                    [$tokenName, $this->_ruleName]
                );
            }

            $name                      = $this->_transitionalRuleCounter++;
            $token                     = new Token(
                $name,
                $tokenName,
                null,
                $uId,
                true
            );
            $this->_parsedRules[$name] = $token;
            $this->_lexer->next();

            return $name;
        }

        if ('named' === $this->_lexer->current()['token']) {
            $tokenName = \rtrim($this->_lexer->current()['value'], '()');

            if (false === \array_key_exists($tokenName, $this->_rules) &&
                false === \array_key_exists('#' . $tokenName, $this->_rules)) {
                throw new Compiler\Exception\Rule(
                    'Cannot call rule %s() in rule %s because it does not exist.',
                    5,
                    [$tokenName, $this->_ruleName]
                );
            }

            if (0 === $this->_lexer->key() &&
                'EOF' === $this->_lexer->getNext()['token']) {
                $name                      = $this->_transitionalRuleCounter++;
                $this->_parsedRules[$name] = new Concatenation(
                    $name,
                    [$tokenName],
                    null
                );
            } else {
                $name = $tokenName;
            }

            $this->_lexer->next();

            return $name;
        }
    }
}
